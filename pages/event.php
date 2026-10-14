<?php
ensure_interclasse_schema_updates();

$eventId = (int) ($_GET['id'] ?? 0);
$publicCacheKey = 'event:' . $eventId . ':' . md5(json_encode($_GET, JSON_UNESCAPED_UNICODE));
if (public_page_cache_serve($publicCacheKey)) {
    return;
}
$publicCacheEnabled = public_page_cache_start($publicCacheKey);

$eventStmt = db()->prepare('SELECT e.*, u.name AS professor_name FROM events e JOIN users u ON u.id = e.created_by WHERE e.id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();

if (!$event) {
    http_response_code(404);
    public_page_cache_abort($publicCacheEnabled);
    echo '<div class="alert alert-warning">Evento não encontrado.</div>';
    return;
}

function uses_bracket_table(string $modality): bool
{
    return in_array($modality, ['xadrez', 'quiz', 'cubo_magico', 'fifa', 'clash_royale'], true);
}

function bracket_uses_player_names(string $modality): bool
{
    return is_individual_modality($modality);
}

function bracket_label_for_team(?int $teamId, string $modality, array $playersByTeam, array $teamNames): string
{
    if (!$teamId) {
        return 'A definir';
    }

    if ($modality === 'quiz') {
        return $teamNames[$teamId] ?? 'A definir';
    }

    $players = $playersByTeam[$teamId] ?? [];
    if (!$players) {
        return $teamNames[$teamId] ?? 'A definir';
    }

    return implode(' / ', array_column($players, 'name'));
}

function bracket_label_for_match_side(array $match, string $side, string $modality, array $playersById, array $playersByTeam, array $teamNames): string
{
    $data = decode_score_data($match['score_data'] ?? null, $modality);
    $playerKey = $side === 'home' ? 'bracket_home_player_id' : 'bracket_away_player_id';
    $playerId = (int) ($match[$side . '_player_id'] ?? $data[$playerKey] ?? 0);

    if ($playerId && isset($playersById[$playerId])) {
        $player = $playersById[$playerId];
        $className = $player['class_name'] ?? '';

        return $className ? $player['name'] . ' · ' . $className : $player['name'];
    }

    $teamId = (int) ($side === 'home' ? ($match['home_team_id'] ?? 0) : ($match['away_team_id'] ?? 0));

    return bracket_label_for_team($teamId, $modality, $playersByTeam, $teamNames);
}

function collect_bracket_slots(int $eventId, string $modality): array
{
    if ($modality === 'quiz' || !bracket_uses_player_names($modality)) {
        $teamsStmt = db()->prepare('SELECT id AS team_id, name AS class_name, name AS player_name FROM teams WHERE event_id = ? AND modality = ? ORDER BY name');
        $teamsStmt->execute([$eventId, $modality]);

        return array_map(static function (array $team): array {
            return [
                'player_id' => null,
                'team_id' => (int) $team['team_id'],
                'player_name' => $team['player_name'],
                'class_name' => $team['class_name'],
            ];
        }, $teamsStmt->fetchAll());
    }

    $playersStmt = db()->prepare(
        'SELECT p.id AS player_id, p.name AS player_name, p.team_id, t.class_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?
         ORDER BY t.name, p.name'
    );
    $playersStmt->execute([$eventId, $modality]);
    $playersByTeam = [];

    foreach ($playersStmt->fetchAll() as $player) {
        $playersByTeam[(int) $player['team_id']][] = $player;
    }

    $slots = [];
    foreach ($playersByTeam as $teamId => $teamPlayers) {
        foreach ($teamPlayers as $player) {
            $slots[] = [
                'player_id' => (int) $player['player_id'],
                'team_id' => $teamId,
                'player_name' => $player['player_name'],
                'class_name' => $player['class_name'] ?? '',
            ];
        }
    }

    return $slots;
}

function pair_bracket_slots(array $slots): array
{
    $slotCount = count($slots);
    if ($slotCount < 2 || $slotCount > 32) {
        return [];
    }

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $remaining = $slots;
        shuffle($remaining);
        $pairs = [];
        $byes = [];

        if (count($remaining) % 2 === 1) {
            $byes[] = array_pop($remaining);
        }

        while ($remaining) {
            $slot = array_shift($remaining);
            $opponentIndex = null;

            foreach ($remaining as $index => $opponent) {
                if ($slot['team_id'] !== $opponent['team_id']) {
                    $opponentIndex = $index;
                    break;
                }
            }

            if ($opponentIndex === null) {
                $opponentIndex = array_key_first($remaining);
            }

            if ($opponentIndex === null) {
                break;
            }

            $opponent = $remaining[$opponentIndex];
            unset($remaining[$opponentIndex]);
            $remaining = array_values($remaining);
            $pairs[] = [$slot, $opponent];
        }

        if (count($pairs) * 2 + count($byes) === $slotCount) {
            return [
                'pairs' => $pairs,
                'byes' => $byes,
            ];
        }
    }

    return [];
}

