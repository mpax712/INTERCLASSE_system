<?php
$user = current_user();
$canCreateEvents = has_role(['admin']);
$errors = [];
$allowedStatuses = ['rascunho', 'inscricoes', 'em_andamento', 'encerrado'];
$allowedModalities = allowed_modalities();
ensure_interclasse_schema_updates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($canCreateEvents) {
        http_response_code(428);
        echo '<div class="alert alert-warning">Use o gerenciamento administrativo para analisar e confirmar esta alteração.</div>';
        return;
    }
    verify_csrf();
    $action = $_POST['action'] ?? 'create';

    if (!$canCreateEvents) {
        http_response_code(403);
        echo '<div class="alert alert-danger">Acesso negado.</div>';
        return;
    }

    if ($action === 'status') {
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $status = $_POST['status'] ?? 'inscricoes';

        if ($eventId && in_array($status, $allowedStatuses, true) && can_manage_event_structure($eventId)) {
            $currentStmt = db()->prepare('SELECT status FROM events WHERE id = ?');
            $currentStmt->execute([$eventId]);
            $currentStatus = $currentStmt->fetchColumn();

            if ($currentStatus === 'encerrado') {
                flash('Evento encerrado. Status não pode ser alterado.', 'warning');
            } else {
                $stmt = db()->prepare('UPDATE events SET status = ? WHERE id = ?');
                $stmt->execute([$status, $eventId]);
                flash('Status do evento atualizado.');
            }
        } else {
            flash('Não foi possível atualizar este evento.', 'danger');
        }

        redirect('index.php?page=events');
    }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?: null;
    $place = trim($_POST['place'] ?? '');
    $groupCount = max(1, (int) ($_POST['group_count'] ?? 2));
    $bestThirdsCount = max(0, (int) ($_POST['best_thirds_count'] ?? 0));
    $rosterEditUntil = $_POST['roster_edit_until'] ? str_replace('T', ' ', $_POST['roster_edit_until']) . ':00' : null;
    $modalities = $_POST['modalities'] ?? [];

    if (strlen($name) < 3) {
        $errors[] = 'Informe o nome do evento.';
    }
    if (!$startDate) {
        $errors[] = 'Informe a data inicial.';
    }
    if (!$modalities) {
        $errors[] = 'Escolha pelo menos uma modalidade.';
    }

    if (!$errors) {
        try {
        db()->beginTransaction();
        $stmt = db()->prepare(
            'INSERT INTO events (name, description, created_by, start_date, end_date, place, group_count, best_thirds_count, roster_edit_until, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "inscricoes")'
        );
        $stmt->execute([$name, $description, $user['id'], $startDate, $endDate, $place, $groupCount, $bestThirdsCount, $rosterEditUntil]);
        $eventId = (int) db()->lastInsertId();

        $insertModality = db()->prepare(
            'INSERT INTO event_modalities (event_id, modality, score_type, set_best_of, set_points, quarters_count, max_players_per_team, bracket_type, rules_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach (array_unique($modalities) as $modality) {
            if (!in_array($modality, $allowedModalities, true)) {
                continue;
            }

            $insertModality->execute([
                $eventId,
                $modality,
                score_type_for_modality($modality),
                $modality === 'volei' ? (int) ($_POST['set_best_of'] ?? 3) : null,
                $modality === 'volei' ? (int) ($_POST['set_points'] ?? 25) : null,
                $modality === 'basquete' ? (int) ($_POST['quarters_count'] ?? 4) : null,
                game_is_new_modality($modality) ? min(32, max(1, (int) ($_POST['player_limits'][$modality] ?? default_player_limit_for_modality($modality)))) : max(1, (int) ($_POST['player_limits'][$modality] ?? default_player_limit_for_modality($modality))),
                default_bracket_type_for_modality($modality),
                json_encode(validate_game_rules($modality, $_POST['rules'][$modality] ?? []), JSON_UNESCAPED_UNICODE),
            ]);
        }

        db()->commit();
        flash('Evento criado com sucesso.');
        redirect('index.php?page=event&id=' . $eventId);
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Não foi possível criar o evento.';
        }
    }
}

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$modalityFilter = $_GET['modality'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$quickFilter = $_GET['quick'] ?? '';
$params = [];
$where = [];

if ($q !== '') {
    $where[] = '(e.name LIKE ? OR e.description LIKE ? OR e.place LIKE ? OR u.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'e.status = ?';
    $params[] = $statusFilter;
}

if (in_array($modalityFilter, $allowedModalities, true)) {
    $where[] = 'EXISTS (SELECT 1 FROM event_modalities em_filter WHERE em_filter.event_id = e.id AND em_filter.modality = ?)';
    $params[] = $modalityFilter;
}

if ($dateFrom !== '') {
    $where[] = 'e.start_date >= ?';
    $params[] = $dateFrom;
}

if ($quickFilter === 'live') {
    $where[] = 'COALESCE(mc.running_matches, 0) > 0';
} elseif ($quickFilter === 'open') {
    $where[] = 'e.status = "inscricoes"';
} elseif ($quickFilter === 'no_matches') {
    $where[] = 'COALESCE(mc.match_count, 0) = 0';
} elseif ($quickFilter === 'closed') {
    $where[] = 'e.status = "encerrado"';
}

$publicCacheKey = public_page_cache_key('events:list', [
    'q' => $q,
    'status' => $statusFilter,
    'modality' => $modalityFilter,
    'date_from' => $dateFrom,
    'quick' => $quickFilter,
]);
if (public_page_cache_serve($publicCacheKey)) {
    return;
}
$publicCacheEnabled = public_page_cache_start($publicCacheKey);

$sql = 'SELECT e.*, u.name AS professor_name,
        mods.modalities,
        COALESCE(tc.team_count, 0) AS team_count,
        COALESCE(mc.match_count, 0) AS match_count,
        COALESCE(mc.running_matches, 0) AS running_matches,
        COALESCE(pc.player_count, 0) AS player_count
    FROM events e
    JOIN users u ON u.id = e.created_by
    LEFT JOIN (
        SELECT event_id, string_agg(modality::text, "," ORDER BY array_position(ARRAY["futsal", "handbol", "queimada", "volei", "basquete", "cubo_magico", "xadrez", "quiz", "fifa", "clash_royale"], modality::text)) AS modalities
        FROM event_modalities
        GROUP BY event_id
    ) mods ON mods.event_id = e.id
    LEFT JOIN (
        SELECT event_id, COUNT(*) AS team_count
        FROM teams
        GROUP BY event_id
    ) tc ON tc.event_id = e.id
    LEFT JOIN (
        SELECT event_id, COUNT(*) AS match_count, SUM(CASE WHEN status = "em_andamento" THEN 1 ELSE 0 END) AS running_matches
        FROM matches
        GROUP BY event_id
    ) mc ON mc.event_id = e.id
    LEFT JOIN (
        SELECT t.event_id, COUNT(p.id) AS player_count
        FROM teams t
        LEFT JOIN players p ON p.team_id = t.id
        GROUP BY t.event_id
    ) pc ON pc.event_id = e.id';

$where[] = 'e.archived = 0';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY e.start_date DESC, e.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$totals = db()->query(
    'SELECT
        COUNT(*) AS total_events,
        SUM(CASE WHEN status = "inscricoes" THEN 1 ELSE 0 END) AS open_events,
        (SELECT COUNT(*) FROM matches WHERE starts_at >= CURRENT_DATE AND starts_at < CURRENT_DATE + INTERVAL "1 day") AS today_matches,
        (SELECT COUNT(*) FROM players) AS total_players
     FROM events
     WHERE archived = 0'
)->fetch();
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Eventos do Interclasse</h1>
        <p class="text-muted mb-0">Catálogo público, filtros e operação dos eventos em um só lugar.</p>
    </div>
    <?php if ($canCreateEvents): ?>
        <a class="btn btn-primary" href="index.php?page=create_event">Novo evento</a>
    <?php endif; ?>
</div>

<div class="quick-filter-bar mb-3">
    <?php
    $quickLinks = [
        '' => 'Todos',
        'live' => 'Ao vivo',
        'open' => 'Inscrições abertas',
        'no_matches' => 'Sem jogos',
        'closed' => 'Encerrados',
    ];
    foreach ($quickLinks as $value => $label):
        $query = array_filter([
            'page' => 'events',
            'quick' => $value ?: null,
            'q' => $q ?: null,
            'status' => $statusFilter ?: null,
            'modality' => $modalityFilter ?: null,
            'date_from' => $dateFrom ?: null,
        ], fn($item) => $item !== null && $item !== '');
    ?>
        <a class="<?= $quickFilter === $value ? 'active' : '' ?>" href="index.php?<?= e(http_build_query($query)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="stats-grid mb-3">
    <div class="stat-tile">
        <span>Eventos</span>
        <strong><?= (int) ($totals['total_events'] ?? 0) ?></strong>
    </div>
    <div class="stat-tile">
        <span>Inscrições abertas</span>
        <strong><?= (int) ($totals['open_events'] ?? 0) ?></strong>
    </div>
    <div class="stat-tile">
        <span>Jogos hoje</span>
        <strong><?= (int) ($totals['today_matches'] ?? 0) ?></strong>
    </div>
    <div class="stat-tile">
        <span>Jogadores</span>
        <strong><?= (int) ($totals['total_players'] ?? 0) ?></strong>
    </div>
</div>

<div class="card shadow-sm mb-3 filter-card">
    <div class="card-body">
        <form class="row g-2 align-items-end compact-filter-form" method="get">
            <input type="hidden" name="page" value="events">
            <input type="hidden" name="quick" value="<?= e($quickFilter) ?>">
            <div class="col-lg-4">
                <label class="form-label">Buscar</label>
                <input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Evento, local ou responsável">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">Todos</option>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(event_status_label($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label">Modalidade</label>
                <select class="form-select" name="modality">
                    <option value="">Todas</option>
                    <?php foreach ($allowedModalities as $modality): ?>
                        <option value="<?= e($modality) ?>" <?= $modalityFilter === $modality ? 'selected' : '' ?>><?= e(modality_label($modality)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label class="form-label">A partir de</label>
                <input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-sm-6 col-lg-2 d-grid">
                <button class="btn btn-primary">Filtrar</button>
            </div>
            <div class="col-12 filter-actions-row">
                <a class="btn btn-sm btn-outline-secondary" href="index.php?page=events">Limpar filtros</a>
            </div>
        </form>
    </div>
</div>

<div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <strong><?= $user ? e(role_label($user['role'])) : 'Visitante' ?>:</strong>
        <?php if (!$user): ?>
            acompanhe eventos, tabelas e placares públicos.
        <?php elseif ($user['role'] === 'admin'): ?>
            gerencie eventos, usuários, turmas, status e arquivamento.
        <?php elseif ($user['role'] === 'professor'): ?>
            acompanhe os eventos sob sua gestão e controle partidas.
        <?php else: ?>
            acompanhe inscrições, jogos e classificações disponíveis.
        <?php endif; ?>
    </div>
    <?php if (!$user): ?>
        <a class="btn btn-sm btn-outline-primary" href="index.php?page=login">Entrar</a>
    <?php endif; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>


<div class="event-card-list">
    <?php foreach ($events as $event): ?>
        <?php
        $modalities = array_filter(explode(',', (string) ($event['modalities'] ?? '')));
        $manager = $canCreateEvents && can_manage_event_structure((int) $event['id']);
        ?>
        <article class="card event-card shadow-sm <?= (int) ($event['running_matches'] ?? 0) > 0 ? 'event-card-live' : '' ?> <?= $event['status'] === 'inscricoes' ? 'event-card-open' : '' ?> <?= (int) $event['match_count'] === 0 ? 'event-card-empty-matches' : '' ?>">
            <div class="card-body">
                <div class="event-card-top">
                    <div>
                        <div class="event-card-tags">
                            <span class="<?= e(status_pill_class($event['status'])) ?>"><?= e(event_status_label($event['status'])) ?></span>
                            <?php if ((int) ($event['running_matches'] ?? 0) > 0): ?>
                                <span class="operation-chip chip-live"><?= (int) $event['running_matches'] ?> ao vivo</span>
                            <?php elseif ($event['status'] === 'inscricoes'): ?>
                                <span class="operation-chip chip-open">Inscrições</span>
                            <?php elseif ((int) $event['match_count'] === 0): ?>
                                <span class="operation-chip chip-muted">Sem jogos</span>
                            <?php endif; ?>
                            <?php foreach ($modalities as $modality): ?>
                                <span class="modality-chip"><?= e(modality_label($modality)) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <h2 class="event-card-title"><?= e($event['name']) ?></h2>
                        <p class="event-card-subtitle"><?= e($event['place'] ?: 'Local a definir') ?></p>
                    </div>
                    <div class="event-card-actions">
                        <a class="btn btn-sm btn-primary" href="index.php?page=event&id=<?= (int) $event['id'] ?>">Abrir evento</a>
                    </div>
                </div>

                <div class="event-meta-grid">
                    <div class="event-meta-item">
                        <span>Período</span>
                        <strong><?= e(date('d/m/Y', strtotime($event['start_date']))) ?><?= $event['end_date'] ? ' até ' . e(date('d/m/Y', strtotime($event['end_date']))) : '' ?></strong>
                    </div>
                    <div class="event-meta-item">
                        <span>Times</span>
                        <strong><?= (int) $event['team_count'] ?></strong>
                    </div>
                    <div class="event-meta-item">
                        <span>Jogos</span>
                        <strong><?= (int) $event['match_count'] ?></strong>
                        <?php if ((int) ($event['running_matches'] ?? 0) > 0): ?>
                            <small class="text-success d-block"><?= (int) $event['running_matches'] ?> ao vivo</small>
                        <?php endif; ?>
                    </div>
                    <div class="event-meta-item">
                        <span>Jogadores</span>
                        <strong><?= (int) $event['player_count'] ?></strong>
                    </div>
                </div>

                <?php if (trim((string) $event['description']) !== ''): ?>
                    <p class="event-description"><?= e($event['description']) ?></p>
                <?php endif; ?>

                <div class="event-card-footer">
                    <span class="small text-muted">Elencos até <?= e(format_datetime($event['roster_edit_until'])) ?></span>
                    <div class="event-admin-actions">
                        <?php if ($manager && ($canCreateEvents || $event['status'] !== 'encerrado')): ?>
                            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="status">
                                <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
                                <select class="form-select form-select-sm status-select" name="status">
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?= e($status) ?>" <?= $event['status'] === $status ? 'selected' : '' ?>><?= e(event_status_label($status)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary">Atualizar</button>
                            </form>
                        <?php endif; ?>
                        <div class="event-danger-actions">
                            <?php if ($manager && !$canCreateEvents && $event['status'] === 'encerrado' && !$event['archived']): ?>
                                <button class="btn btn-sm btn-outline-warning archive-btn" data-event-id="<?= (int) $event['id'] ?>">Arquivar</button>
                            <?php endif; ?>
                            <?php if ($user && $user['role'] === 'admin'): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-admin-edit="events" data-id="<?= (int) $event['id'] ?>">Editar evento</button>
                                <button type="button" class="btn btn-sm btn-outline-warning" data-admin-op="archive" data-admin-entity="events" data-id="<?= (int) $event['id'] ?>">Arquivar</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php if (!$events): ?>
    <div class="empty-state">
        <div class="empty-state-icon">!</div>
        <h2 class="h5">Nenhum evento encontrado</h2>
        <p class="text-muted mb-0">Ajuste os filtros ou aguarde um administrador criar o próximo interclasse.</p>
    </div>
<?php endif; ?>

<script>
const csrfToken = <?= json_encode($canCreateEvents ? csrf_token() : '', JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.archive-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const eventId = this.dataset.eventId;
        if (await askConfirmation({title: 'Arquivar evento', message: 'O evento sairá da lista ativa e ficará disponível no histórico.', confirmText: 'Arquivar', variant: 'btn-warning'})) {
            fetch('api/events.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    id: eventId,
                    archived: true,
                    csrf_token: csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    location.reload();
                } else {
                    askConfirmation({title: 'Erro', message: data.error || 'Erro ao arquivar evento', confirmText: 'Entendi', variant: 'btn-danger'});
                }
            })
            .catch(error => {
                askConfirmation({title: 'Erro', message: 'Erro ao arquivar evento', confirmText: 'Entendi', variant: 'btn-danger'});
            });
        }
    });
});

