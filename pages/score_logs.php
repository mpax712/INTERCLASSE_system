<?php
require_roles(['admin']);
ensure_score_logs_schema();

$eventId = (int) ($_GET['event_id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$modalityFilter = trim((string) ($_GET['modality'] ?? ''));
$userFilter = (int) ($_GET['user_id'] ?? 0);
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 25;
$events = db()->query('SELECT id, name FROM events ORDER BY start_date DESC, created_at DESC')->fetchAll();

if (!$eventId && $events) {
    $eventId = (int) $events[0]['id'];
}

$records = [];
$totalRecords = 0;
$actions = [];
$users = [];
if ($eventId) {
    $where = 'WHERE m.event_id = ?';
    $params = [$eventId];

    $actionsStmt = db()->prepare(
        'SELECT DISTINCT sl.action
         FROM score_logs sl
         JOIN matches m ON m.id = sl.match_id
         WHERE m.event_id = ?
         ORDER BY sl.action'
    );
    $actionsStmt->execute([$eventId]);
    $actions = array_filter(array_column($actionsStmt->fetchAll(), 'action'));

    $usersStmt = db()->prepare(
        'SELECT DISTINCT u.id, u.name
         FROM score_logs sl
         JOIN matches m ON m.id = sl.match_id
         JOIN users u ON u.id = sl.user_id
         WHERE m.event_id = ?
         ORDER BY u.name'
    );
    $usersStmt->execute([$eventId]);
    $users = $usersStmt->fetchAll();

    if ($q !== '') {
        $where .= ' AND (
            e.name LIKE ? OR u.name LIKE ? OR ht.name LIKE ? OR at.name LIKE ?
            OR m.modality LIKE ? OR m.stage LIKE ? OR sl.action LIKE ? OR sl.description LIKE ?
        )';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($actionFilter !== '') {
        $where .= ' AND sl.action = ?';
        $params[] = $actionFilter;
    }

    if ($statusFilter !== '') {
        $where .= ' AND (sl.old_status = ? OR sl.new_status = ?)';
        array_push($params, $statusFilter, $statusFilter);
    }

    if ($modalityFilter !== '' && in_array($modalityFilter, allowed_modalities(), true)) {
        $where .= ' AND m.modality = ?';
        $params[] = $modalityFilter;
    }

    if ($userFilter > 0) {
        $where .= ' AND sl.user_id = ?';
        $params[] = $userFilter;
    }

    $countStmt = db()->prepare(
        "SELECT COUNT(*) AS total
         FROM score_logs sl
         JOIN matches m ON m.id = sl.match_id
         JOIN events e ON e.id = m.event_id
         LEFT JOIN users u ON u.id = sl.user_id
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
        "SELECT sl.*, u.name AS user_name, e.name AS event_name, m.modality, m.stage,
                ht.name AS home_name, at.name AS away_name
         FROM score_logs sl
         JOIN matches m ON m.id = sl.match_id
         JOIN events e ON e.id = m.event_id
         LEFT JOIN users u ON u.id = sl.user_id
         LEFT JOIN teams ht ON ht.id = m.home_team_id
         LEFT JOIN teams at ON at.id = m.away_team_id
         $where
         ORDER BY sl.created_at DESC, sl.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $records = $stmt->fetchAll();
}

$totalPages = max(1, (int) ceil($totalRecords / $perPage));

function score_log_action_label(string $action): string
{
    return [
        'assign_numbers' => 'Numeração',
        'start' => 'Início',
        'finish' => 'Encerramento',
        'adjust' => 'Alteração de placar',
        'save_cube_attempts' => 'Tentativas do Cubo Mágico',
        'volleyball_point' => 'Ponto de vôlei',
        'volleyball_set' => 'Set de vôlei',
        'wo' => 'W.O.',
        'set_winner' => 'Vencedor',
        'admin_correct_result' => 'Correção administrativa',
    ][$action] ?? ucfirst(str_replace('_', ' ', $action));
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Registros de placar</h1>
        <p class="text-muted mb-0">Histórico de alterações feitas nas partidas.</p>
    </div>
</div>

<form class="row g-2 mb-3 filter-card filter-card-inline compact-filter-form" method="get">
    <input type="hidden" name="page" value="score_logs">
    <div class="col-lg-4">
        <select class="form-select" name="event_id" onchange="this.form.submit()">
            <?php foreach ($events as $event): ?>
                <option value="<?= (int) $event['id'] ?>" <?= $eventId === (int) $event['id'] ? 'selected' : '' ?>><?= e($event['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-4">
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar por partida, usuário, ação ou modalidade">
    </div>
    <div class="col-sm-6 col-lg-2">
        <select class="form-select" name="action">
            <option value="">Todas as ações</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= e($action) ?>" <?= $actionFilter === $action ? 'selected' : '' ?>><?= e(score_log_action_label($action)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-6 col-lg-2">
        <select class="form-select" name="status">
            <option value="">Todos os status</option>
            <?php foreach (['agendada', 'em_andamento', 'encerrada', 'wo'] as $status): ?>
                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(match_status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-6 col-lg-3">
        <select class="form-select" name="modality">
            <option value="">Todas as modalidades</option>
            <?php foreach (allowed_modalities() as $modality): ?>
                <option value="<?= e($modality) ?>" <?= $modalityFilter === $modality ? 'selected' : '' ?>><?= e(modality_label($modality)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-6 col-lg-3">
        <select class="form-select" name="user_id">
            <option value="0">Todos os usuários</option>
            <?php foreach ($users as $item): ?>
                <option value="<?= (int) $item['id'] ?>" <?= $userFilter === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-2"><button class="btn btn-primary w-100">Filtrar</button></div>
    <div class="col-lg-2"><a class="btn btn-outline-secondary w-100" href="index.php?page=score_logs&event_id=<?= (int) $eventId ?>">Limpar filtros</a></div>
</form>

<?php if ($eventId): ?>
    <p class="text-muted small mb-3">
        <?= (int) $totalRecords ?> registro<?= $totalRecords === 1 ? '' : 's' ?> encontrado<?= $totalRecords === 1 ? '' : 's' ?>.
    </p>
<?php endif; ?>

<?php if (!$records): ?>
    <div class="alert alert-info">Nenhum registro de placar encontrado para este evento.</div>
<?php else: ?>
    <div class="card shadow-sm dense-data-card">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Partida</th>
                        <th>Usuário</th>
                        <th>Ação</th>
                        <th>Antes</th>
                        <th>Depois</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?= e(format_datetime($record['created_at'])) ?></td>
                            <td>
                                <strong><?= e($record['home_name'] ?? 'A definir') ?> x <?= e($record['away_name'] ?? 'A definir') ?></strong><br>
                                <span class="small text-muted"><?= e(modality_label($record['modality'])) ?> · <?= e(stage_label($record['stage'])) ?></span>
                            </td>
                            <td><?= e($record['user_name'] ?? 'Sistema') ?></td>
                            <td>
                                <strong><?= e(score_log_action_label($record['action'])) ?></strong><br>
                                <span class="small text-muted"><?= e($record['description'] ?: $record['action']) ?></span>
                            </td>
                            <td><?= (int) $record['old_home_score'] ?> x <?= (int) $record['old_away_score'] ?> · <?= e(match_status_label($record['old_status'] ?? 'agendada')) ?></td>
                            <td><?= (int) $record['new_home_score'] ?> x <?= (int) $record['new_away_score'] ?> · <?= e(match_status_label($record['new_status'] ?? 'agendada')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Paginação dos registros">
            <div class="d-flex gap-2 flex-wrap justify-content-center">
                <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                    <?php
                    $query = array_filter([
                        'page' => 'score_logs',
                        'event_id' => $eventId,
                        'q' => $q ?: null,
                        'action' => $actionFilter ?: null,
                        'status' => $statusFilter ?: null,
                        'modality' => $modalityFilter ?: null,
                        'user_id' => $userFilter ?: null,
                        'p' => $page,
                    ], fn($value) => $value !== null && $value !== '');
                    ?>
                    <a class="btn btn-sm <?= $pageNumber === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="index.php?<?= e(http_build_query($query)) ?>"><?= $page ?></a>
                <?php endfor; ?>
            </div>
        </nav>
    <?php endif; ?>
<?php endif; ?>