function bracket_winner_side(array $match): ?string
{
    if (!in_array($match['status'], ['encerrada', 'wo'], true)) {
        return null;
    }

    $winnerPlayerId = (int) ($match['winner_player_id'] ?? 0);
    if ($winnerPlayerId > 0) {
        if ($winnerPlayerId === (int) ($match['home_player_id'] ?? 0)) return 'home';
        if ($winnerPlayerId === (int) ($match['away_player_id'] ?? 0)) return 'away';
    }
    $winnerId = (int) ($match['winner_team_id'] ?? 0);
    if ($winnerId === (int) ($match['home_team_id'] ?? 0)) {
        return 'home';
    }
    if ($winnerId === (int) ($match['away_team_id'] ?? 0)) {
        return 'away';
    }

    return null;
}

function build_bracket_rounds(array $matches, string $modality, array $playersById, array $playersByTeam, array $teamNames): array
{
    $rounds = [];

    $stages = ['dezesseis_avos', 'oitavas', 'quartas', 'semifinal', 'final'];
    foreach ($matches as $match) {
        if (($match['stage'] ?? '') !== 'preliminar') {
            continue;
        }

        $data = decode_score_data($match['score_data'] ?? null, $modality);
        $forStage = (string) ($data['preliminary_for_stage'] ?? '');
        $insertIndex = array_search($forStage, $stages, true);
        if ($insertIndex === false) {
            $insertIndex = count($stages);
        }
        array_splice($stages, $insertIndex, 0, ['preliminar']);
        break;
    }

    foreach ($stages as $stage) {
        $stageMatches = array_values(array_filter($matches, static fn(array $match): bool => ($match['stage'] ?? '') === $stage));
        if (!$stageMatches) {
            continue;
        }

        usort($stageMatches, static function (array $a, array $b): int {
            return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0))
                ?: ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        $formattedMatches = [];
        foreach ($stageMatches as $match) {
            $winnerSide = bracket_winner_side($match);
            $formattedMatches[] = [
                'id' => (int) $match['id'],
                'home' => bracket_label_for_match_side($match, 'home', $modality, $playersById, $playersByTeam, $teamNames),
                'away' => bracket_label_for_match_side($match, 'away', $modality, $playersById, $playersByTeam, $teamNames),
                'winner_side' => $winnerSide,
                'status' => $match['status'] ?? 'agendada',
                'score' => match_score_text($match),
            ];
        }

        $rounds[] = [
            'stage' => $stage,
            'label' => $stage === 'preliminar' ? 'Preliminar' : stage_label($stage),
            'matches' => $formattedMatches,
        ];
    }

    return $rounds;
}

function generate_bracket_matches(int $eventId, string $modality, ?string $place = null): int
{
    $existingStmt = db()->prepare(
        'SELECT COUNT(*) FROM matches
         WHERE event_id = ? AND modality = ? AND stage IN ("preliminar", "dezesseis_avos", "oitavas", "quartas", "semifinal", "final")'
    );
    $existingStmt->execute([$eventId, $modality]);
    if ((int) $existingStmt->fetchColumn() > 0) {
        return 0;
    }

    $slots = collect_bracket_slots($eventId, $modality);
    if (count($slots) < 2 || count($slots) > 32) {
        return 0;
    }

    $bracket = pair_bracket_slots($slots);
    if (!$bracket) {
        return 0;
    }

    $slotCount = count($slots);
    $targetSize = 2;
    while ($targetSize < $slotCount) {
        $targetSize *= 2;
    }

    $stage = 'final';
    if ($targetSize === 32) {
        $stage = 'dezesseis_avos';
    } elseif ($targetSize === 16) {
        $stage = 'oitavas';
    } elseif ($targetSize === 8) {
        $stage = 'quartas';
    } elseif ($targetSize === 4) {
        $stage = 'semifinal';
    }
    $created = 0;

    $preliminaryForStage = $bracket['byes']
        ? knockout_stage_for_participant_count((int) (count($bracket['pairs'])))
        : null;
    foreach ($bracket['byes'] as $bye) {
        if ($preliminaryForStage === null) {
            continue;
        }

        create_preliminary_shell(
            $eventId,
            $modality,
            'masculino',
            $stage,
            $preliminaryForStage,
            (int) $bye['team_id'],
            $bye['player_id'] ?: null,
            $place
        );
        $created++;
    }

    foreach ($bracket['pairs'] as $pair) {
        [$home, $away] = $pair;
        $insert = db()->prepare(
            'INSERT INTO matches (event_id, modality, gender, stage, home_team_id, away_team_id, score_data, place, sort_order)
             VALUES (?, ?, "masculino", ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $eventId,
            $modality,
            $stage,
            $home['team_id'],
            $away['team_id'],
            json_encode(
                bracket_score_data_with_players($modality, $home['player_id'] ?: null, $away['player_id'] ?: null),
                JSON_UNESCAPED_UNICODE
            ),
            $place,
            500 + $created,
        ]);
        $created++;
    }

    return $created;
}

