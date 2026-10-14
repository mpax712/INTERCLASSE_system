<?php
require_once __DIR__ . '/../services/GameScoreService.php';
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$modality = $requestedModality;
$eventStmt = db()->prepare('SELECT e.* FROM events e JOIN event_modalities em ON em.event_id = e.id WHERE e.id = ? AND em.modality = ?');
$eventStmt->execute([$eventId, $modality]);
$event = $eventStmt->fetch();
if (!$event) { http_response_code(404); echo '<div class="alert alert-warning">Evento ou modalidade não encontrado.</div>'; return; }
if (!can_manage_event_structure($eventId)) { http_response_code(403); echo '<div class="alert alert-danger">Acesso negado.</div>'; return; }
$url = 'index.php?page=match_manager&event_id=' . $eventId . '&modality=' . urlencode($modality);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($event['status'] === 'encerrado') throw new RuntimeException('Evento encerrado. Alterações indisponíveis.');
        if ($action === 'generate_groups') {
            if ($modality !== 'fifa') throw new RuntimeException('Esta modalidade não usa fase de grupos.');
            $created = game_generate_groups($eventId, $modality);
            flash($created . ' partida(s) de grupo gerada(s).');
        } elseif ($action === 'generate_bracket') {
            $created = game_generate_bracket($eventId, $modality);
            flash($created . ' partida(s) eliminatória(s) gerada(s).');
        } elseif ($action === 'save_rules') {
            $rules = validate_game_rules($modality, $_POST['rules'] ?? []);
            $stmt = db()->prepare('UPDATE event_modalities SET rules_json = ? WHERE event_id = ? AND modality = ?');
            $stmt->execute([json_encode($rules, JSON_UNESCAPED_UNICODE), $eventId, $modality]);
            flash('Regras salvas. Partidas existentes mantêm sua configuração.');
        } elseif ($action === 'save_format') {
            if ($modality !== 'fifa') throw new RuntimeException('Formato fixo para esta modalidade.');
            $format = (string) ($_POST['bracket_type'] ?? '');
            if (!in_array($format, ['groups','simple'], true)) throw new RuntimeException('Formato inválido.');
            $count = db()->prepare('SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ?');
            $count->execute([$eventId, $modality]);
            if ((int) $count->fetchColumn() > 0) throw new RuntimeException('Altere o formato antes de gerar partidas.');
            $stmt = db()->prepare('UPDATE event_modalities SET bracket_type = ? WHERE event_id = ? AND modality = ?');
            $stmt->execute([$format, $eventId, $modality]);
            flash('Formato atualizado.');
        } elseif ($action === 'create_match') {
            if ($event['status'] === 'encerrado') throw new RuntimeException('Evento encerrado.');
            $homeId = (int) ($_POST['home_player_id'] ?? 0); $awayId = (int) ($_POST['away_player_id'] ?? 0);
            if (!$homeId || !$awayId || $homeId === $awayId) throw new RuntimeException('Selecione dois alunos diferentes.');
            $players = [];
            foreach (game_players($eventId, $modality) as $p) $players[(int) $p['id']] = $p;
            if (!isset($players[$homeId], $players[$awayId])) throw new RuntimeException('Participantes inválidos para esta modalidade.');
            $stage = (string) ($_POST['stage'] ?? '');
            $format = bracket_type_for_event_modality($eventId, $modality);
            if ($format === 'groups' && $stage !== 'grupo') throw new RuntimeException('Gere a fase eliminatória após os grupos.');
            if ($format === 'simple' && $stage !== 'final') throw new RuntimeException('Use o gerador para formar o chaveamento; criação manual aceita somente final avulsa.');
            if ($format === 'simple') {
                $hasBracket = db()->prepare("SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ? AND stage <> 'grupo'");
                $hasBracket->execute([$eventId, $modality]);
                if ($hasBracket->fetchColumn()) throw new RuntimeException('Já existe chaveamento; edite as partidas geradas.');
            }
            $group = $stage === 'grupo' ? (string) ($players[$homeId]['group_name'] ?? '') : null;
            if ($stage === 'grupo' && ($group === '' || $group !== (string) ($players[$awayId]['group_name'] ?? ''))) throw new RuntimeException('Os alunos precisam pertencer ao mesmo grupo.');
            $exists = db()->prepare('SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ? AND stage = ? AND ((home_player_id = ? AND away_player_id = ?) OR (home_player_id = ? AND away_player_id = ?))');
            $exists->execute([$eventId,$modality,$stage,$homeId,$awayId,$awayId,$homeId]);
            if ($exists->fetchColumn()) throw new RuntimeException('Este confronto já existe.');
            db()->beginTransaction();
            try {
                $id = game_create_match($eventId, $modality, $stage, $group, game_node_for_player($players[$homeId]), game_node_for_player($players[$awayId]), 9000 + time() % 1000, trim((string) ($_POST['place'] ?? '')) ?: null);
                $starts = trim((string) ($_POST['starts_at'] ?? ''));
                if ($starts !== '') { $set = db()->prepare('UPDATE matches SET starts_at = ? WHERE id = ?'); $set->execute([str_replace('T',' ',$starts) . ':00', $id]); }
                db()->commit();
            } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
            flash('Partida criada.');
        } elseif ($action === 'save_schedule') {
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $expectedVersion = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT);
            if ($expectedVersion === false || $expectedVersion === null || $expectedVersion < 0) throw new RuntimeException('Recarregue a página antes de alterar a agenda.');
            $starts = trim((string) ($_POST['starts_at'] ?? ''));
            $place = trim((string) ($_POST['place'] ?? ''));
            $stmt = db()->prepare("UPDATE matches SET starts_at = ?, place = ?, version = version + 1 WHERE id = ? AND event_id = ? AND modality = ? AND status = 'agendada' AND version = ?");
            $stmt->execute([$starts !== '' ? str_replace('T',' ',$starts) . ':00' : null, $place ?: null, $matchId, $eventId, $modality, $expectedVersion]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('A partida mudou desde que você abriu esta página. Recarregue e confira os dados atuais.');
            game_forget_score_cache($matchId); public_cache_forget_all();
            flash('Horário e local atualizados.');
        } elseif ($action === 'delete_match') {
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $children = db()->prepare('SELECT COUNT(*) FROM matches WHERE home_source_match_id = ? OR away_source_match_id = ?');
            $children->execute([$matchId, $matchId]);
            if ($children->fetchColumn()) throw new RuntimeException('Esta partida alimenta outra fase e não pode ser excluída isoladamente.');
            $delete = db()->prepare("DELETE FROM matches WHERE id = ? AND event_id = ? AND modality = ? AND status = 'agendada'");
            $delete->execute([$matchId, $eventId, $modality]);
            if ($delete->rowCount() !== 1) throw new RuntimeException('Somente partidas agendadas podem ser excluídas.');
            game_forget_score_cache($matchId); public_cache_forget_all();
            flash('Partida excluída.');
        } elseif ($action === 'correct_match') {
            $matchId = (int) ($_POST['match_id'] ?? 0);
            $scoreData = ['match_id' => $matchId, 'action' => 'correct', 'expected_version' => (int) ($_POST['version'] ?? -1), 'request_id' => bin2hex(random_bytes(16)), 'reason' => $_POST['reason'] ?? ''];
            if ($modality === 'fifa') {
                foreach (['home_score','away_score','home_penalties','away_penalties'] as $key) $scoreData[$key] = $_POST[$key] ?? null;
            } else {
                $scoreData['battles'] = [];
                foreach (($_POST['battles'] ?? []) as $battle) {
                    if (($battle['winner'] ?? '') === '') continue;
                    $scoreData['battles'][] = $battle;
                }
            }
            game_apply_score_action($matchId, $scoreData, current_user());
            flash('Resultado corrigido e classificação recalculada.');
        } else throw new RuntimeException('Ação inválida.');
    } catch (Throwable $error) { flash($error->getMessage(), 'warning'); }
    redirect($url);
}

