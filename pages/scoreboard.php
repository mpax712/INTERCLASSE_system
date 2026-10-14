<?php
require_roles(['admin', 'professor']);

$matchId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT m.*, e.name AS event_name, e.status AS event_status, COALESCE(hp.name, ht.name) AS home_name, ht.class_name AS home_class_name, COALESCE(ap.name, at.name) AS away_name, at.class_name AS away_class_name
     FROM matches m
     JOIN events e ON e.id = m.event_id
     LEFT JOIN teams ht ON ht.id = m.home_team_id
     LEFT JOIN teams at ON at.id = m.away_team_id
     LEFT JOIN players hp ON hp.id = m.home_player_id
     LEFT JOIN players ap ON ap.id = m.away_player_id
     WHERE m.id = ?'
);
$stmt->execute([$matchId]);
$match = $stmt->fetch();

if (!$match) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Partida não encontrada.</div>';
    return;
}

$user = current_user();
$canManageScoreboard = $user && can_operate_match((int) $match['event_id'], $match['modality']);
if (!$canManageScoreboard) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Acesso negado.</div>';
    return;
}

if (game_is_new_modality((string) $match['modality'])) {
    require __DIR__ . '/game_scoreboard.php';
    return;
}

$scoreData = decode_score_data($match['score_data'] ?? null, $match['modality']);
$isCollectiveMatch = !empty($scoreData['collective_match']) && empty($match['home_team_id']) && empty($match['away_team_id']);
$playerNumbers = $scoreData['player_numbers'] ?? [];
$homePlayers = [];
$awayPlayers = [];
$cubeTeams = [];
$cubePlayers = [];

if ($match && ($match['home_team_id'] || $match['away_team_id'])) {
    $teamIds = array_filter([$match['home_team_id'], $match['away_team_id']], fn($id) => $id !== null && $id !== '');
    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $playersStmt = db()->prepare("SELECT id, team_id, name, jersey_number FROM players WHERE team_id IN ($placeholders) ORDER BY name");
        $playersStmt->execute(array_values($teamIds));
        $players = $playersStmt->fetchAll();
        foreach ($players as $player) {
            if ($player['team_id'] === $match['home_team_id']) {
                $homePlayers[] = $player;
            } elseif ($player['team_id'] === $match['away_team_id']) {
                $awayPlayers[] = $player;
            }
        }
    }
}

if ($match['modality'] === 'cubo_magico') {
    $cubePlayerIds = array_filter([
        (int) ($scoreData['bracket_home_player_id'] ?? 0),
        (int) ($scoreData['bracket_away_player_id'] ?? 0),
    ]);
    $teamIds = array_filter([
        (int) ($match['home_team_id'] ?? 0),
        (int) ($match['away_team_id'] ?? 0),
    ]);
    $params = [$match['event_id'], $match['modality']];
    $extraWhere = '';
    if ($cubePlayerIds) {
        $placeholders = implode(',', array_fill(0, count($cubePlayerIds), '?'));
        $extraWhere = " AND p.id IN ($placeholders)";
        $params = array_merge($params, $cubePlayerIds);
    } elseif ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $extraWhere = " AND t.id IN ($placeholders)";
        $params = array_merge($params, $teamIds);
    }

    $cubeStmt = db()->prepare(
        'SELECT p.id, p.team_id, p.name, p.jersey_number, t.name AS team_name, t.class_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?' . $extraWhere . '
         ORDER BY t.name, p.name'
    );
    $cubeStmt->execute($params);
    $cubePlayers = $cubeStmt->fetchAll();
    $cubeTeams = [];
    foreach ($cubePlayers as $player) {
        $teamId = (int) $player['team_id'];
        if (!isset($cubeTeams[$teamId])) {
            $cubeTeams[$teamId] = [
                'team_id' => $teamId,
                'team_name' => $player['team_name'],
                'class_name' => $player['class_name'],
                'players' => [],
            ];
        }
        $cubeTeams[$teamId]['players'][] = $player;
    }
}

