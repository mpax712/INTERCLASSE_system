<?php
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'bulk_import') {
        $bulkNames = trim($_POST['bulk_names'] ?? '');
        if ($bulkNames) {
            $names = array_filter(array_map('trim', explode("\n", $bulkNames)));
            $imported = 0;
            $duplicates = 0;
            
            foreach ($names as $name) {
                if (strlen($name) < 2) {
                    continue;
                }
                
                try {
                    $stmt = db()->prepare('INSERT INTO classes (name) VALUES (?)');
                    $stmt->execute([$name]);
                    $imported++;
                } catch (PDOException $exception) {
                    $duplicates++;
                }
            }
            
            if ($imported > 0) {
                flash("{$imported} turmas importadas com sucesso." . ($duplicates > 0 ? " {$duplicates} duplicadas ignoradas." : ''));
            } else {
                flash('Nenhuma turma importada. Verifique os nomes.', 'warning');
            }
            redirect('index.php?page=classes');
        }
    }

    if ($action === 'delete') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        
        // Check if class is being used by any users or teams
        $userCheck = db()->prepare('SELECT COUNT(*) FROM users WHERE class_id = ?');
        $userCheck->execute([$classId]);
        $userCount = (int) $userCheck->fetchColumn();

        $teamCheck = db()->prepare('SELECT COUNT(*) FROM teams WHERE class_name = (SELECT name FROM classes WHERE id = ?)');
        $teamCheck->execute([$classId]);
        $teamCount = (int) $teamCheck->fetchColumn();

        if ($userCount > 0 || $teamCount > 0) {
            $errors[] = 'Não é possível excluir esta turma pois está sendo utilizada por usuários ou times.';
        } else {
            $stmt = db()->prepare('DELETE FROM classes WHERE id = ?');
            $stmt->execute([$classId]);
            flash('Turma excluída.');
            redirect('index.php?page=classes');
        }
    } elseif ($action === 'edit') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');

        if (strlen($name) < 2) {
            $errors[] = 'Informe o nome da turma.';
        } else {
            // Check if name already exists for another class
            $checkStmt = db()->prepare('SELECT id FROM classes WHERE name = ? AND id != ?');
            $checkStmt->execute([$name, $classId]);
            if ($checkStmt->fetch()) {
                $errors[] = 'Já existe uma turma com este nome.';
            } else {
                $stmt = db()->prepare('UPDATE classes SET name = ? WHERE id = ?');
                $stmt->execute([$name, $classId]);
                flash('Turma atualizada.');
                redirect('index.php?page=classes');
            }
        }
    } else {
        $name = trim($_POST['name'] ?? '');

        if (strlen($name) < 2) {
            $errors[] = 'Informe o nome da turma.';
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO classes (name) VALUES (?)');
                $stmt->execute([$name]);
                flash('Turma cadastrada.');
                redirect('index.php?page=classes');
            } catch (PDOException $exception) {
                $errors[] = 'Já existe uma turma com este nome.';
            }
        }
    }
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
                                    <button class="btn btn-sm btn-outline-danger">Excluir</button>
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

<script>
document.querySelectorAll('.class-delete-form').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const confirmed = await askConfirmation({
            title: 'Excluir turma',
            message: 'Excluir esta turma? A ação só será permitida se não houver usuários ou times vinculados.',
            confirmText: 'Excluir',
            variant: 'btn-danger'
        });
        if (confirmed) {
            form.submit();
        }
    });
});
</script>
