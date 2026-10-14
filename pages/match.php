<?php
$matchId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT m.*, e.name AS event_name, COALESCE(hp.name, ht.name) AS home_name, COALESCE(ap.name, at.name) AS away_name
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

$scoreData = decode_score_data($match['score_data'] ?? null, $match['modality']);
$homeName = $match['home_name'] ?? 'A definir';
$awayName = $match['away_name'] ?? 'A definir';
$matchTitle = $homeName . ' x ' . $awayName;
$cubeTeams = [];
$quizTeams = [];
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
        'SELECT p.id, p.name, p.jersey_number, t.id AS team_id, t.name AS team_name, t.class_name
         FROM players p
         JOIN teams t ON t.id = p.team_id
         WHERE t.event_id = ? AND t.modality = ?' . $extraWhere . '
         ORDER BY t.name, p.name'
    );
    $cubeStmt->execute($params);
    foreach ($cubeStmt->fetchAll() as $player) {
        $teamId = (int) $player['team_id'];
        if (!isset($cubeTeams[$teamId])) {
            $cubeTeams[$teamId] = [
                'team_name' => $player['team_name'],
                'players' => [],
            ];
        }
        $cubeTeams[$teamId]['players'][] = $player;
    }

    foreach ($cubeTeams as &$team) {
        usort($team['players'], function ($a, $b) use ($scoreData) {
            $avgA = isset($scoreData['player_averages'][$a['id']]) ? (float) $scoreData['player_averages'][$a['id']] : null;
            $avgB = isset($scoreData['player_averages'][$b['id']]) ? (float) $scoreData['player_averages'][$b['id']] : null;

            if ($avgA === null && $avgB === null) {
                return strcmp($a['name'], $b['name']);
            }
            if ($avgA === null) {
                return 1;
            }
            if ($avgB === null) {
                return -1;
            }
            if ($avgA !== $avgB) {
                return $avgA < $avgB ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });
    }
    unset($team);

    usort($cubeTeams, function ($a, $b) use ($scoreData) {
        $bestA = null;
        foreach ($a['players'] as $player) {
            $avg = isset($scoreData['player_averages'][$player['id']]) ? (float) $scoreData['player_averages'][$player['id']] : null;
            if ($avg !== null && ($bestA === null || $avg < $bestA)) {
                $bestA = $avg;
            }
        }
        $bestB = null;
        foreach ($b['players'] as $player) {
            $avg = isset($scoreData['player_averages'][$player['id']]) ? (float) $scoreData['player_averages'][$player['id']] : null;
            if ($avg !== null && ($bestB === null || $avg < $bestB)) {
                $bestB = $avg;
            }
        }

        if ($bestA === null && $bestB === null) {
            return strcmp($a['team_name'], $b['team_name']);
        }
        if ($bestA === null) {
            return 1;
        }
        if ($bestB === null) {
            return -1;
        }
        if ($bestA !== $bestB) {
            return $bestA < $bestB ? -1 : 1;
        }
        return strcmp($a['team_name'], $b['team_name']);
    });
} elseif ($match['modality'] === 'quiz') {
    $teamIds = array_filter([
        (int) ($match['home_team_id'] ?? 0),
        (int) ($match['away_team_id'] ?? 0),
    ]);
    if ($teamIds) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $quizStmt = db()->prepare("SELECT id, name, class_name FROM teams WHERE event_id = ? AND modality = ? AND id IN ($placeholders) ORDER BY name");
        $quizStmt->execute(array_merge([$match['event_id'], $match['modality']], $teamIds));
        $quizTeams = $quizStmt->fetchAll();
    }
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1"><?= e($matchTitle) ?></h1>
        <p class="text-muted mb-0"><?= e($match['event_name']) ?> · <?= e(modality_label($match['modality'])) ?> · <?= e(stage_label($match['stage'])) ?></p>
    </div>
    <span class="<?= e(status_pill_class($match['status'])) ?>" id="match-status"><?= e(match_status_label($match['status'])) ?></span>
</div>

<div class="card shadow-sm">
    <div class="card-body" data-live-match="<?= (int) $match['id'] ?>" data-modality="<?= e($match['modality']) ?>">
        <?php if ($match['modality'] === 'cubo_magico'): ?>
            <div id="cube-match-summary">
                <?php if ($cubeTeams): ?>
                    <?php foreach ($cubeTeams as $team): ?>
                        <div class="mb-4">
                            <h4 class="h6 mb-2"><?= e($team['team_name']) ?></h4>
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Aluno</th>
                                            <th class="text-center">Nº</th>
                                            <th class="text-center">Tentativas</th>
                                            <th class="text-center">Média</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($team['players'] as $player): ?>
                                            <?php $attempts = $scoreData['player_attempts'][$player['id']] ?? [];
                                            $average = $scoreData['player_averages'][$player['id']] ?? null;
                                            $attemptText = $attempts ? implode(', ', array_map(fn($value) => number_format((float) $value, 2, ',', '.') . 's', $attempts)) : '<span class="text-muted">sem tentativas</span>';
                                            ?>
                                            <tr>
                                                <td><?= e($player['name']) ?></td>
                                                <td class="text-center"><?= e($player['jersey_number'] ?? '-') ?></td>
                                                <td class="text-center"><span class="cube-attempts" data-player-id="<?= (int) $player['id'] ?>"><?= $attemptText ?></span></td>
                                                <td class="text-center"><span class="cube-average" data-player-id="<?= (int) $player['id'] ?>"><?= $average !== null ? number_format((float) $average, 2, ',', '.') . 's' : '<span class="text-muted">-</span>' ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="small text-muted">Nenhum participante cadastrado nesta modalidade.</p>
                <?php endif; ?>
            </div>
        <?php elseif ($match['modality'] === 'quiz'): ?>
            <div id="quiz-match-summary">
                <?php if ($quizTeams): ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th class="text-center">Turma</th>
                                    <th class="text-center">Pontos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quizTeams as $team): ?>
                                    <?php $teamScore = (int) ($scoreData['scores'][$team['id']] ?? 0); ?>
                                    <tr>
                                        <td class="text-center"><?= e($team['class_name'] ?? '-') ?></td>
                                        <td class="text-center"><span class="quiz-score" data-team-id="<?= (int) $team['id'] ?>"><?= $teamScore ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="small text-muted">Nenhuma equipe cadastrada para este quiz.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row text-center align-items-center">
                <div class="col-5">
                    <div class="h5" id="home-name"><?= e($match['home_name'] ?? 'A definir') ?></div>
                    <div class="score-number" id="home-score"><?= in_array($match['status'], ['agendada','cancelada'], true) ? '–' : (int) $match['home_score'] ?></div>
                </div>
                <div class="col-2 text-muted fs-2">x</div>
                <div class="col-5">
                    <div class="h5" id="away-name"><?= e($match['away_name'] ?? 'A definir') ?></div>
                    <div class="score-number" id="away-score"><?= in_array($match['status'], ['agendada','cancelada'], true) ? '–' : (int) $match['away_score'] ?></div>
                </div>
            </div>
        <?php endif; ?>
        <div class="text-center mt-3 text-muted" id="score-detail"><?= e($match['status'] === 'agendada' ? 'Placar pendente' : ($match['status'] === 'cancelada' ? 'Partida cancelada' : match_score_text($match))) ?></div>
        <div class="text-center mt-2 small text-muted">Atualiza automaticamente.</div>
    </div>
</div>
<script src="assets/js/live-score.js"></script>
