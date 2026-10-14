<?php
require_roles(['admin']);
ensure_interclasse_schema_updates();

$errors = [];
$classes = db()->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
$allModalities = allowed_modalities();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_import') {
        $bulkData = trim($_POST['bulk_data'] ?? '');
        $defaultPassword = trim($_POST['default_password'] ?? '');
        $defaultRole = $_POST['default_role'] ?? 'representante';
        
        if (!$bulkData) {
            $errors[] = 'Informe os dados para importação.';
        }
        
        if (strlen($defaultPassword) < 6) {
            $errors[] = 'A senha padrão deve ter pelo menos 6 caracteres.';
        }
        
        if (!in_array($defaultRole, ['admin', 'professor', 'representante'], true)) {
            $errors[] = 'Perfil padrão inválido.';
        }
        
        if (!$errors) {
            $lines = array_filter(array_map('trim', explode("\n", $bulkData)));
            $imported = 0;
            $skipped = 0;
            
            foreach ($lines as $line) {
                // Parse line: name,email,class_name,representative_gender
                $parts = array_map('trim', explode(',', $line));
                $name = $parts[0] ?? '';
                $email = $parts[1] ?? '';
                $className = $parts[2] ?? null;
                $representativeGender = $parts[3] ?? null;

                if (strlen($name) < 3 || !validate_email($email)) {
                    $skipped++;
                    continue;
                }

                // Check if email already exists
                $checkStmt = db()->prepare('SELECT id FROM users WHERE email = ?');
                $checkStmt->execute([$email]);
                if ($checkStmt->fetch()) {
                    $skipped++;
                    continue;
                }

                // Get class ID if class name provided
                $classId = null;
                if ($className && $defaultRole === 'representante') {
                    $classStmt = db()->prepare('SELECT id FROM classes WHERE name = ?');
                    $classStmt->execute([$className]);
                    $class = $classStmt->fetch();
                    $classId = $class ? (int) $class['id'] : null;
                }

                // Validate representative fields
                if ($defaultRole === 'representante') {
                    if (!$classId) {
                        $skipped++;
                        continue;
                    }
                    if (!in_array($representativeGender, ['masculino', 'feminino'], true)) {
                        $representativeGender = null;
                    }
                } else {
                    $classId = null;
                    $representativeGender = null;
                }

                try {
                    $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, class_id, representative_gender) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$name, $email, password_hash($defaultPassword, PASSWORD_DEFAULT), $defaultRole, $classId, $representativeGender]);
                    $newUserId = (int) db()->lastInsertId();
                    if ($defaultRole === 'professor') {
                        save_user_modalities($newUserId, $allModalities);
                    }
                    $imported++;
                } catch (PDOException $exception) {
                    $skipped++;
                }
            }
            
            if ($imported > 0) {
                flash("{$imported} usuários importados com sucesso." . ($skipped > 0 ? " {$skipped} ignorados." : ''));
            } else {
                flash('Nenhum usuário importado. Verifique os dados.', 'warning');
            }
            redirect('index.php?page=users');
        }
    }

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'representante';
        $classId = $_POST['class_id'] ? (int) $_POST['class_id'] : null;
        $representativeGender = $_POST['representative_gender'] ?? null;
        $modalities = normalize_modalities($_POST['modalities'] ?? []);

        if (strlen($name) < 3 || !validate_email($email) || strlen($password) < 6 || !in_array($role, ['admin', 'professor', 'representante'], true)) {
            $errors[] = 'Preencha nome, e-mail, senha e perfil corretamente.';
        }

        if ($role === 'representante') {
            if (!$classId) {
                $errors[] = 'Selecione a turma do representante.';
            }
            if (!in_array($representativeGender, ['masculino', 'feminino'], true)) {
                $errors[] = 'Selecione o gênero (masculino ou feminino) que o representante irá representar.';
            }
        } else {
            // Se não for representante, zerar esses campos
            $classId = null;
            $representativeGender = null;
        }

        if ($role === 'professor' && !$modalities) {
            $errors[] = 'Selecione ao menos uma modalidade para o mesario.';
        }

        // Verificar se email já existe
        if (!$errors) {
            $checkStmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $checkStmt->execute([$email]);
            if ($checkStmt->fetch()) {
                $errors[] = 'Este e-mail já está cadastrado no sistema.';
            }
        }

        if (!$errors) {
            try {
                $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, class_id, representative_gender) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $classId, $representativeGender]);
                flash('Usuário criado.');
                $newUserId = (int) db()->lastInsertId();
                save_user_modalities($newUserId, $role === 'professor' ? $modalities : []);
                redirect('index.php?page=users');
            } catch (PDOException $exception) {
                $errorCode = $exception->getCode();
                $errorMessage = $exception->getMessage();
                
                error_log("Erro ao criar usuário - Código: $errorCode, Mensagem: $errorMessage");
                error_log("Dados: name=$name, email=$email, role=$role, class_id=$classId, gender=$representativeGender");
                
                // Mensagens de erro mais específicas
                if (strpos($errorMessage, 'FOREIGN KEY') !== false) {
                    $errors[] = 'Turma inválida ou não encontrada. Selecione uma turma válida.';
                } elseif (strpos($errorMessage, 'Duplicate') !== false || strpos($errorMessage, '1062') !== false) {
                    $errors[] = 'Este e-mail já está cadastrado no sistema.';
                } else {
                    $errors[] = 'Erro ao criar usuário: ' . htmlspecialchars($errorMessage);
                }
            }
        }
    }

    if ($action === 'role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? 'representante';
        $modalities = normalize_modalities($_POST['modalities'] ?? []);

        if (in_array($role, ['admin', 'professor', 'representante'], true)) {
            if ($role === 'professor' && !$modalities) {
                $errors[] = 'Selecione ao menos uma modalidade para o mesario.';
            } else {
            $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->execute([$role, $userId]);
                save_user_modalities($userId, $role === 'professor' ? $modalities : []);
            flash('Perfil atualizado.');
            redirect('index.php?page=users');
            }
        }
    }
}