$canManage = can_manage_event_structure($eventId);
$canModifyTables = $canManage;

if ($_SERVER['REQUEST_METHOD']==='POST' && has_role(['admin'])) {
    http_response_code(428);
    echo '<div class="alert alert-warning">Use a montagem administrativa para analisar e confirmar a geração ou alteração de partidas.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$canModifyTables) {
    require_login();
    http_response_code(403);
    echo '<div class="alert alert-danger">Você não tem permissão para alterar este evento.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canModifyTables) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $modality = $_POST['modality'] ?? '';

    if ($action === 'generate_group' && in_array($modality, allowed_modalities(), true)) {
        if ($modality === 'fifa') {
            require_once __DIR__ . '/../services/GameCompetition.php';
            try { $created = game_generate_groups($eventId, $modality); flash($created . ' partida(s) de FIFA gerada(s).'); }
            catch (Throwable $error) { flash($error->getMessage(), 'warning'); }
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
        }
        $gender = $_POST['gender'] ?? 'masculino';
        $overLimitTeams = over_limit_teams($eventId, $modality, $gender);
        if ($overLimitTeams) {
            flash('Regularize os times em vermelho antes de gerar jogos: há equipe acima do limite de jogadores.', 'warning');
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality . '&gender=' . $gender);
        }
        $created = generate_group_matches($eventId, $modality, null, $event['place'], $gender);
        flash($created . ' partida(s) de grupo gerada(s).');
        redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
    }

    if ($action === 'generate_round_robin' && in_array($modality, allowed_modalities(), true)) {
        $gender = $_POST['gender'] ?? 'masculino';
        $overLimitTeams = over_limit_teams($eventId, $modality, $gender);
        if ($overLimitTeams) {
            flash('Regularize os times em vermelho antes de gerar todos contra todos: ha equipe acima do limite de jogadores.', 'warning');
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality . '&gender=' . $gender);
        }
        $created = generate_round_robin_matches($eventId, $modality, null, $event['place'], $gender);
        flash($created . ' partida(s) todos contra todos gerada(s).', $created > 0 ? 'success' : 'warning');
        redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
    }

    if ($action === 'generate_knockout' && in_array($modality, allowed_modalities(), true)) {
        if ($modality === 'fifa') {
            require_once __DIR__ . '/../services/GameCompetition.php';
            try { $created = game_generate_bracket($eventId, $modality); flash($created . ' confronto(s) eliminatório(s) gerado(s).'); }
            catch (Throwable $error) { flash($error->getMessage(), 'warning'); }
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
        }
        $gender = $_POST['gender'] ?? 'masculino';
        $overLimitTeams = over_limit_teams($eventId, $modality, $gender);
        if ($overLimitTeams) {
            flash('Regularize os times em vermelho antes de gerar eliminatórias: há equipe acima do limite de jogadores.', 'warning');
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality . '&gender=' . $gender);
        }
        $created = generate_knockout_matches($eventId, $modality, $gender);
        $message = $created > 0 ? $created . ' partida(s) eliminatória(s) gerada(s).' : 'Nenhuma nova eliminatória foi gerada.';
        if ($created === 0 && !group_stage_is_complete($eventId, $modality, $gender)) {
            $message = 'Finalize todos os jogos de grupo antes de gerar eliminatórias.';
        }
        flash($message, $created > 0 ? 'success' : 'warning');
        redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
    }

    if ($action === 'generate_repechage' && in_array($modality, allowed_modalities(), true)) {
        if (game_is_new_modality($modality)) {
            flash('Esta modalidade não possui repescagem.', 'warning');
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
        }
        $gender = $_POST['gender'] ?? 'masculino';
        $overLimitTeams = over_limit_teams($eventId, $modality, $gender);
        if ($overLimitTeams) {
            flash('Regularize os times em vermelho antes de gerar repescagem: há equipe acima do limite de jogadores.', 'warning');
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality . '&gender=' . $gender);
        }
        $created = generate_repechage_matches($eventId, $modality, $gender);
        $message = $created > 0 ? $created . ' partida(s) de repescagem gerada(s).' : 'Nenhuma nova partida de repescagem foi gerada.';
        if ($created === 0 && !group_stage_is_complete($eventId, $modality, $gender)) {
            $message = 'Finalize todos os jogos de grupo antes de gerar repescagem.';
        }
        flash($message, $created > 0 ? 'success' : 'warning');
        redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
    }

    if ($action === 'generate_bracket' && bracket_type_for_event_modality($eventId, $modality) === 'simple') {
        if (game_is_new_modality($modality)) {
            require_once __DIR__ . '/../services/GameCompetition.php';
            try { $created = game_generate_bracket($eventId, $modality); flash($created . ' confronto(s) gerado(s).'); }
            catch (Throwable $error) { flash($error->getMessage(), 'warning'); }
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
        }
        $overLimitTeams = over_limit_teams($eventId, $modality, 'masculino');
        if ($overLimitTeams) {
            flash('Regularize os times em vermelho antes de gerar o chaveamento: há equipe acima do limite de jogadores.', 'warning');
            redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
        }
        $created = generate_bracket_matches($eventId, $modality, $event['place'] ?? null);
        if ($created > 0) {
            flash($created . ' confronto(s) sorteado(s) no chaveamento.');
        } else {
            $teamsCountStmt = db()->prepare('SELECT COUNT(*) FROM teams WHERE event_id = ? AND modality = ?');
            $teamsCountStmt->execute([$eventId, $modality]);
            $teamsCount = (int) $teamsCountStmt->fetchColumn();
            $slots = collect_bracket_slots($eventId, $modality);
            if (count($slots) < 2) {
                $hint = $modality === 'quiz'
                    ? 'Cadastre pelo menos duas turmas para o chaveamento.'
                    : 'Cadastre pelo menos dois alunos antes de gerar o chaveamento.';
                flash($hint, 'warning');
            } elseif (count($slots) > 32) {
                $hint = $modality === 'quiz'
                    ? 'O chaveamento suporta até 32 turmas.'
                    : 'O chaveamento suporta até 32 alunos inscritos.';
                flash($hint, 'warning');
            } else {
                flash('O chaveamento já foi gerado ou não foi possível sortear os confrontos.', 'warning');
            }
        }
        redirect('index.php?page=event&id=' . $eventId . '&modality=' . $modality);
    }

    if ($action === 'update_match') {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $place = trim($_POST['place'] ?? '');
        $stmt = db()->prepare('UPDATE matches SET place = ? WHERE id = ? AND event_id = ?');
        $stmt->execute([$place, $matchId, $eventId]);
        flash('Partida atualizada.');
        redirect('index.php?page=event&id=' . $eventId);
    }
}