$alertOccurrences = get_match_team_alert_occurrences((int) $match['id']);
$scoreType = score_type_for_modality($match['modality']);
$incrementLabel = $scoreType === 'gols' ? 'gol' : 'ponto';
$isIndividual = is_individual_modality($match['modality']);
$quizTeams = [];
if ($match['modality'] === 'quiz') {
    $teamIds = $isCollectiveMatch
        ? array_filter(array_map('intval', $scoreData['team_ids'] ?? []))
        : array_filter([
            (int) ($match['home_team_id'] ?? 0),
            (int) ($match['away_team_id'] ?? 0),
        ]);
    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $quizTeamsStmt = db()->prepare("SELECT id, name, class_name FROM teams WHERE event_id = ? AND modality = ? AND id IN ($placeholders) ORDER BY name");
        $quizTeamsStmt->execute(array_merge([$match['event_id'], $match['modality']], $teamIds));
        $quizTeams = $quizTeamsStmt->fetchAll();
    }
}
?>
<div class="page-title scoreboard-page-title">
    <div>
        <h1 class="h3 mb-1">Placar ao vivo</h1>
        <p class="text-muted mb-2"><?= e($match['event_name']) ?> · <?= e(modality_label($match['modality'])) ?></p>
        <div class="scoreboard-title-meta">
            <span class="<?= e(status_pill_class($match['status'])) ?>" id="match-status-header"><?= e(match_status_label($match['status'])) ?></span>
            <span><?= $isCollectiveMatch ? 'Partida coletiva' : e($match['home_name'] ?? 'Casa') . ' x ' . e($match['away_name'] ?? 'Visitante') ?></span>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
        <a class="btn btn-outline-warning" href="index.php?page=occurrences&match_id=<?= (int) $match['id'] ?>">Ocorrências</a>
        <a class="btn btn-outline-secondary" href="index.php?page=match&id=<?= (int) $match['id'] ?>" target="_blank">Ver público</a>
    </div>
</div>

<?php if ($alertOccurrences): ?>
    <div class="alert alert-warning scoreboard-alert d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <strong>Time com alerta de ocorrência.</strong>
            Há jogador marcado para atenção antes deste jogo.
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($alertOccurrences as $occurrence): ?>
                <a class="btn btn-warning btn-sm" href="index.php?page=occurrence_records&event_id=<?= (int) $match['event_id'] ?>&occurrence_id=<?= (int) $occurrence['id'] ?>#occurrence-<?= (int) $occurrence['id'] ?>">
                    <?= e($occurrence['team_name']) ?><?= $occurrence['player_name'] ? ' - ' . e($occurrence['player_name']) : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if ($match['modality'] !== 'quiz' && !$isCollectiveMatch): ?>
