<?php
require_roles(['admin']);

$totals = db()->query(
    'SELECT
        (SELECT COUNT(*) FROM events) AS event_count,
        (SELECT COUNT(*) FROM matches) AS match_count,
        (SELECT COUNT(*) FROM players) AS player_count,
        (SELECT COUNT(*) FROM events WHERE status = "inscricoes") AS open_event_count,
        (SELECT COUNT(*) FROM matches WHERE status = "em_andamento") AS live_match_count,
        (SELECT COUNT(*) FROM matches WHERE status = "agendada") AS pending_match_count'
)->fetch();
$eventCount = (int) ($totals['event_count'] ?? 0);
$matchCount = (int) ($totals['match_count'] ?? 0);
$playerCount = (int) ($totals['player_count'] ?? 0);
$openEventCount = (int) ($totals['open_event_count'] ?? 0);
$liveMatchCount = (int) ($totals['live_match_count'] ?? 0);
$pendingMatchCount = (int) ($totals['pending_match_count'] ?? 0);
$events = db()->query('SELECT e.*, u.name AS responsible_name FROM events e JOIN users u ON u.id = e.created_by ORDER BY e.created_at DESC LIMIT 10')->fetchAll();

$runningMatchesStmt = db()->query(
    'SELECT m.id, m.modality, m.stage, e.name AS event_name, ht.name AS home_name, at.name AS away_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     WHERE m.status = "em_andamento"
     ORDER BY COALESCE(m.starts_at, NOW()), m.sort_order
     LIMIT 5'
);
$runningMatches = $runningMatchesStmt->fetchAll();

$unscheduledMatchesStmt = db()->query(
    'SELECT m.id, m.event_id, m.modality, e.name AS event_name, ht.name AS home_name, at.name AS away_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     WHERE m.status = "agendada" AND (m.starts_at IS NULL OR m.place IS NULL OR m.place = "")
     ORDER BY e.start_date DESC, m.sort_order
     LIMIT 5'
);
$unscheduledMatches = $unscheduledMatchesStmt->fetchAll();

$openEvents = db()->query(
    'SELECT id, name, roster_edit_until
     FROM events
     WHERE status = "inscricoes" AND archived = 0
     ORDER BY COALESCE(roster_edit_until, start_date), created_at DESC
     LIMIT 5'
)->fetchAll();

$modalityReadiness = db()->query(
    'SELECT e.id AS event_id, e.name AS event_name, em.modality,
        COUNT(DISTINCT t.id) AS team_count,
        COUNT(DISTINCT m.id) AS match_count,
        SUM(CASE WHEN m.status = "agendada" THEN 1 ELSE 0 END) AS scheduled_count
     FROM event_modalities em
     JOIN events e ON e.id = em.event_id
     LEFT JOIN teams t ON t.event_id = em.event_id AND t.modality = em.modality
     LEFT JOIN matches m ON m.event_id = em.event_id AND m.modality = em.modality
     WHERE e.status IN ("inscricoes", "em_andamento") AND e.archived = 0
     GROUP BY e.id, e.name, em.modality
     HAVING COUNT(DISTINCT t.id) < 2 OR COUNT(DISTINCT m.id) = 0
     ORDER BY e.start_date DESC, e.created_at DESC
     LIMIT 8'
)->fetchAll();
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Painel administrativo</h1>
        <p class="text-muted mb-0">Visão geral de eventos, partidas e inscrições.</p>
    </div>
</div>

<div class="mb-4">
    <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link active" href="index.php?page=dashboard">Painel</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=users">Usuários</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=classes">Turmas</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=events">Eventos</a></li>
    </ul>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-tile"><span>Eventos</span><strong><?= (int) $eventCount ?></strong><small><?= (int) $openEventCount ?> com inscrições abertas</small></div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile"><span>Partidas</span><strong><?= (int) $matchCount ?></strong><small><?= (int) $liveMatchCount ?> ao vivo · <?= (int) $pendingMatchCount ?> agendadas</small></div>
    </div>
    <div class="col-md-4">
        <div class="stat-tile"><span>Jogadores</span><strong><?= (int) $playerCount ?></strong><small>Pré-cadastros e elencos</small></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Próximas ações</span>
                <span class="status-pill status-em_andamento"><?= count($runningMatches) + count($unscheduledMatches) + count($modalityReadiness) ?> pendências</span>
            </div>
            <div class="list-group list-group-flush action-list">
                <?php foreach ($runningMatches as $match): ?>
                    <a class="list-group-item list-group-item-action action-item action-urgent" href="index.php?page=scoreboard&id=<?= (int) $match['id'] ?>">
                        <span>Partida em andamento</span>
                        <strong><?= e($match['home_name'] ?? 'A definir') ?> x <?= e($match['away_name'] ?? 'A definir') ?></strong>
                        <small><?= e($match['event_name']) ?> · <?= e(modality_label($match['modality'])) ?> · <?= e(stage_label($match['stage'])) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php foreach ($unscheduledMatches as $match): ?>
                    <a class="list-group-item list-group-item-action action-item" href="index.php?page=event&id=<?= (int) $match['event_id'] ?>&modality=<?= e($match['modality']) ?>#partidas-evento">
                        <span>Completar agenda/local</span>
                        <strong><?= e($match['home_name'] ?? 'A definir') ?> x <?= e($match['away_name'] ?? 'A definir') ?></strong>
                        <small><?= e($match['event_name']) ?> · <?= e(modality_label($match['modality'])) ?></small>
                    </a>
                <?php endforeach; ?>
                <?php foreach ($modalityReadiness as $item): ?>
                    <a class="list-group-item list-group-item-action action-item" href="index.php?page=event&id=<?= (int) $item['event_id'] ?>&modality=<?= e($item['modality']) ?>">
                        <span><?= (int) $item['team_count'] < 2 ? 'Cadastrar participantes' : 'Gerar confrontos' ?></span>
                        <strong><?= e($item['event_name']) ?> · <?= e(modality_label($item['modality'])) ?></strong>
                        <small><?= (int) $item['team_count'] ?> time(s) · <?= (int) $item['match_count'] ?> jogo(s)</small>
                    </a>
                <?php endforeach; ?>
                <?php if (!$runningMatches && !$unscheduledMatches && !$modalityReadiness): ?>
                    <div class="list-group-item text-muted">Nenhuma ação crítica agora. O sistema está pronto para acompanhamento.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Inscrições abertas</div>
            <div class="list-group list-group-flush quick-link-list">
                <?php foreach ($openEvents as $event): ?>
                    <a class="list-group-item list-group-item-action" href="index.php?page=event&id=<?= (int) $event['id'] ?>">
                        <strong><?= e($event['name']) ?></strong>
                        <span>Prazo de elenco: <?= e(format_datetime($event['roster_edit_until'])) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$openEvents): ?>
                    <div class="list-group-item text-muted">Nenhum evento com inscrição aberta.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Últimos eventos</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 responsive-table">
            <thead><tr><th>Evento</th><th>Responsável</th><th>Período</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td data-label="Evento"><?= e($event['name']) ?></td>
                    <td data-label="Responsável"><?= e($event['responsible_name']) ?></td>
                    <td data-label="Período"><?= e(date('d/m/Y', strtotime($event['start_date']))) ?></td>
                    <td data-label="Status"><span class="<?= e(status_pill_class($event['status'])) ?>"><?= e(event_status_label($event['status'])) ?></span></td>
                    <td data-label="Ações"><a class="btn btn-sm btn-outline-primary" href="index.php?page=event&id=<?= (int) $event['id'] ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