$modalitiesStmt = db()->prepare('SELECT * FROM event_modalities WHERE event_id = ? ORDER BY modality');
$modalitiesStmt->execute([$eventId]);
$modalities = $modalitiesStmt->fetchAll();
$selectedModality = $_GET['modality'] ?? ($modalities[0]['modality'] ?? 'futsal');
$isMixedModality = is_mixed_modality($selectedModality);
$selectedBracketType = bracket_type_for_event_modality($eventId, $selectedModality);
$usesGrouping = $selectedBracketType === 'groups';
$usesRoundRobin = $selectedBracketType === 'round_robin';
$canOperateSelectedModality = can_operate_modality($eventId, $selectedModality);
$gender = $isMixedModality ? 'masculino' : ($_GET['gender'] ?? 'masculino');

if (!$isMixedModality && !in_array($gender, ['masculino', 'feminino'], true)) {
    $gender = 'masculino';
}

$selectedPlayerLimit = player_limit_for_event_modality($eventId, $selectedModality);
$teamSql = 'SELECT t.*, t.representative_name AS manual_representative_name, u.name AS representative_user_name, (SELECT COUNT(*) FROM players p WHERE p.team_id=t.id) AS player_count
    FROM teams t
    LEFT JOIN users u ON u.id = t.representative_id
    WHERE t.event_id = ? AND t.modality = ?' . gender_filter_sql($selectedModality) . '
    ORDER BY t.group_name, t.name';
