<?php
require_roles(['admin', 'professor']);
ensure_occurrences_schema();

$matchId = (int) ($_GET['match_id'] ?? 0);
$stmt = db()->prepare(
    'SELECT m.*, e.name AS event_name, ht.name AS home_name, at.name AS away_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     WHERE m.id = ?'
);
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Partida não encontrada.</div>';
    return;
}

if (!can_operate_match((int) $match['event_id'], $match['modality'])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Acesso negado.</div>';
    return;
}

$teamIds = array_values(array_filter([$match['home_team_id'], $match['away_team_id']], fn($id) => $id !== null && $id !== ''));
if ($teamIds) {
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $teamsStmt = db()->prepare("SELECT id, name, class_name FROM teams WHERE id IN ($placeholders) ORDER BY name");
    $teamsStmt->execute($teamIds);
} else {
    $teamsStmt = db()->prepare('SELECT id, name, class_name FROM teams WHERE event_id = ? AND modality = ? AND gender = ? ORDER BY name');
    $teamsStmt->execute([$match['event_id'], $match['modality'], $match['gender']]);
}
$teams = $teamsStmt->fetchAll();
$formErrors = [];

$playersByTeam = [];
if ($teams) {
    $ids = array_map(fn($team) => (int) $team['id'], $teams);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $playersStmt = db()->prepare("SELECT id, team_id, name, jersey_number FROM players WHERE team_id IN ($placeholders) ORDER BY name");
    $playersStmt->execute($ids);
    foreach ($playersStmt->fetchAll() as $player) {
        $playersByTeam[(int) $player['team_id']][] = $player;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $targetType = $_POST['target_type'] ?? '';
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $playerId = (int) ($_POST['player_id'] ?? 0);
    $actionText = trim((string) ($_POST['action_text'] ?? ''));
    $ineligibleNextGame = isset($_POST['ineligible_next_game']) ? 1 : 0;
    $pointsDeducted = max(0, (int) ($_POST['points_deducted'] ?? 0));
    $validTeamIds = array_map(fn($team) => (int) $team['id'], $teams);
    $errors = [];

    if (!in_array($teamId, $validTeamIds, true)) {
        $errors[] = 'Selecione um time desta partida.';
    }

    if (!in_array($targetType, ['player', 'team'], true)) {
        $errors[] = 'Defina se a ocorrência é para jogador ou time.';
    }

    if ($title === '') {
        $errors[] = 'Informe a ocorrência.';
    }

    if ($description === '') {
        $errors[] = 'Informe uma descricao breve.';
    }

    if ($targetType === 'player') {
        $validPlayerIds = array_map(fn($player) => (int) $player['id'], $playersByTeam[$teamId] ?? []);
        if (!$playerId || !in_array($playerId, $validPlayerIds, true)) {
            $errors[] = 'Selecione um jogador do time definido.';
        }

        if ($actionText === '') {
            $errors[] = 'Defina o que será feito com o jogador.';
        }

        $pointsDeducted = 0;
    } else {
        $playerId = null;
        $actionText = $actionText ?: null;
        $ineligibleNextGame = 0;
        if ($pointsDeducted <= 0) {
            $errors[] = 'Informe quantos pontos serão retirados do time.';
        }
    }

    if ($errors) {
        $formErrors = $errors;
    } else {
        $user = current_user();
        $insert = db()->prepare(
            'INSERT INTO occurrences
                (event_id, match_id, team_id, player_id, target_type, title, description, action_text, ineligible_next_game, points_deducted, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $match['event_id'],
            $match['id'],
            $teamId,
            $playerId,
            $targetType,
            $title,
            $description,
            $actionText,
            $ineligibleNextGame,
            $pointsDeducted,
            $user['id'] ?? null,
        ]);

        flash('Ocorrência registrada com sucesso.');
        redirect('index.php?page=occurrence_records&event_id=' . (int) $match['event_id'] . '&occurrence_id=' . (int) db()->lastInsertId());
    }
}

$recentStmt = db()->prepare(
    'SELECT o.*, t.name AS team_name, p.name AS player_name, u.name AS created_by_name
     FROM occurrences o
     JOIN teams t ON t.id = o.team_id
     LEFT JOIN players p ON p.id = o.player_id
     LEFT JOIN users u ON u.id = o.created_by
     WHERE o.match_id = ?
     ORDER BY o.created_at DESC'
);
$recentStmt->execute([$match['id']]);
$recentOccurrences = $recentStmt->fetchAll();
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Registrar ocorrência</h1>
        <p class="text-muted mb-0"><?= e($match['event_name']) ?> &middot; <?= e(modality_label($match['modality'])) ?> &middot; <?= e($match['home_name'] ?? 'A definir') ?> x <?= e($match['away_name'] ?? 'A definir') ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="index.php?page=match&id=<?= (int) $match['id'] ?>">Voltar ao jogo</a>
</div>

<form id="occurrence-form" method="post" class="card shadow-sm mb-4">
    <div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <?php if ($formErrors): ?>
            <div class="alert alert-danger">
                <?php foreach ($formErrors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="alert alert-info">Defina primeiro o time que receberá a ocorrência. Se for para um jogador, a lista será carregada com os atletas cadastrados nesse time.</div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="team_id">Time</label>
                <select class="form-select" id="team_id" name="team_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?><?= $team['class_name'] ? ' - ' . e($team['class_name']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="target_type">Atribuir ocorrência a</label>
                <select class="form-select" id="target_type" name="target_type" required disabled>
                    <option value="">Selecione</option>
                    <option value="player">Jogador</option>
                    <option value="team">Time</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="title">Ocorrência</label>
                <input class="form-control" id="title" name="title" maxlength="160" required disabled>
            </div>
            <div class="col-md-6 occurrence-player-fields d-none">
                <label class="form-label" for="player_id">Jogador</label>
                <select class="form-select" id="player_id" name="player_id" disabled>
                    <option value="">Selecione um time primeiro</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="description">Descrição da ocorrência</label>
                <textarea class="form-control" id="description" name="description" rows="4" required disabled></textarea>
            </div>
            <div class="col-12 occurrence-player-fields d-none">
                <label class="form-label" for="action_text">O que será feito</label>
                <textarea class="form-control" id="action_text" name="action_text" rows="3" disabled placeholder="Ex.: jogador inelegível para o próximo jogo do time"></textarea>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" value="1" id="ineligible_next_game" name="ineligible_next_game" disabled>
                    <label class="form-check-label" for="ineligible_next_game">Jogador inelegível para o próximo jogo do time</label>
                </div>
            </div>
            <div class="col-md-5 occurrence-team-fields d-none">
                <label class="form-label" for="points_deducted">Pontos a retirar do time</label>
                <input class="form-control" type="number" min="1" step="1" id="points_deducted" name="points_deducted" disabled>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between gap-2">
        <button class="btn btn-outline-secondary" type="button" id="cancel-occurrence">Cancelar ocorrência</button>
        <button class="btn btn-primary" type="button" id="review-occurrence" disabled>Registrar</button>
    </div>
</form>

<?php if ($recentOccurrences): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">Ocorrências deste jogo</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Ocorrência</th><th>Time</th><th>Alvo</th><th>Data</th><th class="text-end">Registro</th></tr></thead>
                <tbody>
                    <?php foreach ($recentOccurrences as $occurrence): ?>
                        <tr>
                            <td><?= e($occurrence['title']) ?></td>
                            <td><?= e($occurrence['team_name']) ?></td>
                            <td><?= $occurrence['target_type'] === 'player' ? e($occurrence['player_name'] ?? 'Jogador') : 'Time' ?></td>
                            <td><?= e(format_datetime($occurrence['created_at'])) ?></td>
                            <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="index.php?page=occurrence_records&event_id=<?= (int) $match['event_id'] ?>&occurrence_id=<?= (int) $occurrence['id'] ?>">Abrir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="confirmOccurrenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5">Você registrou tudo corretamente?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Revisar</button>
                <button type="button" class="btn btn-primary" id="confirm-submit">Sim</button>
            </div>
        </div>
    </div>
</div>

<script>
const playersByTeam = <?= json_encode($playersByTeam, JSON_UNESCAPED_UNICODE) ?>;
const form = document.getElementById('occurrence-form');
const teamSelect = document.getElementById('team_id');
const targetSelect = document.getElementById('target_type');
const titleInput = document.getElementById('title');
const descriptionInput = document.getElementById('description');
const playerSelect = document.getElementById('player_id');
const actionInput = document.getElementById('action_text');
const ineligibleInput = document.getElementById('ineligible_next_game');
const pointsInput = document.getElementById('points_deducted');
const reviewButton = document.getElementById('review-occurrence');

function setDisabled(elements, disabled) {
    elements.forEach(element => element.disabled = disabled);
}

function fillPlayers() {
    const teamId = teamSelect.value;
    const players = playersByTeam[teamId] || [];
    playerSelect.innerHTML = '<option value="">Selecione</option>';

    players.forEach(player => {
        const option = document.createElement('option');
        option.value = player.id;
        option.textContent = player.jersey_number ? `${player.name} #${player.jersey_number}` : player.name;
        playerSelect.appendChild(option);
    });
}

function refreshFormState() {
    const hasTeam = teamSelect.value !== '';
    const targetType = targetSelect.value;
    setDisabled([targetSelect, titleInput, descriptionInput], !hasTeam);
    reviewButton.disabled = !hasTeam || targetType === '';

    document.querySelectorAll('.occurrence-player-fields').forEach(item => item.classList.toggle('d-none', targetType !== 'player'));
    document.querySelectorAll('.occurrence-team-fields').forEach(item => item.classList.toggle('d-none', targetType !== 'team'));

    setDisabled([playerSelect, actionInput, ineligibleInput], !hasTeam || targetType !== 'player');
    setDisabled([pointsInput], !hasTeam || targetType !== 'team');
    playerSelect.required = targetType === 'player';
    actionInput.required = targetType === 'player';
    pointsInput.required = targetType === 'team';
}

teamSelect.addEventListener('change', () => {
    fillPlayers();
    targetSelect.value = '';
    refreshFormState();
});

targetSelect.addEventListener('change', refreshFormState);

document.getElementById('cancel-occurrence').addEventListener('click', () => {
    form.reset();
    playerSelect.innerHTML = '<option value="">Selecione um time primeiro</option>';
    refreshFormState();
});

reviewButton.addEventListener('click', () => {
    if (form.reportValidity()) {
        const confirmModal = new bootstrap.Modal(document.getElementById('confirmOccurrenceModal'));
        confirmModal.show();
    }
});

document.getElementById('confirm-submit').addEventListener('click', () => form.submit());

refreshFormState();
</script>
