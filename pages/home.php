<?php
$publicCacheKey = 'home';
if (public_page_cache_serve($publicCacheKey)) {
    return;
}
$publicCacheEnabled = public_page_cache_start($publicCacheKey);

$totals = db()->query(
    'SELECT
        COUNT(*) AS total_events,
        SUM(CASE WHEN status = "inscricoes" THEN 1 ELSE 0 END) AS open_events,
        (SELECT COUNT(*) FROM matches WHERE status = "em_andamento") AS live_matches,
        (SELECT COUNT(*) FROM matches WHERE status = "agendada" AND (starts_at IS NULL OR CAST(starts_at AS DATE) >= CURRENT_DATE)) AS upcoming_matches
     FROM events'
)->fetch();

$liveStmt = db()->prepare(
    'SELECT m.*, e.name AS event_name, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     LEFT JOIN players hp ON hp.id = m.home_player_id
     LEFT JOIN players ap ON ap.id = m.away_player_id
     WHERE m.status = "em_andamento"
     ORDER BY COALESCE(m.starts_at, NOW()), m.sort_order
     LIMIT 6'
);
$liveStmt->execute();
$liveMatches = $liveStmt->fetchAll();

$upcomingStmt = db()->prepare(
    'SELECT m.*, e.name AS event_name, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     LEFT JOIN players hp ON hp.id = m.home_player_id
     LEFT JOIN players ap ON ap.id = m.away_player_id
     WHERE m.status = "agendada"
       AND (m.starts_at IS NULL OR CAST(m.starts_at AS DATE) >= CURRENT_DATE)
     ORDER BY COALESCE(m.starts_at, e.start_date), m.sort_order
     LIMIT 8'
);
$upcomingStmt->execute();
$upcomingMatches = $upcomingStmt->fetchAll();

$openEventsStmt = db()->prepare(
    'SELECT e.*, u.name AS responsible_name,
        (SELECT COUNT(*) FROM teams t WHERE t.event_id = e.id) AS team_count
     FROM events e
     JOIN users u ON u.id = e.created_by
     WHERE e.status = "inscricoes"
     ORDER BY e.start_date DESC, e.created_at DESC
     LIMIT 4'
);
$openEventsStmt->execute();
$openEvents = $openEventsStmt->fetchAll();

$recentEvents = db()->query(
    'SELECT e.*, u.name AS responsible_name
     FROM events e
     JOIN users u ON u.id = e.created_by
     ORDER BY e.start_date DESC, e.created_at DESC
     LIMIT 5'
)->fetchAll();
?>
<section class="home-hero">
    <div>
        <span class="eyebrow">Painel público</span>
        <h1>Interclasse Escolar</h1>
        <p>Jogos ao vivo, próximos confrontos, inscrições e tabelas em um só lugar.</p>
    </div>
    <div class="home-hero-actions">
        <a class="btn btn-primary" href="index.php?page=events">Ver eventos</a>
        <a class="btn btn-outline-primary" href="index.php?page=standings&view=points">Abrir tabelas</a>
    </div>
</section>

<div class="stats-grid mb-3">
    <div class="stat-tile">
        <span>Eventos</span>
        <strong><?= (int) ($totals['total_events'] ?? 0) ?></strong>
    </div>
    <div class="stat-tile">
        <span>Inscrições abertas</span>
        <strong><?= (int) ($totals['open_events'] ?? 0) ?></strong>
    </div>
    <div class="stat-tile">
        <span>Ao vivo</span>
        <strong><?= (int) ($totals['live_matches'] ?? 0) ?></strong>
    </div>
    <div class="stat-tile">
        <span>Próximos jogos</span>
        <strong><?= (int) ($totals['upcoming_matches'] ?? 0) ?></strong>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card shadow-sm home-live-card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2">
                <span>Hoje no interclasse</span>
                <span class="status-pill status-em_andamento"><?= count($liveMatches) ?> ao vivo</span>
            </div>
            <div class="card-body">
                <?php if ($liveMatches): ?>
                    <div class="match-card-grid">
                        <?php foreach ($liveMatches as $match): ?>
                            <a class="match-mini-card is-live" href="index.php?page=match&id=<?= (int) $match['id'] ?>">
                                <span><?= e($match['event_name']) ?> · <?= e(modality_label($match['modality'])) ?></span>
                                <strong><?= e($match['home_name'] ?? 'A definir') ?> x <?= e($match['away_name'] ?? 'A definir') ?></strong>
                                <small><?= e(match_score_text($match)) ?> · <?= e($match['place'] ?: 'Local a definir') ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state my-0">
                        <h2 class="h5">Nenhum jogo ao vivo agora</h2>
                        <p class="text-muted mb-0">Confira os próximos confrontos abaixo.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Atalhos</div>
            <div class="list-group list-group-flush quick-link-list">
                <a class="list-group-item list-group-item-action" href="index.php?page=events">
                    <strong>Agenda de eventos</strong>
                    <span>Filtros por status, modalidade e data</span>
                </a>
                <a class="list-group-item list-group-item-action" href="index.php?page=standings&view=points">
                    <strong>Tabelas por modalidade</strong>
                    <span>Classificação, pontos e critérios</span>
                </a>
                <a class="list-group-item list-group-item-action" href="index.php?page=class_standings">
                    <strong>Classificação geral</strong>
                    <span>Pontuação final por turma</span>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Próximos jogos</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 responsive-table">
                    <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Evento</th>
                        <th>Confronto</th>
                        <th>Local</th>
                        <th>Placar</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($upcomingMatches as $match): ?>
                        <tr>
                            <td data-label="Quando"><?= e(format_datetime($match['starts_at'] ?: null)) ?></td>
                            <td data-label="Evento"><?= e($match['event_name']) ?></td>
                            <td data-label="Confronto"><?= e($match['home_name'] ?? 'A definir') ?> x <?= e($match['away_name'] ?? 'A definir') ?></td>
                            <td data-label="Local"><?= e($match['place'] ?: 'A definir') ?></td>
                            <td data-label="Placar"><a href="index.php?page=match&id=<?= (int) $match['id'] ?>"><?= e(match_score_text($match)) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$upcomingMatches): ?>
                        <tr><td colspan="5" class="text-muted">Nenhum próximo jogo encontrado.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Inscrições abertas</div>
            <div class="list-group list-group-flush">
                <?php foreach ($openEvents as $event): ?>
                    <a class="list-group-item list-group-item-action" href="index.php?page=event&id=<?= (int) $event['id'] ?>">
                        <strong><?= e($event['name']) ?></strong><br>
                        <span class="small text-muted"><?= (int) $event['team_count'] ?> times · <?= e(format_datetime($event['roster_edit_until'])) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$openEvents): ?>
                    <div class="list-group-item text-muted">Nenhuma inscrição aberta.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Eventos recentes</div>
            <div class="list-group list-group-flush">
                <?php foreach ($recentEvents as $event): ?>
                    <a class="list-group-item list-group-item-action" href="index.php?page=event&id=<?= (int) $event['id'] ?>">
                        <strong><?= e($event['name']) ?></strong><br>
                        <span class="small text-muted"><?= e(format_datetime($event['start_date'])) ?> · <?= e(event_status_label($event['status'])) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (!$recentEvents): ?>
                    <div class="list-group-item text-muted">Nenhum evento cadastrado.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php public_page_cache_finish($publicCacheKey, !empty($liveMatches) ? 30 : 120, $publicCacheEnabled); ?>