<div class="card shadow-sm mb-3 scoreboard-numbering-card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h5 mb-1">Numeração de jogadores</h2>
                <p class="text-muted mb-0">Defina os números no placar antes de iniciar a partida. O botão desaparece ao iniciar o jogo.</p>
            </div>
            <?php if ($match['status'] === 'agendada'): ?>
                <button type="button" id="open-numbering" class="btn btn-primary">Definir numeração</button>
            <?php endif; ?>
        </div>

        <div id="numbering-form" class="d-none">
            <form id="player-number-form">
                <div class="form-check form-check-inline mb-3">
                    <input class="form-check-input" type="checkbox" id="apply-to-event" name="apply_to_event" checked>
                    <label class="form-check-label" for="apply-to-event">Aplicar a numeração pelo resto do evento</label>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 bg-light">
                            <h3 class="h6"><?= e($match['home_name'] ?? 'Casa') ?></h3>
                            <?php if ($homePlayers): ?>
                                <?php foreach ($homePlayers as $player): ?>
                                    <div class="mb-2">
                                        <label class="form-label mb-1"><?= e($player['name']) ?></label>
                                        <input type="number" min="0" class="form-control player-number-input" name="player_<?= (int) $player['id'] ?>" value="<?= e($playerNumbers[$player['id']] ?? $player['jersey_number'] ?? '') ?>" placeholder="Número da camisa">
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="small text-muted">Nenhum jogador cadastrado para este time.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 bg-light">
                            <h3 class="h6"><?= e($match['away_name'] ?? 'Visitante') ?></h3>
                            <?php if ($awayPlayers): ?>
                                <?php foreach ($awayPlayers as $player): ?>
                                    <div class="mb-2">
                                        <label class="form-label mb-1"><?= e($player['name']) ?></label>
                                        <input type="number" min="0" class="form-control player-number-input" name="player_<?= (int) $player['id'] ?>" value="<?= e($playerNumbers[$player['id']] ?? $player['jersey_number'] ?? '') ?>" placeholder="Número da camisa">
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="small text-muted">Nenhum jogador cadastrado para este time.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button id="save-numbering" type="button" class="btn btn-success">Salvar numeração</button>
                    <button id="cancel-numbering" type="button" class="btn btn-outline-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm scoreboard-main-card" data-scoreboard-match="<?= (int) $match['id'] ?>" data-version="<?= (int) $match['version'] ?>" data-csrf="<?= e(csrf_token()) ?>" data-modality="<?= e($match['modality']) ?>" data-modality-label="<?= e(modality_label($match['modality'])) ?>" data-status="<?= e($match['status']) ?>" data-is-individual="<?= $isIndividual ? '1' : '0' ?>" data-home-name="<?= e($isCollectiveMatch ? 'Partida coletiva' : ($match['home_name'] ?? 'Casa')) ?>" data-away-name="<?= e($isCollectiveMatch ? '' : ($match['away_name'] ?? 'Visitante')) ?>">
    <div class="card-body">
        <?php if ($match['modality'] === 'quiz'): ?>
            <div class="quiz-scoreboard">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Equipe</th>
                                <th class="text-center">Pontos</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="quiz-team-list">
                            <?php foreach ($quizTeams as $team): ?>
                                <?php $teamScore = $scoreData['scores'][$team['id']] ?? 0; ?>
                                <tr data-team-id="<?= (int) $team['id'] ?>">
                                    <td><?= e($team['name']) ?><?= $team['class_name'] ? ' · ' . e($team['class_name']) : '' ?></td>
                                    <td class="text-center quiz-score" data-team-id="<?= (int) $team['id'] ?>"><?= (int) $teamScore ?></td>
                                    <td class="text-end">
                                        <?php if ($match['event_status'] !== 'rascunho'): ?>
                                            <button class="btn btn-success btn-sm score-action" data-action="adjust" data-team-id="<?= (int) $team['id'] ?>" data-amount="1">+1</button>
                                            <button class="btn btn-outline-danger btn-sm score-action" data-action="adjust" data-team-id="<?= (int) $team['id'] ?>" data-amount="-1">-1</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$quizTeams): ?>
                                <tr><td colspan="3" class="text-center text-muted">Nenhuma equipe cadastrada para o quiz.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 d-flex flex-wrap gap-2 justify-content-center">
                    <?php if ($match['event_status'] !== 'rascunho'): ?>
                        <button class="btn btn-outline-primary btn-sm match-action start-action" data-action="start">Iniciar</button>
                        <button class="btn btn-outline-dark btn-sm match-action finish-action" data-action="finish">Encerrar</button>
                    <?php endif; ?>
                    <span class="<?= e(status_pill_class($match['status'])) ?>" id="match-status"><?= e(match_status_label($match['status'])) ?></span>
                </div>
            </div>
        <?php elseif ($match['modality'] === 'cubo_magico'): ?>
            <div class="mb-3">
                <div class="border rounded p-3 bg-white">
                    <h3 class="h5">Participantes por sala</h3>
                    <?php if ($cubeTeams): ?>
                        <?php foreach ($cubeTeams as $team): ?>
                            <div class="mb-3">
                                <h4 class="h6 mb-2"><?= e($team['team_name']) ?><?= $team['class_name'] ? ' · ' . e($team['class_name']) : '' ?></h4>
                                <?php foreach ($team['players'] as $player): ?>
                                    <div class="form-check mb-1">
                                        <input class="form-check-input cube-player-radio" type="radio" name="cube_player" id="cube-player-<?= (int) $player['id'] ?>" value="<?= (int) $player['id'] ?>">
                                        <label class="form-check-label" for="cube-player-<?= (int) $player['id'] ?>">
                                            <?= e($player['name']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="small text-muted">Nenhum jogador cadastrado nesta modalidade.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="border rounded p-3 bg-white mt-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3 class="h5 mb-0">Registrar tentativas</h3>
                    <?php if ($match['event_status'] !== 'rascunho'): ?>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-primary btn-sm match-action start-action" data-action="start">Iniciar</button>
                            <button class="btn btn-outline-dark btn-sm match-action finish-action" data-action="finish">Encerrar</button>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <div id="cube-attempts-list" class="mb-3">
                        <div class="input-group mb-2 attempt-row">
                            <input type="number" step="0.01" min="0" class="form-control attempt-time-input" placeholder="Tempo em segundos">
                            <button type="button" class="btn btn-outline-danger remove-attempt">Remover</button>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button id="add-cube-attempt" type="button" class="btn btn-secondary btn-sm">Nova tentativa</button>
                        <button id="save-cube-attempts" type="button" class="btn btn-primary btn-sm">Salvar tentativas</button>
                    </div>
                </div>
                <div id="cube-score-summary" class="mt-3"></div>
            </div>
        <?php elseif ($isIndividual): ?>
            <!-- Individual modality scoreboard -->
            <div class="row g-3 text-center">
                <div class="col-md-5">
                    <div class="border rounded p-3 scoreboard-team bg-white">
                        <h2 class="h4" id="home-name"><?= e($match['home_name'] ?? 'Atleta A') ?></h2>
                        <p class="text-muted small"><?= e($match['home_class_name'] ?? '') ?></p>
                        <?php if ($match['event_status'] !== 'rascunho'): ?>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 score-controls" data-team="home">
                            <button class="btn btn-success score-action winner-action" data-action="set_winner" data-team="home">Vencedor</button>
                            <button class="btn btn-warning score-action wo-action" data-action="wo" data-amount="0">W.O.</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-2 d-flex flex-column justify-content-center align-items-center gap-2">
                    <span class="text-muted fs-2">vs</span>
                    <?php if ($match['event_status'] !== 'rascunho'): ?>
                    <button class="btn btn-outline-primary btn-sm match-action start-action" data-action="start">Iniciar</button>
                    <button class="btn btn-outline-dark btn-sm match-action finish-action" data-action="finish">Encerrar</button>
                    <?php endif; ?>
                    <span class="<?= e(status_pill_class($match['status'])) ?>" id="match-status"><?= e(match_status_label($match['status'])) ?></span>
                </div>
                <div class="col-md-5">
                    <div class="border rounded p-3 scoreboard-team bg-white">
                        <h2 class="h4" id="away-name"><?= e($match['away_name'] ?? 'Atleta B') ?></h2>
                        <p class="text-muted small"><?= e($match['away_class_name'] ?? '') ?></p>
                        <?php if ($match['event_status'] !== 'rascunho'): ?>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 score-controls" data-team="away">
                            <button class="btn btn-success score-action winner-action" data-action="set_winner" data-team="away">Vencedor</button>
                            <button class="btn btn-warning score-action wo-action" data-action="wo" data-amount="0">W.O.</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Team modality scoreboard -->
            <div class="row g-3 text-center">
                <div class="col-md-5">
                    <div class="border rounded p-3 scoreboard-team bg-white">
                        <h2 class="h4" id="home-name"><?= e($match['home_name'] ?? 'Time A') ?></h2>
                        <div class="score-number" id="home-score"><?= (int) $match['home_score'] ?></div>
                        <?php if ($homePlayers): ?>
                            <div class="mt-3 text-start">
                                <strong class="d-block mb-2">Jogadores</strong>
                                <ul class="list-unstyled mb-0 home-roster">
                                    <?php foreach ($homePlayers as $player): ?>
                                        <?php $playerNumber = $playerNumbers[$player['id']] ?? $player['jersey_number'] ?? null; ?>
                                        <li>
                                            <?= e($player['name']) ?>
                                            <?php if ($playerNumber !== null && $playerNumber !== ''): ?>
                                                (<span class="player-number" data-player-id="<?= (int) $player['id'] ?>"><?= e($playerNumber) ?></span>)
                                            <?php else: ?>
                                                (<span class="player-number text-muted" data-player-id="<?= (int) $player['id'] ?>">-</span>)
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($match['event_status'] !== 'rascunho'): ?>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 score-controls" data-team="home">
                            <?php if ($match['modality'] === 'basquete'): ?>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="1">+1</button>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="2">+2</button>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="3">+3</button>
                                <button class="btn btn-outline-danger score-action" data-action="adjust" data-amount="-1">-1</button>
                            <?php elseif ($match['modality'] === 'volei'): ?>
                                <button class="btn btn-success score-action" data-action="volleyball_point" data-amount="1">+ ponto</button>
                                <button class="btn btn-outline-danger score-action" data-action="volleyball_point" data-amount="-1">- ponto</button>
                                <button class="btn btn-primary score-action" data-action="volleyball_set" data-amount="1">+ set</button>
                                <button class="btn btn-outline-danger score-action" data-action="volleyball_set" data-amount="-1">- set</button>
                            <?php else: ?>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="1">+ <?= e($incrementLabel) ?></button>
                                <button class="btn btn-outline-danger score-action" data-action="adjust" data-amount="-1">- <?= e($incrementLabel) ?></button>
                            <?php endif; ?>
                            <button class="btn btn-warning score-action wo-action" data-action="wo" data-amount="0">W.O.</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-2 d-flex flex-column justify-content-center align-items-center gap-2">
                    <span class="text-muted fs-2">x</span>
                    <?php if ($match['modality'] === 'basquete'): ?>
                        <label class="small text-muted quarter-control">Quarto</label>
                        <select class="form-select form-select-sm quarter-control" id="quarter">
                            <option value="0">1º</option>
                            <option value="1">2º</option>
                            <option value="2">3º</option>
                            <option value="3">4º</option>
                        </select>
                    <?php endif; ?>
                    <?php if ($match['event_status'] !== 'rascunho'): ?>
                    <button class="btn btn-outline-primary btn-sm match-action start-action" data-action="start">Iniciar</button>
                    <button class="btn btn-outline-dark btn-sm match-action finish-action" data-action="finish">Encerrar</button>
                    <?php endif; ?>
                    <span class="<?= e(status_pill_class($match['status'])) ?>" id="match-status"><?= e(match_status_label($match['status'])) ?></span>
                </div>
                <div class="col-md-5">
                    <div class="border rounded p-3 scoreboard-team bg-white">
                        <h2 class="h4" id="away-name"><?= e($match['away_name'] ?? 'Time B') ?></h2>
                        <div class="score-number" id="away-score"><?= (int) $match['away_score'] ?></div>
                        <?php if ($awayPlayers): ?>
                            <div class="mt-3 text-start">
                                <strong class="d-block mb-2">Jogadores</strong>
                                <ul class="list-unstyled mb-0 away-roster">
                                    <?php foreach ($awayPlayers as $player): ?>
                                        <?php $playerNumber = $playerNumbers[$player['id']] ?? $player['jersey_number'] ?? null; ?>
                                        <li>
                                            <?= e($player['name']) ?>
                                            <?php if ($playerNumber !== null && $playerNumber !== ''): ?>
                                                (<span class="player-number" data-player-id="<?= (int) $player['id'] ?>"><?= e($playerNumber) ?></span>)
                                            <?php else: ?>
                                                (<span class="player-number text-muted" data-player-id="<?= (int) $player['id'] ?>">-</span>)
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <?php if ($match['event_status'] !== 'rascunho'): ?>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 score-controls" data-team="away">
                            <?php if ($match['modality'] === 'basquete'): ?>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="1">+1</button>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="2">+2</button>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="3">+3</button>
                                <button class="btn btn-outline-danger score-action" data-action="adjust" data-amount="-1">-1</button>
                            <?php elseif ($match['modality'] === 'volei'): ?>
                                <button class="btn btn-success score-action" data-action="volleyball_point" data-amount="1">+ ponto</button>
                                <button class="btn btn-outline-danger score-action" data-action="volleyball_point" data-amount="-1">- ponto</button>
                                <button class="btn btn-primary score-action" data-action="volleyball_set" data-amount="1">+ set</button>
                                <button class="btn btn-outline-danger score-action" data-action="volleyball_set" data-amount="-1">- set</button>
                            <?php else: ?>
                                <button class="btn btn-success score-action" data-action="adjust" data-amount="1">+ <?= e($incrementLabel) ?></button>
                                <button class="btn btn-outline-danger score-action" data-action="adjust" data-amount="-1">- <?= e($incrementLabel) ?></button>
                            <?php endif; ?>
                            <button class="btn btn-warning score-action wo-action" data-action="wo" data-amount="0">W.O.</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
            </div>
        </div>
        <div class="text-center mt-3 text-muted" id="score-detail"><?= e(match_score_text($match)) ?></div>
        <div class="score-sync-state text-center mt-2" id="score-sync-state" aria-live="polite">Sincronizado</div>
        <button type="button" class="btn btn-outline-primary mt-2" id="score-retry" hidden>Reenviar atualização</button>
        <div class="d-flex gap-2 justify-content-center flex-wrap mt-3">
            <button type="button" class="btn btn-outline-primary match-action await-action" data-action="await_result">Aguardar resultado</button>
            <button type="button" class="btn btn-outline-primary match-action resume-action" data-action="resume">Retomar partida</button>
            <?php if (($user['role'] ?? '') === 'admin'): ?><button type="button" class="btn btn-outline-danger match-action cancel-action" data-action="cancel">Cancelar partida</button><?php endif; ?>
        </div>
    </div>
</div>
<?php if ($match['modality'] === 'cubo_magico'): ?>
<script>
    window.cubePlayerInfo = <?= json_encode($cubePlayers, JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
<script src="assets/js/scoreboard.js"></script>
