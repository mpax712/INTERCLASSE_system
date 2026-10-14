<?php
$publicCacheKey = public_page_cache_key('class_standings', $_GET);
if (public_page_cache_serve($publicCacheKey)) {
    return;
}
$publicCacheEnabled = public_page_cache_start($publicCacheKey);

$events = db()->query('SELECT id, name FROM events ORDER BY start_date DESC')->fetchAll();
$eventId = (int) ($_GET['event_id'] ?? ($events[0]['id'] ?? 0));

$classStandings = [];
if ($eventId) {
    $classStandings = get_class_standings($eventId);
}
?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Classificação geral das turmas</h1>
        <p class="text-muted mb-0">Pontuação baseada na posição final em cada modalidade (mata-mata).</p>
    </div>
    <?php if ($eventId && has_role(['admin'])): ?>
        <button class="btn btn-success export-general-btn" data-event-id="<?= $eventId ?>">Exportar Súmula Geral</button>
    <?php endif; ?>
</div>

<div class="alert alert-info mb-3">
    <strong>Pontuação por posição:</strong> 1º lugar = 10 pontos | 2º lugar = 8 pontos | 3º lugar = 6 pontos | 4º lugar ou abaixo = 2 pontos (perde os 2 pontos se tiver W.O.)
</div>

<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="page" value="class_standings">
    <div class="col-md-4">
        <select class="form-select" name="event_id" onchange="this.form.submit()">
            <?php foreach ($events as $event): ?>
                <option value="<?= (int) $event['id'] ?>" <?= $eventId === (int) $event['id'] ? 'selected' : '' ?>><?= e($event['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-primary">Ver</button></div>
</form>

<?php if (!$classStandings): ?>
    <div class="alert alert-info">Nenhuma turma cadastrada neste evento.</div>
<?php else: ?>
    <?php $hasPoints = array_sum(array_column($classStandings, 'total_points')) > 0; ?>
    <?php if (!$hasPoints): ?>
        <div class="alert alert-warning mb-3">
            <strong>Atenção:</strong> As turmas estão cadastradas, mas ainda não há pontos. Os pontos serão calculados automaticamente quando as finais de cada modalidade forem encerradas.
        </div>
    <?php endif; ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold">Classificação geral</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 standings-table responsive-table">
                <thead><tr><th class="text-center">#</th><th>Turma</th><th class="text-center">Total de pontos</th><th>Detalhes por modalidade</th><?php if (has_role(['admin'])): ?><th class="text-end">Ações</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($classStandings as $index => $row): ?>
                    <tr>
                        <td data-label="#" class="text-center"><span class="position-badge position-<?= $index + 1 ?>"><?= $index + 1 ?></span></td>
                        <td data-label="Turma" class="team-name fw-bold"><?= e($row['class_name']) ?></td>
                        <td data-label="Total" class="text-center points-cell fw-bold"><?= (int) $row['total_points'] ?></td>
                        <td data-label="Detalhes" class="text-muted small"><?= e($row['details'] ?? 'Aguardando finais') ?></td>
                        <?php if (has_role(['admin'])): ?>
                            <td data-label="Ações" class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-warning deduct-points-btn" data-id="<?= (int) $row['id'] ?>" data-points="5" data-class="<?= e($row['class_name']) ?>">-5</button>
                                    <button class="btn btn-outline-danger deduct-points-btn" data-id="<?= (int) $row['id'] ?>" data-points="10" data-class="<?= e($row['class_name']) ?>">-10</button>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (has_role(['admin'])): ?>
<script>
const csrfToken = <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.deduct-points-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const id = this.dataset.id;
        const points = this.dataset.points;
        const className = this.dataset.class;
        
        if (await askConfirmation({title: 'Remover pontos', message: `Remover ${points} pontos da turma "${className}"?`, confirmText: 'Remover', variant: 'btn-danger'})) {
            fetch('api/class_standings.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    id: id,
                    deduct_points: points,
                    csrf_token: csrfToken
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    location.reload();
                } else {
                    askConfirmation({title: 'Erro', message: data.error || 'Erro ao remover pontos', confirmText: 'Entendi', variant: 'btn-danger'});
                }
            })
            .catch(error => {
                askConfirmation({title: 'Erro', message: 'Erro ao remover pontos', confirmText: 'Entendi', variant: 'btn-danger'});
            });
        }
    });
});
</script>
<?php endif; ?>
<?php public_page_cache_finish($publicCacheKey, 120, $publicCacheEnabled); ?>

<?php if ($eventId && has_role(['admin'])): ?>
<script>
// Handle general standings export button
const exportBtn = document.querySelector('.export-general-btn');
if (exportBtn) {
    exportBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const eventId = this.dataset.eventId;
        
        const url = `api/export.php?type=general&event_id=${eventId}&csrf_token=${encodeURIComponent(csrfToken)}`;
        window.location.href = url;
    });
}
</script>
<?php endif; ?>