$teamParams = array_merge([$eventId, $selectedModality], gender_filter_params($selectedModality, $gender));
$teamsStmt = db()->prepare($teamSql);
$teamsStmt->execute($teamParams);
$teams = $teamsStmt->fetchAll();

$isBracketModality = $selectedBracketType === 'simple';
$modalitySummaries = [];
$teamSummaryStmt = db()->prepare(
    'SELECT modality, COUNT(*) AS team_count
     FROM teams
     WHERE event_id = ? AND (modality IN ("cubo_magico", "xadrez", "quiz", "fifa", "clash_royale") OR gender = ?)
     GROUP BY modality'
);
$teamSummaryStmt->execute([$eventId, $gender]);
$teamSummaryByModality = [];
foreach ($teamSummaryStmt->fetchAll() as $row) {
    $teamSummaryByModality[$row['modality']] = (int) $row['team_count'];
}

$matchSummaryStmt = db()->prepare(
    'SELECT modality,
        COUNT(*) AS total_matches,
        SUM(CASE WHEN status = "agendada" THEN 1 ELSE 0 END) AS scheduled_matches,
        SUM(CASE WHEN status = "em_andamento" THEN 1 ELSE 0 END) AS running_matches,
        SUM(CASE WHEN status IN ("encerrada", "wo") THEN 1 ELSE 0 END) AS finished_matches
     FROM matches
     WHERE event_id = ? AND (modality IN ("cubo_magico", "xadrez", "quiz", "fifa", "clash_royale") OR gender = ?)
     GROUP BY modality'
);
$matchSummaryStmt->execute([$eventId, $gender]);
$matchSummaryByModality = [];
foreach ($matchSummaryStmt->fetchAll() as $row) {
    $matchSummaryByModality[$row['modality']] = $row;
}

$gamePlayerSummaryStmt = db()->prepare('SELECT t.modality, COUNT(p.id) AS player_count FROM teams t JOIN players p ON p.team_id = t.id WHERE t.event_id = ? AND t.modality IN ("fifa", "clash_royale") GROUP BY t.modality');
$gamePlayerSummaryStmt->execute([$eventId]);
$gamePlayerSummary = [];
foreach ($gamePlayerSummaryStmt->fetchAll() as $row) $gamePlayerSummary[$row['modality']] = (int) $row['player_count'];

foreach ($modalities as $eventModality) {
    $modalityKey = $eventModality['modality'];
    $matchSummary = $matchSummaryByModality[$modalityKey] ?? [];

    $modalitySummaries[$modalityKey] = [
        'teams' => game_is_new_modality($modalityKey) ? (int) ($gamePlayerSummary[$modalityKey] ?? 0) : (int) ($teamSummaryByModality[$modalityKey] ?? 0),
        'total_matches' => (int) ($matchSummary['total_matches'] ?? 0),
        'scheduled_matches' => (int) ($matchSummary['scheduled_matches'] ?? 0),
        'running_matches' => (int) ($matchSummary['running_matches'] ?? 0),
        'finished_matches' => (int) ($matchSummary['finished_matches'] ?? 0),
    ];
}

$selectedSummary = $modalitySummaries[$selectedModality] ?? [
    'teams' => count($teams),
    'total_matches' => 0,
    'scheduled_matches' => 0,
    'running_matches' => 0,
    'finished_matches' => 0,
];
$nextStepTitle = 'Tudo pronto';
$nextStepText = 'Acompanhe partidas, classificação e súmulas desta modalidade.';
$nextStepAction = null;

if ($event['status'] === 'encerrado') {
    $nextStepTitle = 'Evento encerrado';
    $nextStepText = has_role(['admin'])
        ? 'As partidas não podem mais ser geradas. Use as tabelas e exportações para consultar os resultados.'
        : 'As partidas não podem mais ser geradas. Use as tabelas para consultar os resultados.';
} elseif ($selectedSummary['teams'] < 2) {
    $nextStepTitle = 'Cadastre os times';
    $nextStepText = 'Esta modalidade precisa de pelo menos dois times ou participantes para gerar confrontos.';
    $nextStepAction = ['label' => 'Abrir times', 'href' => 'index.php?page=teams&event_id=' . $eventId . '&modality=' . urlencode($selectedModality)];
} elseif ($selectedSummary['total_matches'] === 0) {
    $nextStepTitle = $isBracketModality ? 'Gere o chaveamento' : ($usesRoundRobin ? 'Gere todos contra todos' : 'Gere os grupos');
    $nextStepText = $isBracketModality
        ? 'Os participantes já existem. Sorteie o chaveamento para criar os confrontos.'
        : 'Os times já existem. Gere a fase de grupos para abrir a agenda de partidas.';
} elseif ($selectedSummary['running_matches'] > 0) {
    $nextStepTitle = 'Finalize partidas em andamento';
    $nextStepText = 'Existe partida aberta no placar. Encerre para atualizar classificação e próximas fases.';
} elseif ($selectedSummary['scheduled_matches'] > 0) {
    $nextStepTitle = 'Controle os próximos placares';
    $nextStepText = 'Há partidas agendadas. Abra o placar quando o jogo começar.';
} elseif (!$isBracketModality && !$usesRoundRobin && (game_is_new_modality($selectedModality) ? $selectedSummary['scheduled_matches'] === 0 && $selectedSummary['running_matches'] === 0 : group_stage_is_complete($eventId, $selectedModality, $gender))) {
    $nextStepTitle = 'Gere eliminatórias';
    $nextStepText = game_is_new_modality($selectedModality) ? 'A fase de grupos terminou. Gere o mata-mata.' : 'A fase de grupos está concluída. Gere repescagem, se houver, ou avance para as eliminatórias.';
}