document.querySelectorAll('.delete-event-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const eventId = this.dataset.eventId;
        if (await askConfirmation({title: 'Excluir evento', message: 'Esta ação não pode ser desfeita e removerá dados vinculados ao evento.', confirmText: 'Excluir', variant: 'btn-danger'})) {
            fetch('api/events.php?id=' + eventId, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    csrf_token: csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    location.reload();
                } else {
                    askConfirmation({title: 'Erro', message: data.error || 'Erro ao excluir evento', confirmText: 'Entendi', variant: 'btn-danger'});
                }
            })
            .catch(error => {
                askConfirmation({title: 'Erro', message: 'Erro ao excluir evento', confirmText: 'Entendi', variant: 'btn-danger'});
            });
        }
    });
});

// Confirmation for closing event
document.querySelectorAll('form[method="post"]').forEach(form => {
    form.addEventListener('submit', function(e) {
        const statusSelect = form.querySelector('select[name="status"]');
        if (statusSelect && statusSelect.value === 'encerrado') {
            e.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('closeEventModal'));
            document.getElementById('confirmInput').value = '';
            modal.show();
            document.getElementById('confirmCloseBtn').onclick = function() {
                const input = document.getElementById('confirmInput');
                if (input.value.trim().toLowerCase() === 'encerrar') {
                    modal.hide();
                    form.submit();
                } else {
                    askConfirmation({title: 'Confirmação necessária', message: 'Digite "encerrar" para confirmar.', confirmText: 'Entendi', variant: 'btn-warning'});
                }
            };
        }
    });
});
</script>

<!-- Modal for closing event -->
<div class="modal fade" id="closeEventModal" tabindex="-1" aria-labelledby="closeEventModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="closeEventModalLabel">Confirmar Encerramento do Evento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Você está prestes a encerrar este evento. Esta ação não pode ser desfeita.</p>
                <p>Digite <strong>"encerrar"</strong> para confirmar:</p>
                <input type="text" class="form-control" id="confirmInput" placeholder="Digite 'encerrar'">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="confirmCloseBtn">Encerrar Evento</button>
            </div>
        </div>
    </div>
</div>
<?php public_page_cache_finish($publicCacheKey, 60, $publicCacheEnabled); ?>
