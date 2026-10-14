<?php
require_roles(['admin']);

$allowedStatuses = ['rascunho', 'inscricoes', 'em_andamento', 'encerrado'];
$allowedModalities = allowed_modalities();

$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$modalityFilter = $_GET['modality'] ?? '';
$params = [];
$where = ['e.archived = 1'];

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

$sql = 'SELECT e.*, u.name AS responsible_name,
        mods.modalities,
        COALESCE(tc.team_count, 0) AS team_count,
        COALESCE(mc.match_count, 0) AS match_count,
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
        SELECT event_id, COUNT(*) AS match_count
        FROM matches
        GROUP BY event_id
    ) mc ON mc.event_id = e.id
    LEFT JOIN (
        SELECT t.event_id, COUNT(p.id) AS player_count
        FROM teams t
        LEFT JOIN players p ON p.team_id = t.id
        GROUP BY t.event_id
    ) pc ON pc.event_id = e.id';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY e.start_date DESC, e.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Eventos arquivados</h1>
        <p class="text-muted mb-0">Eventos encerrados e arquivados para consulta histórica.</p>
    </div>
    <a class="btn btn-outline-secondary" href="index.php?page=events">Voltar aos eventos ativos</a>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <input type="hidden" name="page" value="archived_events">
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
            <div class="col-sm-6 col-lg-2 d-grid">
                <button class="btn btn-primary">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="event-card-list">
    <?php foreach ($events as $event): ?>
        <?php
        $modalities = array_filter(explode(',', (string) ($event['modalities'] ?? '')));
        $manager = can_manage_event_structure((int) $event['id']);
        ?>
        <article class="card event-card shadow-sm">
            <div class="card-body">
                <div class="event-card-top">
                    <div>
                        <div class="event-card-tags">
                            <span class="<?= e(status_pill_class($event['status'])) ?>"><?= e(event_status_label($event['status'])) ?></span>
                            <span class="badge text-bg-secondary">Arquivado</span>
                            <?php foreach ($modalities as $modality): ?>
                                <span class="modality-chip"><?= e(modality_label($modality)) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <h2 class="event-card-title"><?= e($event['name']) ?></h2>
                        <p class="event-card-subtitle"><?= e($event['responsible_name']) ?> · <?= e($event['place'] ?: 'Local a definir') ?></p>
                    </div>
                    <div class="event-card-actions">
                        <a class="btn btn-sm btn-outline-primary" href="index.php?page=event&id=<?= (int) $event['id'] ?>">Abrir</a>
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
                    <?php if ($manager): ?>
                        <button class="btn btn-sm btn-outline-success unarchive-btn" data-event-id="<?= (int) $event['id'] ?>">Desarquivar</button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php if (!$events): ?>
    <div class="empty-state">
        <div class="empty-state-icon">!</div>
        <h2 class="h5">Nenhum evento arquivado</h2>
        <p class="text-muted mb-0">Ajuste os filtros ou arquive eventos encerrados na página de eventos.</p>
    </div>
<?php endif; ?>

<script>
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.unarchive-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const eventId = this.dataset.eventId;
        if (await askConfirmation({title: 'Desarquivar evento', message: 'Este evento voltará para a lista de eventos ativos.', confirmText: 'Desarquivar', variant: 'btn-success'})) {
            fetch('api/events.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    id: eventId,
                    archived: false,
                    csrf_token: csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    location.reload();
                } else {
                    askConfirmation({title: 'Erro', message: data.error || 'Erro ao desarquivar evento', confirmText: 'Entendi', variant: 'btn-danger'});
                }
            })
            .catch(error => {
                askConfirmation({title: 'Erro', message: 'Erro ao desarquivar evento', confirmText: 'Entendi', variant: 'btn-danger'});
            });
        }
    });
});
</script>
