<?php
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(428); echo '<div class="alert alert-warning">Confira os impactos antes de confirmar.</div>'; return; }
require_roles(['admin']);

$errors = [];
$editClass = null;

// Handle edit request
if (isset($_GET['edit_id'])) {
    $editId = (int) $_GET['edit_id'];
    $stmt = db()->prepare('SELECT * FROM classes WHERE id = ?');
    $stmt->execute([$editId]);
    $editClass = $stmt->fetch();
}



$classes = db()->query('SELECT * FROM classes ORDER BY name')->fetchAll();
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Turmas</h1>
        <p class="text-muted mb-0">Cadastre turmas para usar na criação de times e na atribuição de representantes.</p>
    </div>
</div>

<div class="mb-4">
    <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link" href="index.php?page=users">Usuários</a></li>
        <li class="nav-item"><a class="nav-link active" href="index.php?page=classes">Turmas</a></li>
    </ul>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><?= $editClass ? 'Editar turma' : 'Nova turma' ?></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="<?= $editClass ? 'edit' : 'create' ?>">
                    <?php if ($editClass): ?>
                        <input type="hidden" name="class_id" value="<?= (int) $editClass['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Nome da turma</label>
                        <input class="form-control" name="name" value="<?= e($editClass['name'] ?? ($_POST['name'] ?? '')) ?>" required>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= $editClass ? 'Atualizar turma' : 'Salvar turma' ?></button>
                    <?php if ($editClass): ?>
                        <a class="btn btn-outline-secondary" href="index.php?page=classes">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
        <?php if (!$editClass): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Importação em massa</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="bulk_import">
                        <div class="mb-3">
                            <label class="form-label">Nomes das turmas (um por linha)</label>
                            <textarea class="form-control" name="bulk_names" rows="5" placeholder="1º Ano A&#10;1º Ano B&#10;2º Ano A"></textarea>
                            <small class="text-muted">Digite o nome de cada turma em uma linha separada.</small>
                        </div>
                        <button class="btn btn-success" type="submit">Importar turmas</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Turmas cadastradas</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Nome</th><th>Criada em</th><th>Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($classes as $class): ?>
                        <tr>
                            <td><?= e($class['name']) ?></td>
                            <td><?= e(format_datetime($class['created_at'])) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="index.php?page=classes&edit_id=<?= (int) $class['id'] ?>">Editar</a>
                                <form method="post" class="d-inline class-delete-form">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="class_id" value="<?= (int) $class['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Arquivar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$classes): ?><tr><td colspan="3" class="text-muted">Nenhuma turma cadastrada.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