$playersByTeam = [];

if ($isBracketModality || game_is_new_modality($selectedModality)) {
    $playersStmt = db()->prepare(
        'SELECT p.id, p.team_id, p.name, t.class_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?
         ORDER BY p.name'
    );
    $playersStmt->execute([$eventId, $selectedModality]);
    foreach ($playersStmt->fetchAll() as $player) {
        $playersByTeam[(int) $player['team_id']][] = $player;
    }
}
?>
<div class="page-title event-page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($event['name']) ?></h1>
        <p class="text-muted mb-2">Responsável: <?= e($event['place'] ?: 'Local a definir') ?></p>
        <div class="event-page-status-wrap">
            <span class="event-page-status-label">Status</span>
            <span class="<?= e(status_pill_class($event['status'])) ?> event-page-status-pill"><?= e(event_status_label($event['status'])) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap event-page-actions">
        <a class="btn btn-outline-secondary" href="index.php?page=standings&event_id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>">Tabela</a>
        <?php if ($canManage): ?>
            <?php if (has_role(['admin'])): ?>
                <a class="btn btn-outline-primary" href="index.php?page=match_manager&event_id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>&gender=<?= e($gender) ?>">Montagem</a>
            <?php endif; ?>
            <a class="btn btn-primary" href="index.php?page=teams&event_id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>"><?= game_is_new_modality($selectedModality) ? 'Inscrições' : 'Times' ?></a>
        <?php endif; ?>
        <?php if (has_role(['admin'])): ?>
            <button class="btn btn-outline-success export-modality-btn" data-export-type="modality_general" data-event-id="<?= $eventId ?>" data-modality="<?= e($selectedModality) ?>" data-gender="<?= e($gender) ?>">Súmula geral da modalidade</button>
            <button class="btn btn-outline-dark export-general-event-btn" data-event-id="<?= $eventId ?>">Geral do evento</button>
        <?php endif; ?>
    </div>
</div>

<nav class="section-nav mb-3" aria-label="Seções do evento">
    <a href="#resumo-evento">Resumo</a>
    <a href="#modalidades-evento">Modalidades</a>
    <a href="#times-evento"><?= game_is_new_modality($selectedModality) ? 'Participantes' : 'Times' ?></a>
    <a href="#partidas-evento">Partidas</a>
</nav>

<div id="resumo-evento" class="event-summary-grid mb-3">
    <?php foreach ($modalities as $modality): ?>
        <?php
        $summary = $modalitySummaries[$modality['modality']] ?? ['teams' => 0, 'total_matches' => 0, 'scheduled_matches' => 0, 'running_matches' => 0, 'finished_matches' => 0];
        $summaryBracketType = bracket_type_for_event_modality($eventId, $modality['modality']);
        $summaryLabel = game_is_new_modality($modality['modality']) ? 'alunos' : ($summaryBracketType === 'groups' || $summaryBracketType === 'round_robin' ? 'times' : ($modality['modality'] === 'quiz' ? 'turmas' : 'inscrições'));
        ?>
        <a class="event-summary-tile <?= $selectedModality === $modality['modality'] ? 'is-active' : '' ?>" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($modality['modality']) ?>&gender=<?= e($gender) ?>">
            <span><?= e(modality_label($modality['modality'])) ?></span>
            <strong><?= (int) $summary['teams'] ?> <?= e($summaryLabel) ?></strong>
            <small><?= (int) $summary['scheduled_matches'] ?> pend. · <?= (int) $summary['running_matches'] ?> ao vivo · <?= (int) $summary['finished_matches'] ?> fim</small>
        </a>
    <?php endforeach; ?>
