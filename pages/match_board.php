<?php
// Included from event.php; all filters are scoped to the selected event and modality.
$boardQuery = trim((string) ($_GET['match_q'] ?? ''));
$boardStage = (string) ($_GET['match_stage'] ?? '');
$boardStatus = (string) ($_GET['match_status'] ?? '');
$boardDate = (string) ($_GET['match_date'] ?? '');
$boardPage = max(1, (int) ($_GET['match_page'] ?? 1));
$allowedBoardStatuses = ['agendada','em_andamento','aguardando_resultado','encerrada','wo','cancelada','pendente'];
$allowedBoardStages = ['grupo','repescagem','preliminar','dezesseis_avos','oitavas','quartas','semifinal','final','individual'];
$where = ['m.event_id = ?', 'm.modality = ?'];
$params = [$eventId, $selectedModality];
if (!$isMixedModality) { $where[] = 'm.gender = ?'; $params[] = $gender; }
if ($boardQuery !== '') {
    $where[] = '(LOWER(COALESCE(hp.name, ht.name, \'\')) LIKE ? OR LOWER(COALESCE(ap.name, at.name, \'\')) LIKE ? OR LOWER(COALESCE(ht.class_name, \'\')) LIKE ? OR LOWER(COALESCE(at.class_name, \'\')) LIKE ?)';
    $term = '%' . (function_exists('mb_strtolower') ? mb_strtolower($boardQuery, 'UTF-8') : strtolower($boardQuery)) . '%';
    array_push($params, $term, $term, $term, $term);
}
if (in_array($boardStage, $allowedBoardStages, true)) { $where[] = 'm.stage = ?'; $params[] = $boardStage; }
if (in_array($boardStatus, $allowedBoardStatuses, true)) {
    if ($boardStatus === 'pendente') $where[] = "m.status = 'agendada' AND m.starts_at IS NULL";
    else { $where[] = 'm.status = ?'; $params[] = $boardStatus; }
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $boardDate) && strtotime($boardDate) !== false) {
    $where[] = 'm.starts_at >= ? AND m.starts_at < ?';
    $params[] = $boardDate . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime($boardDate . ' +1 day')) . ' 00:00:00';
}
$from = 'FROM matches m LEFT JOIN teams ht ON ht.id = m.home_team_id LEFT JOIN teams at ON at.id = m.away_team_id LEFT JOIN players hp ON hp.id = m.home_player_id LEFT JOIN players ap ON ap.id = m.away_player_id WHERE ' . implode(' AND ', $where);
$countStmt = db()->prepare('SELECT COUNT(*) ' . $from);
$countStmt->execute($params);
$boardTotal = (int) $countStmt->fetchColumn();
$boardPerPage = 40;
$boardPages = max(1, (int) ceil($boardTotal / $boardPerPage));
$boardPage = min($boardPage, $boardPages);
$boardSql = 'SELECT m.*, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name, ht.class_name AS home_class, at.class_name AS away_class ' . $from . ' ORDER BY CASE m.stage WHEN \'grupo\' THEN 0 WHEN \'repescagem\' THEN 1 WHEN \'preliminar\' THEN 2 WHEN \'dezesseis_avos\' THEN 3 WHEN \'oitavas\' THEN 4 WHEN \'quartas\' THEN 5 WHEN \'semifinal\' THEN 6 WHEN \'final\' THEN 7 ELSE 8 END, m.group_name, m.sort_order, m.id LIMIT ' . $boardPerPage . ' OFFSET ' . (($boardPage - 1) * $boardPerPage);
$boardStmt = db()->prepare($boardSql);
$boardStmt->execute($params);
$boardMatches = $boardStmt->fetchAll();
$boardRounds = [];
foreach ($boardMatches as $item) {
    $key = $item['stage'] === 'grupo' && $item['group_name'] ? 'Grupo ' . $item['group_name'] : stage_label($item['stage']);
    $boardRounds[$key][] = $item;
}
$boardBracket = $selectedBracketType === 'simple' || (count(array_filter($boardMatches, static fn(array $m): bool => in_array($m['stage'], ['quartas','semifinal','final','oitavas','dezesseis_avos'], true))) > 0 && $boardStage !== 'grupo');
$boardUrl = static function (array $changes = []) use ($eventId, $selectedModality, $gender): string {
    return 'index.php?' . http_build_query(array_merge($_GET, ['page' => 'event', 'id' => $eventId, 'modality' => $selectedModality, 'gender' => $gender], $changes)) . '#partidas-evento';
};
?>
<section id="partidas-evento" class="card match-board mb-4" aria-labelledby="match-board-heading">
    <div class="card-body">
        <div class="match-board-heading"><div><h2 class="h5 mb-1" id="match-board-heading">Confrontos e resultados</h2><p class="text-muted mb-0"><?= $boardTotal ?> partida<?= $boardTotal === 1 ? '' : 's' ?> encontrada<?= $boardTotal === 1 ? '' : 's' ?></p></div><a class="btn btn-outline-primary" href="index.php?page=standings&event_id=<?= $eventId ?>&modality=<?= e($selectedModality) ?>">Ver classificação</a></div>
        <nav class="match-board-quick" aria-label="Situação das partidas"><a href="<?= e($boardUrl(['match_status' => 'em_andamento', 'match_page' => 1])) ?>">Em andamento</a><a href="<?= e($boardUrl(['match_status' => 'agendada', 'match_page' => 1])) ?>">Próximas</a><a href="<?= e($boardUrl(['match_status' => 'encerrada', 'match_page' => 1])) ?>">Finalizadas</a><a href="<?= e($boardUrl(['match_status' => '', 'match_page' => 1])) ?>">Todas</a></nav>
        <form class="match-board-filters" method="get"><input type="hidden" name="page" value="event"><input type="hidden" name="id" value="<?= $eventId ?>"><input type="hidden" name="modality" value="<?= e($selectedModality) ?>"><input type="hidden" name="gender" value="<?= e($gender) ?>"><label><span>Buscar participante ou turma</span><input class="form-control" type="search" name="match_q" value="<?= e($boardQuery) ?>" placeholder="Nome ou turma"></label><label><span>Fase</span><select class="form-select" name="match_stage"><option value="">Todas</option><?php foreach ($allowedBoardStages as $stage): ?><option value="<?= e($stage) ?>" <?= $boardStage === $stage ? 'selected' : '' ?>><?= e(stage_label($stage)) ?></option><?php endforeach; ?></select></label><label><span>Status</span><select class="form-select" name="match_status"><option value="">Todos</option><?php foreach ($allowedBoardStatuses as $state): ?><option value="<?= e($state) ?>" <?= $boardStatus === $state ? 'selected' : '' ?>><?= e($state === 'pendente' ? 'A definir' : match_status_label($state)) ?></option><?php endforeach; ?></select></label><label><span>Data</span><input class="form-control" type="date" name="match_date" value="<?= e($boardDate) ?>"></label><button class="btn btn-primary" type="submit">Filtrar</button></form>
        <?php if ($boardMatches): ?><div class="match-board-rounds <?= $boardBracket ? 'is-bracket' : '' ?>">
            <?php foreach ($boardRounds as $roundLabel => $roundMatches): ?><section class="match-board-round" aria-label="<?= e($roundLabel) ?>"><h3><?= e($roundLabel) ?></h3><div class="match-board-cards">
            <?php foreach ($roundMatches as $item):
                $pending = $item['status'] === 'agendada' && !$item['starts_at'];
                $stateText = $pending ? 'A definir' : match_status_label($item['status']);
                $home = $item['home_name'] ?: ($item['home_source_match_id'] ? 'Vencedor da partida #' . $item['home_source_match_id'] : 'A definir');
                $away = $item['away_name'] ?: ($item['away_source_match_id'] ? 'Vencedor da partida #' . $item['away_source_match_id'] : 'A definir');
                $winnerId = game_is_new_modality($selectedModality) ? (int) ($item['winner_player_id'] ?? 0) : (int) ($item['winner_team_id'] ?? 0);
                $homeId = game_is_new_modality($selectedModality) ? (int) ($item['home_player_id'] ?? 0) : (int) ($item['home_team_id'] ?? 0);
                $awayId = game_is_new_modality($selectedModality) ? (int) ($item['away_player_id'] ?? 0) : (int) ($item['away_team_id'] ?? 0);
            ?><article class="match-board-card <?= $item['status'] === 'em_andamento' ? 'is-live' : '' ?>"><div class="match-board-meta"><span><?= e(modality_label($item['modality'])) ?></span><span class="<?= e(status_pill_class($item['status'])) ?>"><?= e($stateText) ?></span></div><div class="match-board-player <?= $winnerId && $winnerId === $homeId ? 'is-winner' : '' ?>"><strong><?= e($home) ?></strong><b><?= in_array($item['status'], ['encerrada','wo','em_andamento','aguardando_resultado'], true) ? (int) $item['home_score'] : '–' ?></b></div><div class="match-board-player <?= $winnerId && $winnerId === $awayId ? 'is-winner' : '' ?>"><strong><?= e($away) ?></strong><b><?= in_array($item['status'], ['encerrada','wo','em_andamento','aguardando_resultado'], true) ? (int) $item['away_score'] : '–' ?></b></div><div class="match-board-detail"><span><?= e($item['status'] === 'cancelada' ? 'Cancelada' : ($item['status'] === 'agendada' ? 'Placar pendente' : match_score_text($item))) ?></span><span><?= e($item['starts_at'] ? format_datetime($item['starts_at']) : 'Horário a definir') ?></span></div><div class="match-board-links"><a href="index.php?page=match&id=<?= (int) $item['id'] ?>">Ver partida</a><?php if ($canOperateSelectedModality): ?><a href="index.php?page=scoreboard&id=<?= (int) $item['id'] ?>">Atualizar placar</a><?php endif; ?></div></article><?php endforeach; ?></div></section><?php endforeach; ?></div>
        <?php else: ?><div class="empty-state my-3"><h3 class="h6">Nenhuma partida encontrada</h3><p class="text-muted mb-0">Ajuste os filtros ou aguarde a geração dos confrontos.</p></div><?php endif; ?>
        <?php if ($boardPages > 1): ?><nav class="match-board-pagination" aria-label="Páginas de partidas"><?php if ($boardPage > 1): ?><a class="btn btn-outline-secondary" href="<?= e($boardUrl(['match_page' => $boardPage - 1])) ?>">Anterior</a><?php endif; ?><span>Página <?= $boardPage ?> de <?= $boardPages ?></span><?php if ($boardPage < $boardPages): ?><a class="btn btn-outline-secondary" href="<?= e($boardUrl(['match_page' => $boardPage + 1])) ?>">Próxima</a><?php endif; ?></nav><?php endif; ?>
    </div>
</section>