$rules = game_event_rules($eventId, $modality);
$format = bracket_type_for_event_modality($eventId, $modality);
$players = game_players($eventId, $modality);
$generationStmt = db()->prepare("SELECT
    SUM(CASE WHEN stage = 'grupo' THEN 1 ELSE 0 END) AS group_matches,
    SUM(CASE WHEN stage = 'grupo' AND status NOT IN ('encerrada','wo','cancelada') THEN 1 ELSE 0 END) AS pending_group_matches,
    SUM(CASE WHEN stage IN ('dezesseis_avos','oitavas','quartas','semifinal','final') THEN 1 ELSE 0 END) AS bracket_matches
    FROM matches WHERE event_id = ? AND modality = ?");
$generationStmt->execute([$eventId, $modality]);
$generation = $generationStmt->fetch() ?: [];
$groupMatches = (int) ($generation['group_matches'] ?? 0);
$pendingGroupMatches = (int) ($generation['pending_group_matches'] ?? 0);
$bracketMatches = (int) ($generation['bracket_matches'] ?? 0);
$validPlayerCount = count($players) >= 2 && count($players) <= 32;
$canGenerateGroups = $event['status'] !== 'encerrado' && $modality === 'fifa' && $format === 'groups' && $groupMatches === 0 && $bracketMatches === 0 && $validPlayerCount;
$canGenerateBracket = $event['status'] !== 'encerrado' && $bracketMatches === 0 && $validPlayerCount
    && ($format === 'simple' || ($groupMatches > 0 && $pendingGroupMatches === 0));
$managerQuery = trim(substr((string) ($_GET['manager_q'] ?? ''), 0, 100));
$managerStatus = (string) ($_GET['manager_status'] ?? '');
$managerPage = max(1, (int) ($_GET['manager_page'] ?? 1));
$managerStatuses = ['agendada', 'em_andamento', 'aguardando_resultado', 'encerrada', 'wo', 'cancelada'];
$managerFrom = ' FROM matches m LEFT JOIN players hp ON hp.id = m.home_player_id LEFT JOIN players ap ON ap.id = m.away_player_id LEFT JOIN teams ht ON ht.id = m.home_team_id LEFT JOIN teams at ON at.id = m.away_team_id WHERE m.event_id = ? AND m.modality = ?';
$managerParams = [$eventId, $modality];
if ($managerQuery !== '') {
    $managerFrom .= " AND (LOWER(COALESCE(hp.name, ht.name, '')) LIKE ? OR LOWER(COALESCE(ap.name, at.name, '')) LIKE ?)";
    $needle = '%' . (function_exists('mb_strtolower') ? mb_strtolower($managerQuery, 'UTF-8') : strtolower($managerQuery)) . '%';
    array_push($managerParams, $needle, $needle);
}
if (in_array($managerStatus, $managerStatuses, true)) { $managerFrom .= ' AND m.status = ?'; $managerParams[] = $managerStatus; }
$managerCount = db()->prepare('SELECT COUNT(*)' . $managerFrom);
$managerCount->execute($managerParams);
$managerTotal = (int) $managerCount->fetchColumn();
$managerPerPage = 40;
$managerPages = max(1, (int) ceil($managerTotal / $managerPerPage));
$managerPage = min($managerPage, $managerPages);
$stmt = db()->prepare('SELECT m.*, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name' . $managerFrom . ' ORDER BY m.sort_order, m.id LIMIT ' . $managerPerPage . ' OFFSET ' . (($managerPage - 1) * $managerPerPage));
$stmt->execute($managerParams);
$matches = $stmt->fetchAll();
$managerPageUrl = static fn(int $page): string => 'index.php?' . http_build_query(['page' => 'match_manager', 'event_id' => $eventId, 'modality' => $modality, 'manager_q' => $managerQuery, 'manager_status' => $managerStatus, 'manager_page' => $page]);
$affectedByMatch = [];
$affectedStmt = db()->prepare('SELECT id, home_source_match_id, away_source_match_id, status FROM matches WHERE event_id = ? AND modality = ? AND (home_source_match_id IS NOT NULL OR away_source_match_id IS NOT NULL)');
$affectedStmt->execute([$eventId, $modality]);
foreach ($affectedStmt->fetchAll() as $affected) {
    foreach (['home_source_match_id', 'away_source_match_id'] as $key) {
        if ($affected[$key]) $affectedByMatch[(int) $affected[$key]][] = '#' . $affected['id'] . ' (' . match_status_label($affected['status']) . ')';
    }
}
$generatedBracketLabels = [];
if ($modality === 'fifa' && $format === 'groups') {
    $bracketStmt = db()->prepare("SELECT id, status FROM matches WHERE event_id = ? AND modality = ? AND stage <> 'grupo' ORDER BY id LIMIT 5");
    $bracketStmt->execute([$eventId, $modality]);
    foreach ($bracketStmt->fetchAll() as $bracketMatch) $generatedBracketLabels[] = '#' . $bracketMatch['id'] . ' (' . match_status_label($bracketMatch['status']) . ')';
}
?>
<div class="page-title"><div><h1 class="h3 mb-1">Montagem · <?= e(modality_label($modality)) ?></h1><p class="text-muted mb-0"><?= e($event['name']) ?></p></div><a class="btn btn-outline-secondary" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($modality) ?>">Voltar</a></div>
<section class="card shadow-sm mb-3"><div class="card-body"><h2 class="h5">Gerar partidas</h2><p class="small text-muted"><?= count($players) ?> aluno(s) inscrito(s). <?= $format === 'groups' ? 'Gere os grupos e, após encerrar suas partidas, gere o mata-mata.' : 'Sorteie o chaveamento eliminatório.' ?></p><div class="d-flex gap-2 flex-wrap">
<?php if ($format === 'groups'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="action" value="generate_groups"><button class="btn btn-primary" <?= $canGenerateGroups ? '' : 'disabled' ?> onclick="return confirm('Gerar partidas de grupo para <?= e(modality_label($modality)) ?>?')">Gerar grupos</button></form><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="action" value="generate_bracket"><button class="btn <?= $format === 'groups' ? 'btn-outline-success' : 'btn-primary' ?>" <?= $canGenerateBracket ? '' : 'disabled' ?> onclick="return confirm('Sortear e gerar o chaveamento eliminatório?')">Gerar mata-mata</button></form>
</div><?php if ($groupMatches > 0 && $pendingGroupMatches > 0): ?><p class="small text-muted mt-2 mb-0">Finalize <?= $pendingGroupMatches ?> partida(s) de grupo para liberar o mata-mata.</p><?php endif; ?></div></section>
<div class="row g-3 mb-4">
    <div class="col-lg-5"><div class="card h-100"><div class="card-body"><h2 class="h5">Regras para partidas futuras</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="action" value="save_rules">
        <?php if ($modality === 'clash_royale'): ?><label class="form-label">Melhor de<select class="form-select" name="rules[best_of]"><?php foreach ([1,3,5] as $best): ?><option value="<?= $best ?>" <?= $rules['best_of'] === $best ? 'selected' : '' ?>><?= $best ?> batalha<?= $best > 1 ? 's' : '' ?></option><?php endforeach; ?></select></label>
        <?php else: foreach (['win_points' => 'Vitória', 'draw_points' => 'Empate', 'loss_points' => 'Derrota', 'wo_goals' => 'Gols no W.O.'] as $key => $label): ?><label class="form-label d-block"><?= $label ?><input class="form-control" type="number" min="0" max="20" name="rules[<?= e($key) ?>]" value="<?= (int) $rules[$key] ?>"></label><?php endforeach; endif; ?>
        <h3 class="h6 mt-3">Pontos para a turma por colocação</h3>
        <div class="row g-2 mb-3"><?php foreach (['class_points_first' => '1º', 'class_points_second' => '2º', 'class_points_third' => '3º', 'class_points_other' => 'Demais'] as $key => $label): ?><label class="col-6 form-label"><?= $label ?><input class="form-control" type="number" min="0" max="50" name="rules[<?= e($key) ?>]" value="<?= (int) $rules[$key] ?>"></label><?php endforeach; ?></div>
        <button class="btn btn-primary">Salvar regras</button></form></div></div></div>
    <?php if ($modality === 'fifa'): ?><div class="col-lg-3"><div class="card h-100"><div class="card-body"><h2 class="h5">Formato</h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="action" value="save_format"><select class="form-select mb-3" name="bracket_type"><option value="groups" <?= $format === 'groups' ? 'selected' : '' ?>>Grupos e mata-mata</option><option value="simple" <?= $format === 'simple' ? 'selected' : '' ?>>Mata-mata</option></select><button class="btn btn-outline-primary">Salvar formato</button></form></div></div></div><?php endif; ?>
    <div class="col-lg-4"><div class="card h-100"><div class="card-body"><h2 class="h5">Criar partida</h2><p class="small text-muted">Selecione os alunos. Para chaves completas, use “Gerar fases” no evento.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="action" value="create_match"><input type="hidden" name="stage" value="<?= $format === 'groups' ? 'grupo' : 'final' ?>"><select class="form-select mb-2" name="home_player_id" required><option value="">Participante 1</option><?php foreach ($players as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name'] . ' · ' . $p['class_name']) ?></option><?php endforeach; ?></select><select class="form-select mb-2" name="away_player_id" required><option value="">Participante 2</option><?php foreach ($players as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name'] . ' · ' . $p['class_name']) ?></option><?php endforeach; ?></select><input class="form-control mb-2" type="datetime-local" name="starts_at"><input class="form-control mb-2" name="place" placeholder="Local"><button class="btn btn-primary">Criar partida</button></form></div></div></div>
</div>
<section class="card"><div class="card-header bg-white"><h2 class="h5 mb-0">Partidas <small class="text-muted">(<?= $managerTotal ?>)</small></h2></div><div class="card-body"><form method="get" class="d-flex gap-2 flex-wrap mb-3"><input type="hidden" name="page" value="match_manager"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input class="form-control flex-grow-1" type="search" name="manager_q" value="<?= e($managerQuery) ?>" placeholder="Buscar participante" aria-label="Buscar participante"><select class="form-select" name="manager_status" aria-label="Filtrar estado"><option value="">Todos os estados</option><?php foreach ($managerStatuses as $state): ?><option value="<?= e($state) ?>" <?= $managerStatus === $state ? 'selected' : '' ?>><?= e(match_status_label($state)) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary">Buscar</button></form><div class="game-manager-list">
<?php foreach ($matches as $m): $matchRules = game_match_rules($m); $savedScore = decode_score_data($m['score_data'] ?? null, $modality); $savedPenalties = $savedScore['penalties'] ?? []; $savedBattles = $savedScore['battles'] ?? []; ?><article class="game-manager-match"><div><strong><?= e(($m['home_name'] ?? 'A definir') . ' × ' . ($m['away_name'] ?? 'A definir')) ?></strong><small><?= e(stage_label($m['stage'])) ?> · <?= e(match_score_text($m)) ?> · <?= e(match_status_label($m['status'])) ?></small></div><div class="d-flex gap-2 flex-wrap"><a class="btn btn-sm btn-primary" href="index.php?page=scoreboard&id=<?= (int) $m['id'] ?>">Placar</a>
<?php if ($m['status'] === 'agendada'): ?><details><summary class="btn btn-sm btn-outline-secondary">Agenda</summary><form method="post" class="game-manager-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="version" value="<?= (int) $m['version'] ?>"><input class="form-control" type="datetime-local" name="starts_at" value="<?= e($m['starts_at'] ? date('Y-m-d\TH:i', strtotime($m['starts_at'])) : '') ?>"><input class="form-control" name="place" value="<?= e($m['place'] ?? '') ?>" placeholder="Local"><button class="btn btn-sm btn-primary">Salvar</button></form></details><form method="post" onsubmit="return confirm('Excluir esta partida?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="delete_match"><button class="btn btn-sm btn-outline-danger">Excluir</button></form><?php endif; ?>
<?php if (($user['role'] ?? '') === 'admin' && in_array($m['status'], ['encerrada','wo'], true)): ?><details><summary class="btn btn-sm btn-outline-warning">Corrigir</summary><p class="small text-muted mt-2 mb-1">A classificação será recalculada. <?php $affectedLabels = $m['stage'] === 'grupo' ? $generatedBracketLabels : ($affectedByMatch[(int) $m['id']] ?? []); if ($affectedLabels): ?>Confrontos afetados: <?= e(implode(', ', $affectedLabels)) ?>. <?php else: ?>Nenhuma partida posterior vinculada. <?php endif; ?>Se algum confronto dependente já começou, a correção será bloqueada.</p><form method="post" class="game-manager-inline" onsubmit="return confirm('Esse resultado alterará a classificação. Deseja continuar?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($modality) ?>"><input type="hidden" name="match_id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="version" value="<?= (int) $m['version'] ?>"><input type="hidden" name="action" value="correct_match"><input class="form-control" name="reason" required minlength="3" maxlength="240" placeholder="Motivo da correção"><?php if ($modality === 'fifa'): ?><input class="form-control" type="number" min="0" name="home_score" value="<?= (int) $m['home_score'] ?>" aria-label="Gols participante 1"><input class="form-control" type="number" min="0" name="away_score" value="<?= (int) $m['away_score'] ?>" aria-label="Gols participante 2"><input class="form-control" type="number" min="0" name="home_penalties" value="<?= e($savedPenalties['home'] ?? '') ?>" placeholder="Pênaltis 1"><input class="form-control" type="number" min="0" name="away_penalties" value="<?= e($savedPenalties['away'] ?? '') ?>" placeholder="Pênaltis 2"><?php else: for ($i=0; $i < $matchRules['best_of']; $i++): ?><div class="d-flex gap-1"><input class="form-control" type="number" min="0" max="3" name="battles[<?= $i ?>][home_crowns]" value="<?= e($savedBattles[$i]['home_crowns'] ?? '') ?>" placeholder="Coroas 1"><input class="form-control" type="number" min="0" max="3" name="battles[<?= $i ?>][away_crowns]" value="<?= e($savedBattles[$i]['away_crowns'] ?? '') ?>" placeholder="Coroas 2"><select class="form-select" name="battles[<?= $i ?>][winner]"><option value="">Não jogada</option><option value="home" <?= ($savedBattles[$i]['winner'] ?? '') === 'home' ? 'selected' : '' ?>>Venceu 1</option><option value="away" <?= ($savedBattles[$i]['winner'] ?? '') === 'away' ? 'selected' : '' ?>>Venceu 2</option></select><label><input type="checkbox" name="battles[<?= $i ?>][tiebreak]" value="1" <?= !empty($savedBattles[$i]['tiebreak']) ? 'checked' : '' ?>>Desempate</label></div><?php endfor; endif; ?><button class="btn btn-sm btn-warning">Salvar correção</button></form></details><?php endif; ?>
</div></article><?php endforeach; ?><?php if (!$matches): ?><p class="text-muted">Nenhuma partida nesta modalidade.</p><?php endif; ?>
</div><?php if ($managerPages > 1): ?><nav class="match-board-pagination mt-3" aria-label="Páginas da montagem"><?php if ($managerPage > 1): ?><a class="btn btn-outline-secondary" href="<?= e($managerPageUrl($managerPage - 1)) ?>">Anterior</a><?php endif; ?><span>Página <?= $managerPage ?> de <?= $managerPages ?></span><?php if ($managerPage < $managerPages): ?><a class="btn btn-outline-secondary" href="<?= e($managerPageUrl($managerPage + 1)) ?>">Próxima</a><?php endif; ?></nav><?php endif; ?></div></section>