</div>

<ul id="modalidades-evento" class="nav nav-pills mb-3 event-page-modality-tabs">
    <?php foreach ($modalities as $modality): ?>
        <li class="nav-item">
            <a class="nav-link <?= $selectedModality === $modality['modality'] ? 'active' : '' ?>" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($modality['modality']) ?>&gender=<?= e($gender) ?>">
                <?= e(modality_label($modality['modality'])) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-2 mb-3">
    <div class="col-md-6">
        <?php if (!$isMixedModality): ?>
        <div class="btn-group w-100" role="group">
            <a class="btn <?= $gender === 'masculino' ? 'btn-primary' : 'btn-outline-primary' ?>" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>&gender=masculino">Masculino</a>
            <a class="btn <?= $gender === 'feminino' ? 'btn-primary' : 'btn-outline-primary' ?>" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>&gender=feminino">Feminino</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
    <div class="event-next-step d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <div>
            <span>Próximo passo</span>
            <strong><?= e($nextStepTitle) ?></strong>
            <div><?= e($nextStepText) ?></div>
        </div>
        <?php if ($nextStepAction): ?>
            <a class="btn btn-sm btn-primary" href="<?= e($nextStepAction['href']) ?>"><?= e($nextStepAction['label']) ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card shadow-sm mb-3 event-generation-card">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span>Gerar fases</span>
            <span class="small text-muted">Use quando as inscrições ou fases anteriores estiverem prontas.</span>
        </div>
        <div class="card-body">
            <?php if (has_role(['admin'])): ?>
                <a class="btn btn-primary" href="index.php?page=match_manager&event_id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>&gender=<?= e($gender) ?>">Gerar, reorganizar ou corrigir confrontos</a>
                <p class="text-muted mt-2 mb-0">A prévia informa quais partidas e resultados serão mantidos, atualizados ou arquivados.</p>
            <?php elseif ($event['status'] === 'encerrado'): ?>
                <div class="alert alert-warning mb-0">Evento encerrado. Não é possível gerar novas partidas ou fases.</div>
            <?php else: ?>
                <div class="row g-2 align-items-end event-actions-grid">
                    <?php if ($isBracketModality): ?>
                        <div class="col-lg-5 event-actions-col">
                            <form method="post" class="row g-2 event-actions-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="generate_bracket">
                                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                <div class="col-12">
                                    <button class="btn btn-primary w-100" onclick="return confirmAction('Sortear e gerar o chaveamento eliminatório?')">Gerar chaveamento</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-lg-7 text-muted small event-actions-hint">
                            <?php if ($selectedModality === 'quiz'): ?>
                                Sorteia confrontos entre turmas. O chaveamento aceita de 2 a 32 equipes.
                            <?php elseif (bracket_uses_player_names($selectedModality)): ?>
                                Sorteia os alunos inscritos e monta confrontos eliminatórios. O chaveamento aceita de 2 a 32 alunos.
                            <?php else: ?>
                                Sorteia confrontos entre times. O chaveamento aceita de 2 a 32 equipes.
                            <?php endif; ?>
                        </div>
                    <?php elseif ($usesRoundRobin): ?>
                        <div class="col-lg-5 event-actions-col">
                            <form method="post" class="row g-2 event-actions-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="generate_round_robin">
                                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                <div class="col-12">
                                    <button class="btn btn-primary w-100" onclick="return confirmAction('Gerar partidas todos contra todos?')">Gerar todos contra todos</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-lg-7 text-muted small event-actions-hint">
                            Gera uma partida entre cada par de times desta modalidade.
                        </div>
                    <?php elseif ($selectedModality === 'fifa'): ?>
                        <div class="col-md-6 event-actions-col"><form method="post" class="event-actions-single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="generate_group"><input type="hidden" name="modality" value="fifa"><button class="btn btn-primary w-100" onclick="return confirmAction('Gerar grupos de FIFA?')">Gerar grupos</button></form></div>
                        <div class="col-md-6 event-actions-col"><form method="post" class="event-actions-single"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="generate_knockout"><input type="hidden" name="modality" value="fifa"><button class="btn btn-outline-success w-100" onclick="return confirmAction('Gerar mata-mata após os grupos?')">Gerar mata-mata</button></form></div>
                    <?php else: ?>
                        <div class="col-lg-5 event-actions-col">
                            <form method="post" class="row g-2 event-actions-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="generate_group">
                                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                <div class="col-12">
                                    <button class="btn btn-primary w-100" onclick="return confirmAction('Gerar partidas de grupo?')">Gerar grupos</button>
                                </div>
                            </form>
                        </div>
                        <div class="col-lg-3 event-actions-col">
                            <form method="post" class="mb-2 mb-lg-0 event-actions-single">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="generate_repechage">
                                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                <button class="btn btn-outline-warning w-100 secondary-flow-action" onclick="return confirmAction('Gerar repescagem entre os terceiros colocados?')">Repescagem</button>
                            </form>
                        </div>
                        <div class="col-lg-2 event-actions-col">
                            <form method="post" class="event-actions-single">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="generate_knockout">
                                <input type="hidden" name="modality" value="<?= e($selectedModality) ?>">
                                <input type="hidden" name="gender" value="<?= e($gender) ?>">
                                <button class="btn btn-outline-success w-100 secondary-flow-action" onclick="return confirmAction('Gerar eliminatórias conforme classificação?')">Eliminatórias</button>
                            </form>
                        </div>
                        <div class="col-lg-2 text-muted small event-actions-hint">
                            Gere grupos, repescagem opcional e depois eliminatórias.
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>


