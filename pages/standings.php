<?php
ensure_interclasse_schema_updates();

$publicCacheKey = public_page_cache_key('standings', $_GET);
if (public_page_cache_serve($publicCacheKey)) {
    return;
}
$publicCacheEnabled = public_page_cache_start($publicCacheKey);

$events = db()->query('SELECT id, name FROM events ORDER BY start_date DESC')->fetchAll();
$eventId = (int) ($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$modalities = [];
$finalsComplete = false;
$selectedEvent = null;

if ($eventId) {
    $eventStmt = db()->prepare('SELECT status, teams_advancing_per_group, best_thirds_count, advancement_mode FROM events WHERE id = ?');
    $eventStmt->execute([$eventId]);
    $selectedEvent = $eventStmt->fetch();

    $modalitiesStmt = db()->prepare('SELECT modality FROM event_modalities WHERE event_id = ? ORDER BY modality');
    $modalitiesStmt->execute([$eventId]);
    $modalities = array_column($modalitiesStmt->fetchAll(), 'modality');
}

$modality = $_GET['modality'] ?? ($modalities[0] ?? 'futsal');
$isMixedModality = is_mixed_modality($modality);
$isCubeMagic = $modality === 'cubo_magico';
$gender = $isMixedModality ? 'masculino' : ($_GET['gender'] ?? 'masculino');

if (!in_array($modality, allowed_modalities(), true)) {
    $modality = 'futsal';
}

$isMixedModality = is_mixed_modality($modality);
$isCubeMagic = $modality === 'cubo_magico';

if (!$isMixedModality && !in_array($gender, ['masculino', 'feminino'], true)) {
    $gender = 'masculino';
}

$selectedBracketType = $eventId ? bracket_type_for_event_modality($eventId, $modality) : default_bracket_type_for_modality($modality);
$isKnockoutBracket = $selectedBracketType === 'simple';
$isRoundRobinBracket = $selectedBracketType === 'round_robin';
$scoreType = score_type_for_modality($modality);

$finalsComplete = $eventId ? modality_standings_are_complete($eventId, $modality, $gender) : false;
if ($eventId && game_is_new_modality($modality)) {
    $finalStmt = db()->prepare("SELECT COUNT(*) FROM matches WHERE event_id = ? AND modality = ? AND stage = 'final' AND status IN ('encerrada','wo')");
    $finalStmt->execute([$eventId, $modality]);
    $finalsComplete = (int) $finalStmt->fetchColumn() > 0;
}
$view = $_GET['view'] ?? 'points';

// Restrict to 'points' view if finals are not complete
if ($view === 'overall' && !$finalsComplete) {
    $view = 'points';
}

if ($isKnockoutBracket && $finalsComplete) {
    $view = 'overall';
}

$groups = [];
$standingsByGroup = [];

if ($eventId) {
    if (game_is_new_modality($modality)) {
        require_once __DIR__ . '/../services/GameCompetition.php';
        if ($isKnockoutBracket || $view === 'overall') {
            $standingsByGroup['Geral'] = game_knockout_standings($eventId, $modality);
        } else {
            $players = game_players($eventId, $modality);
            $groupNames = array_unique(array_filter(array_column($players, 'group_name')));
            sort($groupNames);
            foreach ($groupNames as $groupName) $standingsByGroup[$groupName] = game_group_standings($eventId, $modality, $groupName);
        }
    } elseif ($modality === 'cubo_magico') {
        $standingsByGroup['Geral'] = calculate_cubo_magico_standings($eventId);
    } elseif ($isKnockoutBracket) {
        $standingsByGroup['Geral'] = calculate_knockout_standings($eventId, $modality, $gender);
    } elseif ($isRoundRobinBracket) {
        $standingsByGroup['Geral'] = $modality === 'quiz'
            ? calculate_quiz_collective_standings($eventId)
            : calculate_standings($eventId, $modality, null, $gender);
    } elseif ($view === 'overall') {
        $standingsByGroup['Geral'] = calculate_standings($eventId, $modality, null, $gender);
    } else {
        $groupsStmt = db()->prepare('SELECT DISTINCT group_name FROM teams WHERE event_id = ? AND modality = ? AND gender = ? AND group_name <> "" ORDER BY group_name');
        $groupsStmt->execute([$eventId, $modality, $gender]);
        $groups = array_column($groupsStmt->fetchAll(), 'group_name');
        $allStandings = calculate_standings($eventId, $modality, null, $gender);

        foreach ($groups as $group) {
            $standingsByGroup[$group] = array_values(array_filter(
                $allStandings,
                static fn(array $row): bool => (string) ($row['group_name'] ?? '') === (string) $group
            ));
        }
    }
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Tabelas de classificação</h1>
        <p class="text-muted mb-0"><?= $modality === 'cubo_magico' ? 'Classificação por média de tempo e tentativas dos participantes.' : (is_individual_modality($modality) ? 'Modalidade individual - classificação por atletas.' : 'Critérios: pontos, saldo de gols, gols marcados, vitórias e confronto direto.') ?></p>
    </div>
    <?php if ($eventId && has_role(['admin'])): ?>
        <a class="btn btn-outline-secondary" href="index.php?page=export_csv&event_id=<?= $eventId ?>&modality=<?= e($modality) ?>">Exportar CSV</a>
    <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="page" value="standings">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="col-md-4">
        <select class="form-select" name="event_id" onchange="this.form.submit()">
            <?php foreach ($events as $event): ?>
                <option value="<?= (int) $event['id'] ?>" <?= $eventId === (int) $event['id'] ? 'selected' : '' ?>><?= e($event['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select" name="modality" onchange="this.form.submit()">
            <?php foreach ($modalities as $item): ?>
                <option value="<?= e($item) ?>" <?= $modality === $item ? 'selected' : '' ?>><?= e(modality_label($item)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!$isMixedModality): ?>
    <div class="col-md-3">
        <select class="form-select" name="gender" onchange="this.form.submit()">
            <option value="masculino" <?= $gender === 'masculino' ? 'selected' : '' ?>>Masculino</option>
            <option value="feminino" <?= $gender === 'feminino' ? 'selected' : '' ?>>Feminino</option>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-md-2"><button class="btn btn-primary">Ver</button></div>
</form>

<nav class="mb-3">
    <ul class="nav nav-tabs">
        <li class="nav-item">
            <a class="nav-link <?= $view === 'points' ? 'active' : '' ?>" href="index.php?page=standings&event_id=<?= $eventId ?>&modality=<?= e($modality) ?>&view=points">Tabela de pontuação</a>
        </li>
        <li class="nav-item">
            <?php if ($finalsComplete): ?>
                <a class="nav-link <?= $view === 'overall' ? 'active' : '' ?>" href="index.php?page=standings&event_id=<?= $eventId ?>&modality=<?= e($modality) ?>&view=overall">Classificação geral</a>
            <?php else: ?>
                <a class="nav-link disabled" href="#" onclick="return false;" title="Disponível após as finais serem encerradas">Classificação mata-mata</a>
            <?php endif; ?>
        </li>
    </ul>
</nav>

<div class="standings-legend mb-3">
    <?php if ($modality === 'cubo_magico'): ?>
        <span><strong>Media</strong> menor tempo medio</span>
        <span><strong>Tentativas</strong> tempos registrados</span>
        <span><strong>#</strong> ordem por desempenho</span>
    <?php elseif ($isKnockoutBracket): ?>
        <span><strong>Pts</strong> pontos por vitoria</span>
        <span><strong>J</strong> confrontos disputados</span>
        <span><strong>V</strong> vitorias</span>
        <span><strong>D</strong> derrotas</span>
        <span><strong>Mata-mata</strong> ranking por fase alcancada</span>
    <?php elseif ($modality === 'quiz'): ?>
        <span><strong>Pts</strong> pontuacao do quiz</span>
        <span><strong>J</strong> rodada disputada</span>
        <span><strong>V</strong> melhor pontuacao</span>
        <span><strong>D</strong> demais equipes</span>
    <?php elseif ($isRoundRobinBracket): ?>
        <span><strong>Pts</strong> pontos na tabela</span>
        <span><strong>J</strong> jogos</span>
        <span><strong>V</strong> vitorias</span>
        <span><strong>D</strong> derrotas</span>
        <span><strong>SG</strong> saldo do placar</span>
    <?php elseif ($scoreType === 'sets'): ?>
        <span><strong>Pts</strong> pontos na tabela</span>
        <span><strong>J</strong> jogos</span>
        <span><strong>V</strong> vitorias</span>
        <span><strong>D</strong> derrotas</span>
        <span><strong>SG</strong> saldo de sets</span>
    <?php elseif ($scoreType === 'pontos'): ?>
        <span><strong>Pts</strong> pontos na tabela</span>
        <span><strong>J</strong> jogos</span>
        <span><strong>V</strong> vitorias</span>
        <span><strong>D</strong> derrotas</span>
        <span><strong>SG</strong> saldo de pontos</span>
    <?php else: ?>
    <span><strong>Pts</strong> pontos</span>
    <span><strong>J</strong> jogos</span>
    <span><strong>V</strong> vitórias</span>
    <span><strong>D</strong> derrotas</span>
    <span><strong>SG</strong> saldo</span>
    <?php if ($view === 'points' && !$isCubeMagic && !is_individual_modality($modality)): ?>
        <?php if (($selectedEvent['advancement_mode'] ?? 'global') === 'per_group'): ?>
            <span class="legend-qualified">Classificação definida por grupo</span>
        <?php else: ?>
            <span class="legend-qualified">Classificam: <?= (int) ($selectedEvent['teams_advancing_per_group'] ?? 2) ?> por grupo</span>
        <?php endif; ?>
        <?php if (($selectedEvent['advancement_mode'] ?? 'global') !== 'per_group' && (int) ($selectedEvent['best_thirds_count'] ?? 0) > 0): ?>
            <span class="legend-repechage">Repescagem: <?= (int) $selectedEvent['best_thirds_count'] ?> melhores 3º</span>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($finalsComplete || (($selectedEvent['status'] ?? '') === 'encerrado')): ?>
        <span class="legend-finished">Classificação finalizada</span>
    <?php endif; ?>
</div>

<?php foreach ($standingsByGroup as $group => $rows): ?>
    <div class="card standings-card mb-4">
        <div class="card-header"><h5 class="mb-0 fw-bold text-dark"><?= $view === 'overall' ? 'Classificação geral' : 'Grupo ' . e($group) ?></h5></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 standings-table responsive-table">
                <?php if ($modality === 'cubo_magico'): ?>
                    <thead><tr><th class="text-center">#</th><th>Aluno</th><th>Turma</th><th class="text-center">Média</th><th class="text-center">Tentativas</th></tr></thead>
                <?php elseif (game_is_new_modality($modality)): ?>
                    <thead><tr><th class="text-center">#</th><th>Aluno</th><th>Turma</th><th class="text-center">Pts</th><th class="text-center">J</th><th class="text-center">V</th><th class="text-center">E</th><th class="text-center">D</th><th class="text-center">GP</th><th class="text-center">GC</th><th class="text-center">SG</th></tr></thead>
                <?php elseif (is_individual_modality($modality)): ?>
                    <thead><tr><th class="text-center">#</th><th>Atleta</th><th>Turma</th><th class="text-center">V</th><th class="text-center">D</th><th class="text-center">Pts</th></tr></thead>
                <?php else: ?>
                    <thead><tr><th class="text-center">#</th><th>Time</th><?php if ($view === 'overall'): ?><th>Grupo</th><?php endif; ?><th class="text-center">Pts</th><th class="text-center">J</th><th class="text-center">V</th><th class="text-center">D</th><th class="text-center">GP</th><th class="text-center">GC</th><th class="text-center">SG</th></tr></thead>
                <?php endif; ?>
                <tbody>
                <?php foreach ($rows as $index => $row):
                    $posClass = match($index) {
                        0 => 'position-1',
                        1 => 'position-2',
                        2 => 'position-3',
                        default => 'position-other'
                    };
                ?>
                    <?php if ($modality === 'cubo_magico'): ?>
                        <tr>
                            <td data-label="#" class="text-center"><span class="position-badge <?= $posClass ?>"><?= $index + 1 ?></span></td>
                            <td data-label="Aluno" class="team-name"><?= e($row['player_name']) ?></td>
                            <td data-label="Turma" class="text-muted"><?= e($row['class_name'] ?? '-') ?></td>
                            <td data-label="Média" class="text-center"><?= $row['average'] !== null ? number_format($row['average'], 2, ',', '.') . 's' : '<span class="text-muted">-</span>' ?></td>
                            <td data-label="Tentativas" class="text-center"><?= $row['attempt_text'] ?></td>
                        </tr>
                    <?php elseif (game_is_new_modality($modality)): ?>
                        <tr><td data-label="#" class="text-center"><span class="position-badge <?= $posClass ?>"><?= $index + 1 ?></span></td><td data-label="Aluno" class="team-name"><?= e($row['player_name']) ?></td><td data-label="Turma"><?= e($row['class_name']) ?></td><td data-label="Pts" class="text-center"><?= (int) $row['P'] ?></td><td data-label="J" class="text-center"><?= (int) $row['J'] ?></td><td data-label="V" class="text-center"><?= (int) $row['V'] ?></td><td data-label="E" class="text-center"><?= (int) ($row['E'] ?? 0) ?></td><td data-label="D" class="text-center"><?= (int) $row['D'] ?></td><td data-label="GP" class="text-center"><?= (int) $row['GP'] ?></td><td data-label="GC" class="text-center"><?= (int) $row['GC'] ?></td><td data-label="SG" class="text-center"><?= (int) $row['SG'] ?></td></tr>
                    <?php elseif (is_individual_modality($modality)): ?>
                        <tr>
                            <td data-label="#" class="text-center"><span class="position-badge <?= $posClass ?>"><?= $index + 1 ?></span></td>
                            <td data-label="Atleta" class="team-name"><?= e($row['player_name'] ?? $row['team_name'] ?? '-') ?></td>
                            <td data-label="Turma" class="text-muted"><?= e($row['class_name'] ?? '-') ?></td>
                            <td data-label="V" class="text-center"><?= (int) $row['V'] ?></td>
                            <td data-label="D" class="text-center"><?= (int) $row['D'] ?></td>
                            <td data-label="Pts" class="text-center points-cell">
                                <?= (int) $row['P'] ?>
                                <?php if ((int) ($row['occurrence_deductions'] ?? 0) > 0): ?>
                                    <div class="small text-danger">-<?= (int) $row['occurrence_deductions'] ?> ocorr.</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <td data-label="#" class="text-center"><span class="position-badge <?= $posClass ?>"><?= $index + 1 ?></span></td>
                            <td data-label="Time" class="team-name"><?= e($row['team_name']) ?></td>
                            <?php if ($view === 'overall'): ?><td data-label="Grupo"><?= e($row['group_name'] ?? '') ?></td><?php endif; ?>
                            <td data-label="Pts" class="text-center points-cell">
                                <?= (int) $row['P'] ?>
                                <?php if ((int) ($row['occurrence_deductions'] ?? 0) > 0): ?>
                                    <div class="small text-danger">-<?= (int) $row['occurrence_deductions'] ?> ocorr.</div>
                                <?php endif; ?>
                            </td>
                            <td data-label="J" class="text-center"><?= (int) $row['J'] ?></td>
                            <td data-label="V" class="text-center"><?= (int) $row['V'] ?></td>
                            <td data-label="D" class="text-center"><?= (int) $row['D'] ?></td>
                            <td data-label="GP" class="text-center"><?= (int) $row['GP'] ?></td>
                            <td data-label="GC" class="text-center"><?= (int) $row['GC'] ?></td>
                            <td data-label="SG" class="text-center <?= (int)$row['SG'] > 0 ? 'text-success' : ((int)$row['SG'] < 0 ? 'text-danger' : '') ?>"><?= (int) $row['SG'] > 0 ? '+' : '' ?><?= (int) $row['SG'] ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= game_is_new_modality($modality) ? 11 : ($modality === 'cubo_magico' ? 5 : (is_individual_modality($modality) ? 6 : ($view === 'overall' ? 10 : 9))) ?>" class="text-muted text-center py-3">
                            <?= $modality === 'cubo_magico' ? 'Nenhum participante cadastrado.' : (is_individual_modality($modality) ? 'Nenhum atleta cadastrado.' : ($view === 'overall' ? 'Nenhum time cadastrado.' : 'Nenhum time neste grupo.')) ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!$standingsByGroup): ?>
    <div class="alert alert-info">Nenhuma tabela disponível.</div>
<?php endif; ?>
<?php public_page_cache_finish($publicCacheKey, 60, $publicCacheEnabled); ?>
