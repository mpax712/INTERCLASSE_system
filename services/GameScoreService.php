<?php

require_once __DIR__ . '/GameCompetition.php';

final class GameScoreException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

function game_forget_score_cache(int $matchId): void
{
    $file = __DIR__ . '/../storage/cache/public/score:' . $matchId . '.json';
    if (is_file($file)) @unlink($file);
}

function game_int_score(mixed $value, string $label, int $max = 999): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT);
    if ($number === false || $number < 0 || $number > $max) throw new GameScoreException($label . ' inválido.');
    return $number;
}

function game_score_battles(array $battles, int $bestOf): array
{
    if (count($battles) > $bestOf) throw new GameScoreException('A série excede o formato configurado.');
    $wins = ['home' => 0, 'away' => 0];
    $target = intdiv($bestOf, 2) + 1;
    foreach ($battles as $index => $battle) {
        if (max($wins) >= $target) throw new GameScoreException('A série já havia sido decidida.');
        if (!is_array($battle)) throw new GameScoreException('Batalha inválida.');
        $home = game_int_score($battle['home_crowns'] ?? null, 'Coroas', 3);
        $away = game_int_score($battle['away_crowns'] ?? null, 'Coroas', 3);
        $winner = $battle['winner'] ?? null;
        if (!in_array($winner, ['home', 'away'], true)) throw new GameScoreException('Indique o vencedor da batalha.');
        if (($home > $away && $winner !== 'home') || ($away > $home && $winner !== 'away')) throw new GameScoreException('O vencedor não corresponde às coroas.');
        if ($home === $away && empty($battle['tiebreak'])) throw new GameScoreException('Informe o desempate quando as coroas forem iguais.');
        $wins[$winner]++;
    }
    return $wins;
}