<div class="row g-3 event-page-content">
    <div class="<?= $isBracketModality ? 'col-12' : 'col-lg-4' ?>">
        <div id="times-evento" class="card shadow-sm event-page-teams-card">
            <div class="card-header bg-white fw-semibold"><?= game_is_new_modality($selectedModality) ? 'Turmas e participantes' : 'Times' ?></div>
            <div class="list-group list-group-flush event-page-team-list">
                <?php foreach ($teams as $team): ?>
                    <?php
                    $isOverPlayerLimit = (int) ($team['player_count'] ?? 0) > $selectedPlayerLimit;
                    $teamPlayers = $playersByTeam[(int) $team['id']] ?? [];
                    $teamDisplayName = game_is_new_modality($selectedModality) ? $team['class_name'] : (($isBracketModality && bracket_uses_player_names($selectedModality) && $teamPlayers)
                        ? implode(' / ', array_column($teamPlayers, 'name'))
                        : $team['name']);
                    ?>
                    <a class="list-group-item list-group-item-action event-page-team-item <?= $isOverPlayerLimit ? 'list-group-item-danger' : '' ?>" href="index.php?page=players&team_id=<?= (int) $team['id'] ?>">
                        <strong><?= e($teamDisplayName) ?></strong>
                        <?php if ($usesGrouping): ?>
                            <span class="badge text-bg-light">Grupo <?= e($team['group_name']) ?></span>
                        <?php endif; ?>
                        <span class="badge <?= $isOverPlayerLimit ? 'text-bg-danger' : 'text-bg-light' ?>">
                            <?= (int) ($team['player_count'] ?? 0) ?>/<?= (int) $selectedPlayerLimit ?> <?= game_is_new_modality($selectedModality) ? 'alunos' : 'jogadores' ?>
                        </span>
                        <?php if (game_is_new_modality($selectedModality) && !empty($playersByTeam[(int) $team['id']])): ?>
                            <span class="d-block small text-muted mt-1"><?= e(implode(', ', array_column($playersByTeam[(int) $team['id']], 'name'))) ?></span>
                        <?php endif; ?>
                        <?php if (!game_is_new_modality($selectedModality)): ?>
                        <br><span class="small text-muted">
                            <?php if ($isBracketModality && bracket_uses_player_names($selectedModality)): ?>
                                <?= e($team['class_name']) ?>
                            <?php elseif ($canManage): ?>
                                <?= e($team['class_name']) ?> · Rep.: <?= e(team_representative_label($team)) ?>
                            <?php else: ?>
                                <?= e($team['class_name']) ?>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$teams): ?>
                    <div class="list-group-item text-muted">
                        <?= game_is_new_modality($selectedModality) ? 'Nenhuma turma cadastrada.' : 'Nenhum time cadastrado.' ?>
                        <?php if ($canManage): ?> Use o botão <strong><?= game_is_new_modality($selectedModality) ? 'Inscrições' : 'Times' ?></strong> no topo da página para adicionar.<?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12"><?php require __DIR__ . '/match_board.php'; ?></div>
</div>

<script type="application/json" data-workspace-config="event">
<?= json_encode(['csrfToken' => has_role(['admin', 'professor']) ? csrf_token() : ''], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
<?php public_page_cache_finish($publicCacheKey, ($selectedSummary['running_matches'] ?? 0) > 0 ? 30 : 120, $publicCacheEnabled); ?>