$perPage = 50;
$currentPage = max(1, (int) ($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;
$totalUsers = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPages = max(1, (int) ceil($totalUsers / $perPage));
$usersStmt = db()->prepare('SELECT u.id, u.name, u.email, u.role, u.created_at, c.name AS class_name, u.representative_gender
    FROM users u
    LEFT JOIN classes c ON c.id = u.class_id
    ORDER BY u.name
    LIMIT ? OFFSET ?');
$usersStmt->bindValue(1, $perPage, PDO::PARAM_INT);
$usersStmt->bindValue(2, $offset, PDO::PARAM_INT);
$usersStmt->execute();
$users = $usersStmt->fetchAll();
$modalitiesByUser = [];
if ($users) {
    $userIds = array_map(static fn(array $item): int => (int) $item['id'], $users);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $modalitiesStmt = db()->prepare("SELECT user_id, modality FROM user_modalities WHERE user_id IN ($placeholders) ORDER BY modality");
    $modalitiesStmt->execute($userIds);
    foreach ($modalitiesStmt->fetchAll() as $row) {
        $modalitiesByUser[(int) $row['user_id']][] = $row['modality'];
    }
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Usuários</h1>
        <p class="text-muted mb-0">Administre perfis de administrador, mesário e representante.</p>
    </div>
</div>

<div class="mb-4">
    <ul class="nav nav-tabs">
        <li class="nav-item"><a class="nav-link active" href="index.php?page=users">Usuários</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?page=classes">Turmas</a></li>
    </ul>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-3 align-items-start users-admin-forms">
    <div class="col-xl-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Novo usuário</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-mail</label>
                        <input class="form-control" type="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Senha inicial</label>
                        <input class="form-control" type="password" name="password" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Perfil</label>
                        <select class="form-select" name="role">
                            <option value="representante">Representante</option>
                            <option value="professor">Mesário</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Turma do representante</label>
                        <select class="form-select" name="class_id">
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= (int) $class['id'] ?>" <?= (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : '' ?>><?= e($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Gênero do representante</label>
                        <select class="form-select" name="representative_gender">
                            <option value="">Selecione o gênero</option>
                            <option value="masculino" <?= ($_POST['representative_gender'] ?? '') === 'masculino' ? 'selected' : '' ?>>Masculino</option>
                            <option value="feminino" <?= ($_POST['representative_gender'] ?? '') === 'feminino' ? 'selected' : '' ?>>Feminino</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Modalidades do mesario</label>
                        <div class="row g-2">
                            <?php foreach ($allModalities as $modality): ?>
                                <label class="col-6 small">
                                    <input class="form-check-input me-1" type="checkbox" name="modalities[]" value="<?= e($modality) ?>" <?= in_array($modality, $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                                    <?= e(modality_label($modality)) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit">Criar usuário</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Importação em massa</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="bulk_import">
                    <div class="mb-3">
                        <label class="form-label">Dados dos usuários (CSV)</label>
                        <textarea class="form-control" name="bulk_data" rows="5" placeholder="João Silva,joao@email.com,1º Ano A,masculino&#10;Maria Santos,maria@email.com,1º Ano B,feminino"></textarea>
                        <small class="text-muted">Formato: nome,email,turma,gênero (um por linha)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Senha padrão</label>
                        <input class="form-control" type="password" name="default_password" minlength="6" required>
                        <small class="text-muted">Todos os usuários importados terão esta senha inicial.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Perfil padrão</label>
                        <select class="form-select" name="default_role">
                            <option value="representante">Representante</option>
                            <option value="professor">Mesário</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                    <button class="btn btn-success" type="submit">Importar usuários</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card shadow-sm users-list-card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>Usuários cadastrados <small class="text-muted">(<?= $totalUsers ?>)</small></span>
                <button class="btn btn-sm btn-danger bulk-delete-btn" style="display: none;">Excluir selecionados</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 responsive-table users-table">
                    <thead>
                        <tr>
                            <th class="text-center users-select-col"><input type="checkbox" id="select-all-users"></th>
                            <th>Usuário</th>
                            <th>Perfil e modalidades</th>
                            <th>Turma</th>
                            <th>Criado em</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $item): ?>
                        <?php $selectedUserModalities = $modalitiesByUser[(int) $item['id']] ?? []; ?>
                        <tr data-user-id="<?= (int) $item['id'] ?>">
                            <td class="text-center users-select-col" data-label="Selecionar"><input type="checkbox" class="user-checkbox" value="<?= (int) $item['id'] ?>"></td>
                            <td data-label="Usuário">
                                <div class="users-identity">
                                    <strong><?= e($item['name']) ?></strong>
                                    <span><?= e($item['email']) ?></span>
                                </div>
                            </td>
                            <td data-label="Perfil">
                                <div class="users-role-stack">
                                    <span class="badge text-bg-light"><?= e(role_label($item['role'])) ?></span>
                                    <div class="users-modality-badges">
                                        <?php foreach ($selectedUserModalities as $modality): ?>
                                            <span><?= e(modality_label($modality)) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (!$selectedUserModalities): ?>
                                            <small class="text-muted">Sem modalidades vinculadas</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Turma">
                                <div class="users-class-stack">
                                    <span><?= e($item['class_name'] ?? 'Sem turma') ?></span>
                                    <small><?= $item['representative_gender'] ? e(ucfirst($item['representative_gender'])) : 'Sem gênero' ?></small>
                                </div>
                            </td>
                            <td data-label="Criado em"><?= e(format_datetime($item['created_at'])) ?></td>
                            <td data-label="Ações" class="text-end">
                                <form method="post" class="users-inline-edit">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="user_id" value="<?= (int) $item['id'] ?>">
                                    <label>
                                        <span>Perfil</span>
                                        <select class="form-select form-select-sm" name="role">
                                            <?php foreach (['admin', 'professor', 'representante'] as $role): ?>
                                                <option value="<?= e($role) ?>" <?= $item['role'] === $role ? 'selected' : '' ?>><?= e(role_label($role)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>
                                        <span>Modalidades</span>
                                        <select class="form-select form-select-sm" name="modalities[]" multiple size="4" aria-label="Modalidades do mesario">
                                            <?php foreach ($allModalities as $modality): ?>
                                                <option value="<?= e($modality) ?>" <?= in_array($modality, $selectedUserModalities, true) ? 'selected' : '' ?>><?= e(modality_label($modality)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button class="btn btn-sm btn-outline-primary">Salvar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="small text-muted">Página <?= $currentPage ?> de <?= $totalPages ?></span>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary <?= $currentPage <= 1 ? 'disabled' : '' ?>" href="index.php?page=users&p=<?= max(1, $currentPage - 1) ?>">Anterior</a>
                        <a class="btn btn-outline-secondary <?= $currentPage >= $totalPages ? 'disabled' : '' ?>" href="index.php?page=users&p=<?= min($totalPages, $currentPage + 1) ?>">Próxima</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;

// Select all checkboxes
document.getElementById('select-all-users').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.user-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateBulkDeleteButton();
});

// Update bulk delete button visibility
document.querySelectorAll('.user-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkDeleteButton);
});

function updateBulkDeleteButton() {
    const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
    const bulkDeleteBtn = document.querySelector('.bulk-delete-btn');
    bulkDeleteBtn.style.display = checkedCount > 0 ? 'block' : 'none';
    bulkDeleteBtn.textContent = `Excluir selecionados (${checkedCount})`;
}

// Bulk delete functionality
document.querySelector('.bulk-delete-btn').addEventListener('click', async function() {
    const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
    const userIds = Array.from(checkedBoxes).map(cb => cb.value);
    
    if (userIds.length === 0) {
        return;
    }
    
    if (await askConfirmation({title: 'Excluir usuários', message: `Excluir ${userIds.length} usuários selecionados? Esta ação não pode ser desfeita.`, confirmText: 'Excluir', variant: 'btn-danger'})) {
        fetch('api/users.php', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify({
                user_ids: userIds,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                location.reload();
            } else {
                askConfirmation({title: 'Erro', message: data.error || 'Erro ao excluir usuários', confirmText: 'Entendi', variant: 'btn-danger'});
            }
        })
        .catch(error => {
            askConfirmation({title: 'Erro', message: 'Erro ao excluir usuários', confirmText: 'Entendi', variant: 'btn-danger'});
        });
    }
});
</script>
