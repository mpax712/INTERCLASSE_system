<?php
require_roles(['admin', 'professor']);
ensure_interclasse_schema_updates();

$eventId = (int) ($_GET['event_id'] ?? 0);
$modality = $_GET['modality'] ?? 'futsal';
$isMixedModality = is_mixed_modality($modality);
$usesGrouping = uses_grouping($modality);
$gender = $isMixedModality ? 'masculino' : ($_GET['gender'] ?? 'masculino');

if (!$isMixedModality && !in_array($gender, ['masculino', 'feminino'], true)) {
    $gender = 'masculino';
}

if (!$eventId || !can_manage_event_structure($eventId)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Acesso negado.</div>';
    return;
}

$eventStmt = db()->prepare('SELECT * FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();

if (!$event) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Evento não encontrado.</div>';
    return;
}

$registeredModalityStmt = db()->prepare('SELECT 1 FROM event_modalities WHERE event_id = ? AND modality = ?');
$registeredModalityStmt->execute([$eventId, $modality]);
if (!$registeredModalityStmt->fetchColumn()) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Modalidade não cadastrada neste evento.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(has_role(['admin'])) { http_response_code(428); echo '<div class="alert alert-warning">Confira os impactos antes de confirmar.</div>'; return; }
    verify_csrf();
    $action = $_POST['action'] ?? 'single';
    $gender = $isMixedModality ? 'masculino' : ($_POST['gender'] ?? 'masculino');

    if ($action === 'update_representative') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $representativeId = !empty($_POST['representative_id']) ? (int) $_POST['representative_id'] : null;
        $representativeName = trim($_POST['representative_name'] ?? '');

        $teamStmt = db()->prepare('SELECT id FROM teams WHERE id = ? AND event_id = ? AND modality = ?');
        $teamStmt->execute([$teamId, $eventId, $modality]);

        if ($teamStmt->fetch()) {
            $stmt = db()->prepare('UPDATE teams SET representative_id = ?, representative_name = ? WHERE id = ?');
            $stmt->execute([$representativeId, $representativeName !== '' ? $representativeName : null, $teamId]);
            flash('Representante atualizado.');
        }

        redirect('index.php?page=teams&event_id=' . $eventId . '&modality=' . $modality . '&gender=' . $gender);
    }

    if (game_is_new_modality($modality)) {
        $generatedStmt = db()->prepare('SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ?');
        $generatedStmt->execute([$eventId, $modality]);
        if ((int) $generatedStmt->fetchColumn() > 0) {
            flash('As turmas desta modalidade não podem mudar após a geração dos confrontos.', 'warning');
            redirect('index.php?page=teams&event_id=' . $eventId . '&modality=' . $modality);
        }
    }

    if ($event['status'] === 'encerrado') {
        flash('Evento encerrado. Não é possível cadastrar novos times.', 'warning');
        redirect('index.php?page=teams&event_id=' . $eventId . '&modality=' . $modality);
    }

    if ($action === 'bulk') {
        // Adição em massa
        $classIds = $_POST['class_ids'] ?? [];
        $created = 0;

        foreach ($classIds as $classId) {
            $classId = (int) $classId;
            if ($classId) {
                $classStmt = db()->prepare('SELECT name FROM classes WHERE id = ?');
                $classStmt->execute([$classId]);
                $classRow = $classStmt->fetch();

                if ($classRow) {
                    $duplicateStmt = db()->prepare('SELECT id FROM teams WHERE event_id = ? AND modality = ? AND gender = ? AND class_name = ? LIMIT 1');
                    $duplicateStmt->execute([$eventId, $modality, $gender, $classRow['name']]);
                    if ($duplicateStmt->fetch()) {
                        continue;
                    }
                    $groupName = $usesGrouping ? auto_assign_group_name($eventId, $modality, $gender) : '';
                    $stmt = db()->prepare('INSERT INTO teams (event_id, modality, gender, name, class_name, group_name, representative_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$eventId, $modality, $gender, $classRow['name'], $classRow['name'], $groupName, null]);
                    $created++;
                }
            }
        }

        if ($usesGrouping) {
            assign_team_groups($eventId, $modality, $gender);
        }
        flash($created . ' time(s) cadastrado(s) em massa.');
        redirect('index.php?page=teams&event_id=' . $eventId . '&modality=' . $modality);
    }

    // Adição individual
    $classId = (int) ($_POST['class_id'] ?? 0);
    $representativeId = $_POST['representative_id'] ? (int) $_POST['representative_id'] : null;
    $representativeName = trim($_POST['representative_name'] ?? '');

    if ($classId) {
        $classStmt = db()->prepare('SELECT name FROM classes WHERE id = ?');
        $classStmt->execute([$classId]);
        $classRow = $classStmt->fetch();

        if ($classRow) {
            $duplicateStmt = db()->prepare('SELECT id FROM teams WHERE event_id = ? AND modality = ? AND gender = ? AND class_name = ? LIMIT 1');
            $duplicateStmt->execute([$eventId, $modality, $gender, $classRow['name']]);
            if ($duplicateStmt->fetch()) {
                flash('Esta turma já está cadastrada nesta modalidade e gênero.', 'warning');
                redirect('index.php?page=teams&event_id=' . $eventId . '&modality=' . $modality . '&gender=' . $gender);
            }
            $groupName = $usesGrouping ? auto_assign_group_name($eventId, $modality, $gender) : '';
            $stmt = db()->prepare('INSERT INTO teams (event_id, modality, gender, name, class_name, group_name, representative_id, representative_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$eventId, $modality, $gender, $classRow['name'], $classRow['name'], $groupName, $representativeId, $representativeName !== '' ? $representativeName : null]);
            if ($usesGrouping) {
                assign_team_groups($eventId, $modality, $gender);
            }
            flash('Time cadastrado.');
        }
    }

    redirect('index.php?page=teams&event_id=' . $eventId . '&modality=' . $modality);
}

$classes = db()->query('SELECT id, name FROM classes ORDER BY name')->fetchAll();
if ($isMixedModality) {
    $representativesStmt = db()->prepare('SELECT id, name, email FROM users WHERE role = "representante" ORDER BY name');
    $representativesStmt->execute([]);
} else {
    $representativesStmt = db()->prepare('SELECT id, name, email FROM users WHERE role = "representante" AND representative_gender = ? ORDER BY name');
    $representativesStmt->execute([$gender]);
}
$representatives = $representativesStmt->fetchAll();
$playerLimit = player_limit_for_event_modality($eventId, $modality);
$teamsStmt = db()->prepare(
    'SELECT t.*, t.representative_name AS manual_representative_name, u.name AS representative_user_name, (SELECT COUNT(*) FROM players p WHERE p.team_id=t.id) AS player_count
     FROM teams t
     LEFT JOIN users u ON u.id = t.representative_id
     WHERE t.event_id = ? AND t.modality = ?
     ORDER BY t.group_name, t.name'
);
$teamsStmt->execute([$eventId, $modality]);
$teams = $teamsStmt->fetchAll();
$registeredClassNames = [];
foreach ($teams as $team) {
    if (($team['gender'] ?? 'masculino') === $gender && $team['class_name']) {
        $registeredClassNames[$team['class_name']] = true;
    }
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1"><?= game_is_new_modality($modality) ? 'Turmas' : 'Times' ?> · <?= e(modality_label($modality)) ?></h1>
        <p class="text-muted mb-0"><?= e($event['name']) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="index.php?page=event&id=<?= $eventId ?>&modality=<?= e($modality) ?>">Voltar ao evento</a>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><?= game_is_new_modality($modality) ? 'Cadastrar turma' : 'Cadastrar time (individual)' ?></div>
            <div class="card-body">
                <?php if ($event['status'] === 'encerrado' && !has_role(['admin'])): ?>
                    <div class="alert alert-warning mb-0">Evento encerrado. Não é possível cadastrar novos times.</div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="single">
                        <?php if ($isMixedModality): ?>
                            <input type="hidden" name="gender" value="masculino">
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Gênero</label>
                                <select class="form-select" name="gender" required>
                                    <option value="masculino">Masculino</option>
                                    <option value="feminino">Feminino</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Turma</label>
                            <select class="form-select" name="class_id" required>
                                <option value="">Selecione uma turma</option>
                                <?php foreach ($classes as $class): ?>
                                    <?php $alreadyRegistered = isset($registeredClassNames[$class['name']]); ?>
                                    <option value="<?= (int) $class['id'] ?>" <?= $alreadyRegistered ? 'disabled' : '' ?>>
                                        <?= e($class['name']) ?><?= $alreadyRegistered ? ' · já cadastrada' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Representante cadastrado</label>
                            <select class="form-select" name="representative_id">
                                <option value="">Sem representante</option>
                                <?php foreach ($representatives as $representative): ?>
                                    <option value="<?= (int) $representative['id'] ?>"><?= e($representative['name']) ?> · <?= e($representative['email']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nome do representante</label>
                            <input class="form-control" name="representative_name" maxlength="160" placeholder="Opcional, caso não tenha usuário cadastrado">
                        </div>
                        <button class="btn btn-primary" type="submit">Salvar time</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><?= game_is_new_modality($modality) ? 'Cadastrar turmas em massa' : 'Cadastrar times em massa' ?></div>
            <div class="card-body">
                <?php if ($event['status'] === 'encerrado' && !has_role(['admin'])): ?>
                    <div class="alert alert-warning mb-0">Evento encerrado. Não é possível cadastrar novos times.</div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="bulk">
                        <?php if ($isMixedModality): ?>
                            <input type="hidden" name="gender" value="masculino">
                        <?php else: ?>
                            <div class="mb-3">
                                <label class="form-label">Gênero</label>
                                <select class="form-select" name="gender" required>
                                    <option value="masculino">Masculino</option>
                                    <option value="feminino">Feminino</option>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Turmas (selecione múltiplas)</label>
                            <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                                <?php foreach ($classes as $class): ?>
                                    <?php $alreadyRegistered = isset($registeredClassNames[$class['name']]); ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="class_ids[]" value="<?= (int) $class['id'] ?>" id="class_<?= (int) $class['id'] ?>" <?= $alreadyRegistered ? 'disabled' : '' ?>>
                                        <label class="form-check-label" for="class_<?= (int) $class['id'] ?>">
                                            <?= e($class['name']) ?><?= $alreadyRegistered ? ' · já cadastrada' : '' ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button class="btn btn-success" type="submit"><?= game_is_new_modality($modality) ? 'Cadastrar turmas selecionadas' : 'Cadastrar times selecionados' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><?= game_is_new_modality($modality) ? 'Turmas cadastradas' : 'Times cadastrados' ?></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 responsive-table">
                    <thead><tr><?php if ($usesGrouping): ?><th>Grupo</th><?php endif; ?><th>Time</th><th>Turma</th><th>Representante</th><th>Jogadores</th></tr></thead>
                    <tbody>
                    <?php foreach ($teams as $team): ?>
                        <?php $isOverLimit = (int) ($team['player_count'] ?? 0) > $playerLimit; ?>
                        <tr class="<?= $isOverLimit ? 'table-danger' : '' ?>">
                            <?php if ($usesGrouping): ?><td data-label="Grupo"><?= e($team['group_name']) ?></td><?php endif; ?>
                            <td data-label="Time"><?= e($team['name']) ?></td>
                            <td data-label="Turma"><?= e($team['class_name']) ?></td>
                            <td data-label="Representante">
                                <form method="post" class="d-flex flex-column gap-1">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="update_representative">
                                    <input type="hidden" name="team_id" value="<?= (int) $team['id'] ?>">
                                    <select class="form-select form-select-sm" name="representative_id">
                                        <option value="">Sem usuário</option>
                                        <?php foreach ($representatives as $representative): ?>
                                            <option value="<?= (int) $representative['id'] ?>" <?= (int) ($team['representative_id'] ?? 0) === (int) $representative['id'] ? 'selected' : '' ?>>
                                                <?= e($representative['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input class="form-control form-control-sm" name="representative_name" maxlength="160" value="<?= e($team['manual_representative_name'] ?? '') ?>" placeholder="Nome livre">
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="small text-muted"><?= e(team_representative_label($team)) ?></span>
                                        <button class="btn btn-sm btn-outline-secondary">Salvar</button>
                                    </div>
                                </form>
                            </td>
                            <td data-label="Jogadores">
                                <div class="d-flex flex-column gap-1">
                                    <span class="<?= $isOverLimit ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                        <?= (int) ($team['player_count'] ?? 0) ?>/<?= (int) $playerLimit ?>
                                    </span>
                                    <a class="btn btn-sm btn-outline-primary" href="index.php?page=players&team_id=<?= (int) $team['id'] ?>">Gerenciar</a>
                                    <?php if(has_role(['admin'])): ?><button type="button" class="btn btn-sm btn-outline-secondary" data-admin-edit="teams" data-id="<?= (int) $team['id'] ?>">Editar equipe</button><button type="button" class="btn btn-sm btn-outline-danger" data-admin-op="archive" data-admin-entity="teams" data-id="<?= (int) $team['id'] ?>">Arquivar inscrição</button><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$teams): ?><tr><td colspan="<?= $usesGrouping ? 5 : 4 ?>" class="text-muted">Nenhum time.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
