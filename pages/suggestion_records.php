<?php
require_roles(['admin']);
ensure_suggestions_schema();

$statuses = ['nova', 'em_analise', 'resolvida', 'arquivada'];
$types = ['bug', 'melhoria', 'outro'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? 'update';
    $suggestionId = (int) ($_POST['suggestion_id'] ?? 0);
    $status = $_POST['status'] ?? 'nova';
    $adminNote = trim((string) ($_POST['admin_note'] ?? ''));

    if ($action === 'delete' && $suggestionId) {
        $stmt = db()->prepare('DELETE FROM suggestions WHERE id = ?');
        $stmt->execute([$suggestionId]);
        flash('Sugestão excluída.');
    } elseif ($suggestionId && in_array($status, $statuses, true)) {
        $stmt = db()->prepare('UPDATE suggestions SET status = ?, admin_note = ? WHERE id = ?');
        $stmt->execute([$status, $adminNote ?: null, $suggestionId]);
        flash('Sugestão atualizada.');
    }

    redirect('index.php?page=suggestion_records');
}

$filter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$q = trim((string) ($_GET['q'] ?? ''));
$pageNumber = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 10;
$params = [];
$whereParts = [];
if (in_array($filter, $statuses, true)) {
    $whereParts[] = 's.status = ?';
    $params[] = $filter;
}
if (in_array($typeFilter, $types, true)) {
    $whereParts[] = 's.type = ?';
    $params[] = $typeFilter;
}
if ($q !== '') {
    $whereParts[] = '(s.title LIKE ? OR s.description LIKE ? OR s.admin_note LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$countStmt = db()->prepare(
    "SELECT COUNT(*) AS total
     FROM suggestions s
     LEFT JOIN users u ON u.id = s.user_id
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
    "SELECT s.*, u.name AS user_name, u.email AS user_email
     FROM suggestions s
     LEFT JOIN users u ON u.id = s.user_id
     $where
     ORDER BY s.created_at DESC"
     . " LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$records = $stmt->fetchAll();

function suggestion_status_label(string $status): string
{
    return [
        'nova' => 'Nova',
        'em_analise' => 'Em análise',
        'resolvida' => 'Resolvida',
        'arquivada' => 'Arquivada',
    ][$status] ?? ucfirst($status);
}

function suggestion_type_label(string $type): string
{
    return [
        'bug' => 'Bug',
        'melhoria' => 'Melhoria',
        'outro' => 'Outro',
    ][$type] ?? ucfirst($type);
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Sugestões recebidas</h1>
        <p class="text-muted mb-0">Área restrita ao administrador.</p>
    </div>
</div>

<form class="row g-2 mb-3 filter-card filter-card-inline compact-filter-form" method="get">
    <input type="hidden" name="page" value="suggestion_records">
    <div class="col-lg-4">
        <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Pesquisar por título, descrição ou usuário">
    </div>
    <div class="col-sm-6 col-lg-3">
        <select class="form-select" name="status" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= e($status) ?>" <?= $filter === $status ? 'selected' : '' ?>><?= e(suggestion_status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-6 col-lg-3">
        <select class="form-select" name="type">
            <option value="">Todos os tipos</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= e($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>><?= e(suggestion_type_label($type)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-2"><button class="btn btn-primary w-100">Filtrar</button></div>
    <div class="col-lg-2"><a class="btn btn-outline-secondary w-100" href="index.php?page=suggestion_records">Limpar filtros</a></div>
</form>

<p class="text-muted small mb-3">
    <?= (int) $totalRecords ?> <?= $totalRecords === 1 ? 'sugestão encontrada' : 'sugestões encontradas' ?>.
</p>

<?php if (!$records): ?>
    <div class="alert alert-info">Nenhuma sugestão encontrada.</div>
<?php else: ?>
    <div class="vstack gap-3 suggestion-record-list">
        <?php foreach ($records as $record): ?>
            <article class="card shadow-sm suggestion-record-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-3 flex-wrap mb-2">
                        <div>
                            <h2 class="h5 mb-1"><?= e($record['title']) ?></h2>
                            <p class="text-muted mb-0">
                                <?= e($record['user_name'] ?? 'Usuário removido') ?>
                                <?php if ($record['user_email']): ?> · <?= e($record['user_email']) ?><?php endif; ?>
                                · <?= e(format_datetime($record['created_at'])) ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge text-bg-light"><?= e(suggestion_type_label($record['type'])) ?></span>
                            <span class="badge text-bg-light"><?= e(suggestion_status_label($record['status'])) ?></span>
                        </div>
                    </div>
                    <p class="mb-3"><?= nl2br(e($record['description'])) ?></p>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="suggestion_id" value="<?= (int) $record['id'] ?>">
                        <input type="hidden" name="action" value="update">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= e($status) ?>" <?= $record['status'] === $status ? 'selected' : '' ?>><?= e(suggestion_status_label($status)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Observação administrativa</label>
                            <input class="form-control" name="admin_note" value="<?= e($record['admin_note'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-outline-primary w-100">Salvar</button>
                        </div>
                    </form>
                    <form method="post" class="mt-2 text-end" onsubmit="return confirmAction('Excluir esta sugestão permanentemente?')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="suggestion_id" value="<?= (int) $record['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-outline-danger btn-sm">Excluir sugestão</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Paginação das sugestões">
            <div class="d-flex gap-2 flex-wrap justify-content-center">
                <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                    <?php
                    $query = array_filter([
                        'page' => 'suggestion_records',
                        'q' => $q ?: null,
                        'status' => $filter ?: null,
                        'type' => $typeFilter ?: null,
                        'p' => $page,
                    ], fn($value) => $value !== null && $value !== '');
                    ?>
                    <a class="btn btn-sm <?= $pageNumber === $page ? 'btn-primary' : 'btn-outline-secondary' ?>" href="index.php?<?= e(http_build_query($query)) ?>"><?= $page ?></a>
                <?php endfor; ?>
            </div>
        </nav>
    <?php endif; ?>
<?php endif; ?>
