<?php
require_roles(['admin', 'professor']);
ensure_occurrences_schema();

$eventId = (int) ($_GET['event_id'] ?? 0);
$highlightId = (int) ($_GET['occurrence_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$targetFilter = $_GET['target_type'] ?? '';
$modalityFilter = $_GET['modality'] ?? '';
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;

$events = db()->query('SELECT id, name FROM events ORDER BY start_date DESC, created_at DESC')->fetchAll();
if (!$eventId && $events) {
    $eventId = (int) $events[0]['id'];
}

$records = [];
$totalRecords = 0;
$totalPages = 1;
$user = current_user();
$allowedRecordModalities = allowed_modalities();

if ($user && $user['role'] === 'professor') {
    $allowedRecordModalities = normalize_modalities($user['modalities'] ?? []);
    if ($modalityFilter === '' && $allowedRecordModalities) {
        $modalityFilter = $allowedRecordModalities[0];
    }
}

if ($eventId) {
    if ($user && $user['role'] === 'professor' && (!$modalityFilter || !in_array($modalityFilter, $allowedRecordModalities, true))) {
        http_response_code(403);
        echo '<div class="alert alert-danger">Acesso negado.</div>';
        return;
    }

    $where = 'WHERE o.event_id = ?';
    $params = [$eventId];

    if ($q !== '') {
        $where .= ' AND (
            o.title LIKE ? OR o.description LIKE ? OR o.action_text LIKE ?
            OR t.name LIKE ? OR p.name LIKE ? OR u.name LIKE ?
            OR ht.name LIKE ? OR at.name LIKE ? OR m.stage LIKE ?
        )';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    if (in_array($targetFilter, ['player', 'team'], true)) {
        $where .= ' AND o.target_type = ?';
        $params[] = $targetFilter;
    }

    if ($modalityFilter !== '' && in_array($modalityFilter, $allowedRecordModalities, true)) {
        $where .= ' AND m.modality = ?';
        $params[] = $modalityFilter;
    }

    $countStmt = db()->prepare(
        "SELECT COUNT(*) AS total
         FROM occurrences o
         JOIN matches m ON m.id = o.match_id
         JOIN teams t ON t.id = o.team_id
         LEFT JOIN players p ON p.id = o.player_id
         LEFT JOIN users u ON u.id = o.created_by
         LEFT JOIN teams ht ON ht.id = m.home_team_id
         LEFT JOIN teams at ON at.id = m.away_team_id
         $where"
    );
    $countStmt->execute($params);
    $totalRecords = (int) ($countStmt->fetch()['total'] ?? 0);
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    if ($pageNumber > $totalPages) {
        $pageNumber = $totalPages;
    }
    $offset = ($pageNumber - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT o.*, e.name AS event_name, t.name AS team_name, p.name AS player_name,
                u.name AS created_by_name, m.modality, m.stage,
                ht.name AS home_name, at.name AS away_name
         FROM occurrences o
         JOIN events e ON e.id = o.event_id
         JOIN matches m ON m.id = o.match_id
         JOIN teams t ON t.id = o.team_id
         LEFT JOIN players p ON p.id = o.player_id
         LEFT JOIN users u ON u.id = o.created_by
         LEFT JOIN teams ht ON ht.id = m.home_team_id
         LEFT JOIN teams at ON at.id = m.away_team_id
         $where
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $records = $stmt->fetchAll();
}

function occurrence_target_label(string $targetType): string
{
    return [
        'player' => 'Jogador',
        'team' => 'Time',
    ][$targetType] ?? ucfirst($targetType);
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Registros de ocorrências</h1>
        <p class="text-muted mb-0">Histórico de ocorrências registradas nas partidas.</p>
    </div>
</div>

<form class="row g-2 mb-3 filter-card filter-card-inline compact-filter-form" method="get">
    <input type="hidden" name="page" value="occurrence_records">
    <div class="col-lg-4">
        <select class="form-select" name="event_id" onchange="this.form.submit()">
            <?php foreach ($events as $event): ?>
                <option value="<?= (int) $event['id'] ?>" <?= $eventId === (int) $event['id'] ? 'selected' : '' ?>><?= e($event['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-4">
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar por ocorrência, time, jogador ou partida">
    </div>
    <div class="col-sm-6 col-lg-2">
        <select class="form-select" name="target_type">
            <option value="">Todos os alvos</option>
            <?php foreach (['player', 'team'] as $targetType): ?>
                <option value="<?= e($targetType) ?>" <?= $targetFilter === $targetType ? 'selected' : '' ?>><?= e(occurrence_target_label($targetType)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-6 col-lg-2">
        <select class="form-select" name="modality">
            <option value="">Todas as modalidades</option>
            <?php foreach ($allowedRecordModalities as $modality): ?>
                <option value="<?= e($modality) ?>" <?= $modalityFilter === $modality ? 'selected' : '' ?>><?= e(modality_label($modality)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-2"><button class="btn btn-primary w-100">Filtrar</button></div>
    <div class="col-lg-2"><a class="btn btn-outline-secondary w-100" href="index.php?page=occurrence_records&event_id=<?= (int) $eventId ?>">Limpar filtros</a></div>
</form>

<?php if ($eventId): ?>
    <p class="text-muted small mb-3">
        <?= (int) $totalRecords ?> ocorrência<?= $totalRecords === 1 ? '' : 's' ?> encontrada<?= $totalRecords === 1 ? '' : 's' ?>.
    </p>
<?php endif; ?>

<?php if (!$eventId): ?>
    <div class="alert alert-info">Nenhum evento cadastrado.</div>
<?php elseif (!$records): ?>
    <div class="alert alert-info">Nenhuma ocorrência encontrada para este evento.</div>
<?php else: ?>
    <div class="vstack gap-3 occurrence-record-list">
        <?php foreach ($records as $record): ?>
            <?php $isHighlighted = $highlightId === (int) $record['id']; ?>
            <article id="occurrence-<?= (int) $record['id'] ?>" class="card shadow-sm occurrence-record-card <?= $isHighlighted ? 'border-warning' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-3 flex-wrap mb-2">
                        <div>
                            <h2 class="h5 mb-1"><?= e($record['title']) ?></h2>
                            <p class="text-muted mb-0">
                                <?= e($record['team_name']) ?>
                                <?php if ($record['target_type'] === 'player'): ?>
                                    · <?= e($record['player_name'] ?? 'Jogador removido') ?>
                                <?php endif; ?>
                                · <?= e(format_datetime($record['created_at'])) ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php if ($isHighlighted): ?><span class="badge text-bg-warning">Novo registro</span><?php endif; ?>
                            <span class="badge text-bg-light"><?= e(occurrence_target_label($record['target_type'])) ?></span>
                            <span class="badge text-bg-light"><?= e(modality_label($record['modality'])) ?></span>
                        </div>
                    </div>

                    <p class="mb-3"><?= nl2br(e($record['description'])) ?></p>

                    <?php if ($record['target_type'] === 'player'): ?>
                        <?php if ($record['action_text']): ?>
                            <div class="alert alert-light border mb-3">
                                <strong>Ação:</strong> <?= nl2br(e($record['action_text'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ((int) $record['ineligible_next_game'] === 1): ?>
                            <span class="badge text-bg-warning mb-3">Inelegível para o próximo jogo do time</span>
                        <?php endif; ?>
                    <?php elseif ((int) $record['points_deducted'] > 0): ?>
                        <div class="alert alert-warning mb-3">
                            <strong>Penalidade:</strong> -<?= (int) $record['points_deducted'] ?> ponto<?= (int) $record['points_deducted'] === 1 ? '' : 's' ?> para o time.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between gap-2 flex-wrap align-items-center">
                        <span class="small text-muted">
                            <?= e($record['event_name']) ?> ·
                            <?= e($record['home_name'] ?? 'A definir') ?> x <?= e($record['away_name'] ?? 'A definir') ?> ·
                            <?= e(stage_label($record['stage'])) ?> ·
                            Registrado por <?= e($record['created_by_name'] ?? 'Sistema') ?>
                        </span>
                        <a class="btn btn-outline-secondary btn-sm" href="index.php?page=match&id=<?= (int) $record['match_id'] ?>">Ver jogo</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Paginação das ocorrências">
            <div class="d-flex gap-2 flex-wrap justify-content-center">
                <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                    <?php
                    $query = array_filter([
                        'page' => 'occurrence_records',
                        'event_id' => $eventId,
                        'q' => $q ?: null,
                        'target_type' => $targetFilter ?: null,
                        'modality' => $modalityFilter ?: null,
                        'occurrence_id' => $highlightId ?: null,
                        'p' => $page,
                    ], fn($value) => $value !== null && $value !== '');
                    ?>
                    <a class="btn btn-sm <?= $pageNumber === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="index.php?<?= e(http_build_query($query)) ?>"><?= $page ?></a>
                <?php endfor; ?>
            </div>
        </nav>
    <?php endif; ?>
<?php endif; ?>
