<?php
require_roles(['admin']);
$adminWorkspaceLoaded = true;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(428);
    echo '<div class="alert alert-warning">Use a análise de impacto e confirme a alteração nas ferramentas administrativas.</div>';
    return;
}
$adminContext = ['eventId'=>(int)($_GET['event_id']??0), 'modality'=>$_GET['modality']??'',
    'gender'=>$_GET['gender']??'masculino', 'teamId'=>(int)($_GET['team_id']??0), 'editMatchId'=>(int)($_GET['edit_match_id']??0),
    'tab'=>$adminInitialTab??(in_array($_GET['tab']??'', ['matches','players','teams','classes','events','settings','history'],true)?$_GET['tab']:'matches')];
?>
<div data-admin-panel class="admin-competition-panel">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div><h1 class="h3">Administração dos campeonatos</h1><p class="text-muted">Corrija inscrições, confrontos e resultados com análise das consequências e histórico recuperável.</p></div>
        <a class="btn btn-outline-secondary" href="index.php?page=events">Eventos</a>
    </div>
    <div data-admin-notice role="status"></div>
    <div class="row g-2 mb-3">
        <label class="col-md-5">Evento<select data-admin-scope="event" class="form-select"></select></label>
        <label class="col-md-4">Modalidade<select data-admin-scope="modality" class="form-select"></select></label>
        <label class="col-md-3">Gênero<select data-admin-scope="gender" class="form-select"><option value="masculino">Masculino</option><option value="feminino">Feminino</option></select></label>
    </div>
    <nav class="d-flex gap-2 flex-wrap mb-3" aria-label="Ferramentas administrativas">
        <?php foreach (['matches'=>'Partidas','players'=>'Jogadores','teams'=>'Inscrições e equipes','classes'=>'Turmas globais','events'=>'Eventos','settings'=>'Regras e formato','history'=>'Histórico e recuperação'] as $key=>$label): ?>
        <button type="button" class="btn btn-outline-primary" data-admin-tab="<?= e($key) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
    </nav>
    <div class="d-flex gap-2 flex-wrap mb-3">
        <button type="button" class="btn btn-primary" data-admin-create>Novo registro</button>
        <button type="button" class="btn btn-outline-primary" data-admin-regenerate>Gerar / reorganizar confrontos</button>
        <label class="ms-auto"><input type="checkbox" data-admin-show-archived> Mostrar arquivados</label>
    </div>
    <input type="search" class="form-control mb-3" data-admin-search placeholder="Buscar por nome, fase, turma ou ID" aria-label="Buscar registros">
    <div data-admin-content aria-live="polite">Carregando registros…</div>
</div>
<script type="application/json" data-workspace-config="admin"><?= json_encode($adminContext,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) ?></script>