function game_apply_score_action(int $matchId, array $data, array $user): array
{
    if (($user['role'] ?? '') === 'admin') {
        throw new GameScoreException('Correções e cancelamentos administrativos exigem prévia de impacto.',428);
    }
    $requestId = (string) ($data['request_id'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/', $requestId)) throw new GameScoreException('Identificador da operação inválido.');
    $expectedVersion = filter_var($data['expected_version'] ?? null, FILTER_VALIDATE_INT);
    if ($expectedVersion === false || $expectedVersion < 0) throw new GameScoreException('Versão do placar inválida.');
    $action = (string) ($data['action'] ?? '');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Serialize score writes across this event before touching matches or class standings.
        $eventLock = $pdo->prepare('SELECT id FROM events WHERE id = (SELECT event_id FROM matches WHERE id = ?) FOR UPDATE');
        $eventLock->execute([$matchId]);
        if (!$eventLock->fetchColumn()) throw new GameScoreException('Partida não encontrada.', 404);
        $stmt = $pdo->prepare('SELECT m.*, e.status AS event_status FROM matches m JOIN events e ON e.id = m.event_id WHERE m.id = ? FOR UPDATE OF m');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) throw new GameScoreException('Partida não encontrada.', 404);
        if (!game_is_new_modality((string) $match['modality'])) throw new GameScoreException('Modalidade inválida.');
        if (!can_operate_match((int) $match['event_id'], (string) $match['modality'])) throw new GameScoreException('Sem permissão para esta partida.', 403);
        if ($match['event_status'] === 'rascunho' || $match['event_status'] === 'encerrado') throw new GameScoreException('O evento não permite alterações.', 403);
        $duplicate = $pdo->prepare('SELECT id FROM score_logs WHERE match_id = ? AND request_id = ?');
        $duplicate->execute([$matchId, $requestId]);
        if ($duplicate->fetchColumn()) {
            $pdo->commit();
            return ['duplicate' => true];
        }
        if ((int) $match['version'] !== $expectedVersion) throw new GameScoreException('Esta partida foi alterada por outra pessoa. Recarregue o placar.', 409);
        $old = $match;
        $score = decode_score_data($match['score_data'] ?? null, (string) $match['modality']);
        $rules = game_match_rules($match);
        $status = (string) $match['status'];
        $homeScore = (int) $match['home_score'];
        $awayScore = (int) $match['away_score'];
        $winner = $match['winner_player_id'] ? (int) $match['winner_player_id'] : null;
        $cancelReason = $match['cancel_reason'];
        $cancelledBy = $match['cancelled_by'];
        $isAdmin = ($user['role'] ?? '') === 'admin';
        if (in_array($status, ['encerrada', 'wo', 'cancelada'], true) && $action !== 'correct') throw new GameScoreException('Partida fechada. Use a correção administrativa.');
        if ($action === 'start') {
            if ($status !== 'agendada' || !$match['home_player_id'] || !$match['away_player_id']) throw new GameScoreException('Defina os dois participantes antes de iniciar.');
            $status = 'em_andamento';
        } elseif ($action === 'await_result') {
            if ($status !== 'em_andamento') throw new GameScoreException('A partida precisa estar em andamento.');
            $status = 'aguardando_resultado';
        } elseif ($action === 'resume') {
            if ($status !== 'aguardando_resultado') throw new GameScoreException('Esta partida não aguarda resultado.');
            $status = 'em_andamento';
        } elseif ($action === 'adjust') {
            if ($match['modality'] !== 'fifa' || $status !== 'em_andamento') throw new GameScoreException('Placar não pode ser alterado agora.');
            $side = $data['team'] ?? '';
            $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($side, ['home', 'away'], true) || !in_array($amount, [-1, 1], true)) throw new GameScoreException('Ajuste inválido.');
            if ($side === 'home') $homeScore = max(0, $homeScore + $amount); else $awayScore = max(0, $awayScore + $amount);
            $score['penalties'] = null;
        } elseif ($action === 'record_battle' || $action === 'remove_battle') {
            if ($match['modality'] !== 'clash_royale' || $status !== 'em_andamento') throw new GameScoreException('Série não pode ser alterada agora.');
            $battles = $score['battles'] ?? [];
            if ($action === 'remove_battle') {
                if (!$battles) throw new GameScoreException('Nenhuma batalha para remover.');
                array_pop($battles);
            } else {
                $wins = game_score_battles($battles, $rules['best_of']);
                if (max($wins) >= intdiv($rules['best_of'], 2) + 1) throw new GameScoreException('A série já foi decidida.');
                $battles[] = [
                    'home_crowns' => game_int_score($data['home_crowns'] ?? null, 'Coroas', 3),
                    'away_crowns' => game_int_score($data['away_crowns'] ?? null, 'Coroas', 3),
                    'winner' => $data['winner'] ?? null,
                    'tiebreak' => !empty($data['tiebreak']),
                ];
            }
            $wins = game_score_battles($battles, $rules['best_of']);
            $score['battles'] = $battles;
            $homeScore = $wins['home']; $awayScore = $wins['away'];
        } elseif ($action === 'finish') {
            if (!in_array($status, ['em_andamento', 'aguardando_resultado'], true)) throw new GameScoreException('Inicie a partida antes de finalizá-la.');
            if ($match['modality'] === 'clash_royale') {
                $wins = game_score_battles($score['battles'] ?? [], $rules['best_of']);
                $target = intdiv($rules['best_of'], 2) + 1;
                if (max($wins) !== $target) throw new GameScoreException('Registre as batalhas até decidir a série.');
                $winner = $wins['home'] > $wins['away'] ? (int) $match['home_player_id'] : (int) $match['away_player_id'];
            } elseif ($homeScore === $awayScore) {
                if ($match['stage'] === 'grupo') {
                    $winner = null;
                } else {
                    $homePen = game_int_score($data['home_penalties'] ?? null, 'Pênaltis', 30);
                    $awayPen = game_int_score($data['away_penalties'] ?? null, 'Pênaltis', 30);
                    if ($homePen === $awayPen) throw new GameScoreException('Pênaltis precisam definir um vencedor.');
                    $score['penalties'] = ['home' => $homePen, 'away' => $awayPen];
                    $score['decided_by'] = 'penalties';
                    $winner = $homePen > $awayPen ? (int) $match['home_player_id'] : (int) $match['away_player_id'];
                }
            } else {
                $score['decided_by'] = !empty($data['extra_time']) ? 'extra_time' : 'regular';
                $winner = $homeScore > $awayScore ? (int) $match['home_player_id'] : (int) $match['away_player_id'];
            }
            $status = 'encerrada';
        } elseif ($action === 'wo') {
            if ($status !== 'agendada') throw new GameScoreException('W.O. só pode ser registrado antes do início.');
            $loser = $data['team'] ?? '';
            if (!in_array($loser, ['home','away'], true) || !$match['home_player_id'] || !$match['away_player_id']) throw new GameScoreException('Informe o participante ausente.');
            $winningSide = $loser === 'home' ? 'away' : 'home';
            $winner = (int) $match[$winningSide . '_player_id'];
            $award = $match['modality'] === 'fifa' ? $rules['wo_goals'] : intdiv($rules['best_of'], 2) + 1;
            $homeScore = $winningSide === 'home' ? $award : 0;
            $awayScore = $winningSide === 'away' ? $award : 0;
            $score['wo'] = true;
            $status = 'wo';
        } elseif ($action === 'cancel') {
            if (!$isAdmin) throw new GameScoreException('Somente administrador pode cancelar.', 403);
            $reason = trim((string) ($data['reason'] ?? ''));
            if (strlen($reason) < 3 || strlen($reason) > 240) throw new GameScoreException('Informe o motivo do cancelamento.');
            $children = $pdo->prepare('SELECT id FROM matches WHERE home_source_match_id = ? OR away_source_match_id = ? FOR UPDATE');
            $children->execute([$matchId, $matchId]);
            $affected = $children->fetchAll(PDO::FETCH_COLUMN);
            if ($affected) throw new GameScoreException('Cancelamento bloqueado: a partida alimenta o confronto #' . implode(', #', $affected) . '.', 409);
            $status = 'cancelada'; $cancelReason = $reason; $cancelledBy = (int) $user['id']; $winner = null;
        } elseif ($action === 'correct') {
            if (!$isAdmin || !in_array($status, ['encerrada','wo'], true)) throw new GameScoreException('Correção administrativa indisponível.', 403);
            $reason = trim((string) ($data['reason'] ?? ''));
            if (strlen($reason) < 3 || strlen($reason) > 240) throw new GameScoreException('Informe o motivo da correção.');
            if ($match['stage'] === 'grupo') {
                $bracket = $pdo->prepare("SELECT id FROM matches WHERE event_id = ? AND modality = ? AND stage <> 'grupo' ORDER BY id LIMIT 5");
                $bracket->execute([$match['event_id'], $match['modality']]);
                $affected = $bracket->fetchAll(PDO::FETCH_COLUMN);
                if ($affected) throw new GameScoreException('Correção bloqueada: o mata-mata já foi gerado. Confrontos afetados: #' . implode(', #', $affected) . '.', 409);
            }
            $downstream = $pdo->prepare('SELECT id, status FROM matches WHERE home_source_match_id = ? OR away_source_match_id = ? FOR UPDATE');
            $downstream->execute([$matchId, $matchId]);
            foreach ($downstream->fetchAll() as $next) if ($next['status'] !== 'agendada') throw new GameScoreException('Partida posterior já iniciada. Revise o impacto antes de corrigir.', 409);
            if ($match['modality'] === 'fifa') {
                $homeScore = game_int_score($data['home_score'] ?? null, 'Placar');
                $awayScore = game_int_score($data['away_score'] ?? null, 'Placar');
                if ($homeScore === $awayScore && $match['stage'] !== 'grupo') {
                    $homePen = game_int_score($data['home_penalties'] ?? null, 'Pênaltis', 30);
                    $awayPen = game_int_score($data['away_penalties'] ?? null, 'Pênaltis', 30);
                    if ($homePen === $awayPen) throw new GameScoreException('Pênaltis precisam definir um vencedor.');
                    $score['penalties'] = ['home' => $homePen, 'away' => $awayPen];
                    $winner = $homePen > $awayPen ? (int) $match['home_player_id'] : (int) $match['away_player_id'];
                } else {
                    $score['penalties'] = null;
                    $winner = $homeScore === $awayScore ? null : ($homeScore > $awayScore ? (int) $match['home_player_id'] : (int) $match['away_player_id']);
                }
            } else {
                $battles = $data['battles'] ?? null;
                if (!is_array($battles)) throw new GameScoreException('Informe as batalhas corrigidas.');
                $wins = game_score_battles($battles, $rules['best_of']);
                if (max($wins) !== intdiv($rules['best_of'], 2) + 1) throw new GameScoreException('A série corrigida precisa ter vencedor.');
                $score['battles'] = $battles;
                $homeScore = $wins['home']; $awayScore = $wins['away'];
                $winner = $homeScore > $awayScore ? (int) $match['home_player_id'] : (int) $match['away_player_id'];
            }
            $status = 'encerrada';
            $score['correction_reason'] = $reason;
        } else {
            throw new GameScoreException('Ação inválida.');
        }
        $winnerTeam = $winner ? ($winner === (int) $match['home_player_id'] ? $match['home_team_id'] : $match['away_team_id']) : null;
        $update = $pdo->prepare('UPDATE matches SET home_score = ?, away_score = ?, score_data = ?, status = ?, winner_player_id = ?, winner_team_id = ?, cancel_reason = ?, cancelled_by = ?, version = version + 1 WHERE id = ?');
        $update->execute([$homeScore, $awayScore, json_encode($score, JSON_UNESCAPED_UNICODE), $status, $winner, $winnerTeam, $cancelReason, $cancelledBy, $matchId]);
        $log = $pdo->prepare('INSERT INTO score_logs (match_id, user_id, action, description, old_home_score, old_away_score, new_home_score, new_away_score, old_status, new_status, old_score_data, new_score_data, request_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $log->execute([$matchId, $user['id'], $action, $action === 'correct' ? $reason : ($action === 'cancel' ? $cancelReason : 'Atualização de ' . modality_label($match['modality'])), $old['home_score'], $old['away_score'], $homeScore, $awayScore, $old['status'], $status, $old['score_data'], json_encode($score, JSON_UNESCAPED_UNICODE), $requestId]);
        if (in_array($status, ['encerrada','wo'], true) && $match['stage'] !== 'grupo') {
            $match['winner_player_id'] = $winner;
            game_advance_winner($match);
        }
        if (in_array($action, ['finish','wo','correct','cancel'], true)) game_recalculate_class_points((int) $match['event_id'], (string) $match['modality']);
        $pdo->commit();
        game_forget_score_cache($matchId);
        public_cache_forget_all();
        return ['duplicate' => false];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
