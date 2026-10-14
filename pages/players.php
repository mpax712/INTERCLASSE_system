<?php
if (has_role(['admin'])) { $adminInitialTab = 'players'; require __DIR__ . '/admin_workspace.php'; return; }

require_login();

$teamId = (int) ($_GET['team_id'] ?? 0);
$teamStmt = db()->prepare('SELECT t.*, e.name AS event_name, e.status AS event_status, e.roster_edit_until FROM teams t JOIN events e ON e.id = t.event_id WHERE t.id = ?');
$teamStmt->execute([$teamId]);
$team = $teamStmt->fetch();

if (!$team) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Time não encontrado.</div>';
    return;
}

$user = current_user();
$isRepresentative = (int) $team['representative_id'] === (int) $user['id'];
$isEventManager = can_manage_event_structure((int) $team['event_id']);
$canManage = $isEventManager || $isRepresentative;
$rosterDeadlinePassed = $team['roster_edit_until'] && strtotime($team['roster_edit_until']) < time();
$rosterClosed = !in_array($team['event_status'], ['rascunho', 'inscricoes'], true) || $rosterDeadlinePassed;

// Prevent player registration when event is 'em_andamento' for all users
$eventInProgress = $team['event_status'] === 'em_andamento';


$playersStmt = db()->prepare('SELECT * FROM players WHERE team_id = ? ORDER BY name');
$playersStmt->execute([$teamId]);
$players = $playersStmt->fetchAll();
$playerCount = count($players);
$playerLimit = player_limit_for_team($team);
$teamIsFull = $playerCount >= $playerLimit;
$teamOverLimit = $playerCount > $playerLimit;
$canEditPlayers = $canManage && ($isEventManager || (!$eventInProgress && !$rosterClosed));
$formDisabled = !$canEditPlayers || $teamIsFull;
$isGameParticipant = game_is_new_modality((string) $team['modality']);
$gameParticipantCount = 0;
if ($isGameParticipant) {
    $participantCountStmt = db()->prepare('SELECT COUNT(*) FROM players p JOIN teams t ON t.id = p.team_id WHERE t.event_id = ? AND t.modality = ?');
    $participantCountStmt->execute([(int) $team['event_id'], $team['modality']]);
    $gameParticipantCount = (int) $participantCountStmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';
    if ($isGameParticipant && $action !== 'update') {
        $matchesStmt = db()->prepare('SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ?');
        $matchesStmt->execute([(int) $team['event_id'], $team['modality']]);
        if ((int) $matchesStmt->fetchColumn() > 0) {
            flash('As inscrições desta modalidade estão fechadas porque os confrontos já foram gerados.', 'warning');
            redirect('index.php?page=players&team_id=' . $teamId);
        }
    }

    if ($action === 'update') {
        if (!$canEditPlayers) {
            flash('Não é possível editar jogadores neste momento.', 'warning');
            redirect('index.php?page=players&team_id=' . $teamId);
        }

        $playerId = (int) ($_POST['player_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $birthDate = $_POST['birth_date'] ?: null;

        if ($playerId && strlen($name) >= 2) {
            $stmt = db()->prepare('UPDATE players SET name = ?, email = ?, birth_date = ? WHERE id = ? AND team_id = ?');
            $stmt->execute([$name, $email, $birthDate, $playerId, $teamId]);
            flash('Jogador atualizado.');
        } else {
            flash('Informe um nome válido para atualizar o jogador.', 'warning');
        }

        redirect('index.php?page=players&team_id=' . $teamId);
    }

    if ($action === 'delete') {
        if (!$canEditPlayers) {
            flash('Não é possível excluir jogadores neste momento.', 'warning');
            redirect('index.php?page=players&team_id=' . $teamId);
        }

        $playerId = (int) ($_POST['player_id'] ?? 0);
        if ($playerId) {
            $stmt = db()->prepare('DELETE FROM players WHERE id = ? AND team_id = ?');
            $stmt->execute([$playerId, $teamId]);
            flash('Jogador excluído.');
        }

        redirect('index.php?page=players&team_id=' . $teamId);
    }

    if ($action === 'bulk_import') {
        if (!$canEditPlayers) {
            flash('Você não tem permissão para cadastrar jogadores neste time.', 'warning');
            redirect('index.php?page=players&team_id=' . $teamId);
        }

        if ($eventInProgress) {
            flash('Não é possível cadastrar jogadores enquanto o evento estiver em andamento.', 'warning');
            redirect('index.php?page=players&team_id=' . $teamId);
        }

        if ($rosterClosed && !can_manage_event_structure((int) $team['event_id'])) {
            flash('As inscrições deste evento não estão abertas para edição de jogadores.', 'warning');
            redirect('index.php?page=players&team_id=' . $teamId);
        }

        $bulkData = trim($_POST['bulk_data'] ?? '');
        if ($bulkData) {
            $lines = array_filter(array_map('trim', explode("\n", $bulkData)));
            $imported = 0;
            $skipped = 0;
            
            foreach ($lines as $line) {
                if ($playerCount + $imported >= $playerLimit || ($isGameParticipant && $gameParticipantCount + $imported >= 32)) {
                    break;
                }

                // Parse line: name
                $name = trim($line);

                if (strlen($name) < 2) {
                    $skipped++;
                    continue;
                }

                try {
                    $stmt = db()->prepare('INSERT INTO players (team_id, user_id, name, email, birth_date) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$teamId, null, $name, null, null]);
                    $imported++;
                } catch (PDOException $exception) {
                    $skipped++;
                }
            }
            
            if ($imported > 0) {
                flash("{$imported} jogadores importados com sucesso." . ($skipped > 0 ? " {$skipped} ignorados." : ''));
            } else {
                flash('Nenhum jogador importado. Verifique os dados.', 'warning');
            }
            redirect('index.php?page=players&team_id=' . $teamId);
        }
    }

    if (!$canEditPlayers) {
        flash('Você não tem permissão para cadastrar jogadores neste time.', 'warning');
        redirect('index.php?page=players&team_id=' . $teamId);
    }

    if ($eventInProgress) {
        flash('Não é possível cadastrar jogadores enquanto o evento estiver em andamento.', 'warning');
        redirect('index.php?page=players&team_id=' . $teamId);
    }

    if ($rosterClosed && !can_manage_event_structure((int) $team['event_id'])) {
        flash('As inscrições deste evento não estão abertas para edição de jogadores.', 'warning');
        redirect('index.php?page=players&team_id=' . $teamId);
    }

    if ($teamIsFull) {
        flash('Este time já atingiu o limite de ' . $playerLimit . ' jogador' . ($playerLimit > 1 ? 'es' : '') . '.', 'warning');
        redirect('index.php?page=players&team_id=' . $teamId);
    }

    if ($isGameParticipant && $gameParticipantCount >= 32) {
        flash('O limite da chave é 32 participantes nesta modalidade.', 'warning');
        redirect('index.php?page=players&team_id=' . $teamId);
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birthDate = $_POST['birth_date'] ?: null;

    if ($name) {
        $userId = null;
        if ($email && validate_email($email)) {
            $userStmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $userStmt->execute([$email]);
            $linkedUser = $userStmt->fetch();
            $userId = $linkedUser ? (int) $linkedUser['id'] : null;
        }

        $stmt = db()->prepare('INSERT INTO players (team_id, user_id, name, email, birth_date) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$teamId, $userId, $name, $email, $birthDate]);
        flash('Jogador cadastrado.');
        redirect('index.php?page=players&team_id=' . $teamId);
    }
}

?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Jogadores · <?= e($team['name']) ?></h1>
        <p class="text-muted mb-0">
            <?= e($team['event_name']) ?> · <?= e(modality_label($team['modality'])) ?>
            <?php if (uses_grouping($team['modality'])): ?> · Grupo <?= e($team['group_name']) ?><?php endif; ?>
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="index.php?page=event&id=<?= (int) $team['event_id'] ?>&modality=<?= e($team['modality']) ?>">Voltar</a>
</div>

<?php if ($eventInProgress): ?>
    <div class="alert alert-warning">
        Não é possível cadastrar jogadores enquanto o evento estiver em andamento.
    </div>
<?php endif; ?>

<?php if ($rosterClosed): ?>
    <div class="alert alert-warning">
        As inscrições deste evento não estão abertas para edição de jogadores<?= $rosterDeadlinePassed ? ' desde ' . e(format_datetime($team['roster_edit_until'])) : '.' ?>
    </div>
<?php endif; ?>

<?php if ($teamIsFull): ?>
    <div class="alert <?= $teamOverLimit ? 'alert-danger' : 'alert-info' ?>">
        Este time <?= $teamOverLimit ? 'ultrapassou' : 'atingiu' ?> o limite de <?= $playerLimit ?> jogador<?= $playerLimit > 1 ? 'es' : '' ?>.
        <?php if ($teamOverLimit): ?>Remova jogadores até regularizar para participar dos jogos.<?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3 players-layout">
    <?php if ($canEditPlayers): ?>
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Novo jogador · <?= $playerCount ?>/<?= $playerLimit ?></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input class="form-control" name="name" required <?= $formDisabled ? 'disabled' : '' ?>>
                    </div>
                    <button class="btn btn-primary" type="submit" <?= $formDisabled ? 'disabled' : '' ?>>Salvar</button>
                </form>
            </div>
        </div>
        <?php if (!$formDisabled): ?>
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Importação em massa</div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="bulk_import">
                        <div class="mb-3">
                            <label class="form-label">Dados dos jogadores (um por linha)</label>
                            <textarea class="form-control" name="bulk_data" rows="5" placeholder="João Silva&#10;Maria Santos"></textarea>
                            <small class="text-muted">Formato: nome (um por linha)</small>
                        </div>
                        <button class="btn btn-success" type="submit">Importar jogadores</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="<?= $canEditPlayers ? 'col-lg-8' : 'col-12' ?>">
        <div class="card shadow-sm player-list-card">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span>Lista pré-cadastrada</span>
                <span class="badge text-bg-light"><?= $playerCount ?>/<?= $playerLimit ?> jogadores</span>
                <?php if (!$canEditPlayers): ?>
                    <span class="badge text-bg-light">Somente visualização</span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 responsive-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <?php if ($canEditPlayers): ?>
                                <th>E-mail</th>
                                <th>Nascimento</th>
                                <th>Ações</th>
                            <?php else: ?>
                                <th class="text-center">Número</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($players as $player): ?>
                        <tr>
                            <td data-label="Nome" class="player-name-cell">
                                <?php if ($canEditPlayers): ?>
                                    <form method="post" id="player-form-<?= (int) $player['id'] ?>" class="d-flex flex-column gap-1">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                                        <input class="form-control form-control-sm" name="name" value="<?= e($player['name']) ?>" required>
                                    </form>
                                <?php else: ?>
                                    <strong><?= e($player['name']) ?></strong>
                                <?php endif; ?>
                            </td>
                            <?php if ($canEditPlayers): ?>
                            <td data-label="E-mail">
                                <input class="form-control form-control-sm" form="player-form-<?= (int) $player['id'] ?>" name="email" value="<?= e($player['email']) ?>">
                            </td>
                            <td data-label="Nascimento">
                                <input class="form-control form-control-sm" form="player-form-<?= (int) $player['id'] ?>" type="date" name="birth_date" value="<?= e($player['birth_date']) ?>">
                            </td>
                            <td data-label="Ações">
                                <div class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-sm btn-outline-primary" form="player-form-<?= (int) $player['id'] ?>">Salvar</button>
                                    <form method="post" onsubmit="return confirmAction('Excluir este jogador?')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                    </form>
                                </div>
                            </td>
                            <?php else: ?>
                                <td data-label="Número" class="text-center"><?= e($player['jersey_number'] ?? '-') ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$players): ?><tr><td colspan="<?= $canEditPlayers ? 4 : 2 ?>" class="text-muted">Nenhum jogador cadastrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
