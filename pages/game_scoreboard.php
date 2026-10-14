<?php
$isFifa = $match['modality'] === 'fifa';
$rules = game_match_rules($match);
$score = decode_score_data($match['score_data'] ?? null, $match['modality']);
?>
<div class="page-title">
    <div><h1 class="h3 mb-1">Placar · <?= e(modality_label($match['modality'])) ?></h1><p class="text-muted mb-0"><?= e($match['event_name']) ?> · <?= e(stage_label($match['stage'])) ?></p></div>
    <a class="btn btn-outline-secondary" href="index.php?page=event&id=<?= (int) $match['event_id'] ?>&modality=<?= e($match['modality']) ?>#partidas-evento">Voltar às partidas</a>
</div>
<section class="card game-scoreboard" id="game-scoreboard" data-id="<?= (int) $match['id'] ?>" data-version="<?= (int) $match['version'] ?>" data-status="<?= e($match['status']) ?>" data-modality="<?= e($match['modality']) ?>" data-stage="<?= e($match['stage']) ?>" data-csrf="<?= e(csrf_token()) ?>" data-admin="<?= ($user['role'] ?? '') === 'admin' ? '1' : '0' ?>">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-3">
            <span class="<?= e(status_pill_class($match['status'])) ?>" id="game-status"><?= e(match_status_label($match['status'])) ?></span>
            <span class="small text-muted"><?= e(format_datetime($match['starts_at'])) ?> · <?= e($match['place'] ?: 'Local a definir') ?></span>
        </div>
        <div class="game-scoreboard-versus">
            <div><span>Participante 1</span><strong><?= e($match['home_name'] ?? 'A definir') ?></strong><small><?= e($match['home_class_name'] ?? '') ?></small><b id="game-home-score"><?= $match['status'] === 'agendada' ? '–' : (int) $match['home_score'] ?></b>
                <?php if ($isFifa): ?><div class="game-quick-controls"><button type="button" class="btn btn-success" data-action="adjust" data-team="home" data-amount="1">+ gol</button><button type="button" class="btn btn-outline-secondary" data-action="adjust" data-team="home" data-amount="-1">−</button></div><?php endif; ?>
            </div>
            <span class="game-versus-label">X</span>
            <div><span>Participante 2</span><strong><?= e($match['away_name'] ?? 'A definir') ?></strong><small><?= e($match['away_class_name'] ?? '') ?></small><b id="game-away-score"><?= $match['status'] === 'agendada' ? '–' : (int) $match['away_score'] ?></b>
                <?php if ($isFifa): ?><div class="game-quick-controls"><button type="button" class="btn btn-success" data-action="adjust" data-team="away" data-amount="1">+ gol</button><button type="button" class="btn btn-outline-secondary" data-action="adjust" data-team="away" data-amount="-1">−</button></div><?php endif; ?>
            </div>
        </div>
        <?php if (!$isFifa): ?>
            <div class="game-battles mt-4">
                <h2 class="h6">Batalhas · melhor de <?= (int) $rules['best_of'] ?></h2>
                <ol id="game-battle-list" class="mb-3"></ol>
                <div class="game-battle-form">
                    <label>Coroas de <?= e($match['home_name'] ?? 'participante 1') ?><input class="form-control" id="game-home-crowns" type="number" min="0" max="3" value="0"></label>
                    <label>Coroas de <?= e($match['away_name'] ?? 'participante 2') ?><input class="form-control" id="game-away-crowns" type="number" min="0" max="3" value="0"></label>
                    <label>Vencedor<select class="form-select" id="game-battle-winner"><option value="home"><?= e($match['home_name'] ?? 'Participante 1') ?></option><option value="away"><?= e($match['away_name'] ?? 'Participante 2') ?></option></select></label>
                    <label class="form-check align-self-end"><input class="form-check-input" type="checkbox" id="game-tiebreak"><span class="form-check-label">Desempate no jogo</span></label>
                    <button type="button" class="btn btn-primary" data-action="record_battle">Registrar batalha</button>
                    <button type="button" class="btn btn-outline-secondary" data-action="remove_battle">Desfazer última</button>
                </div>
            </div>
        <?php elseif ($match['stage'] !== 'grupo'): ?>
            <div class="game-penalties mt-4" id="game-penalties">
                <h2 class="h6">Desempate por pênaltis</h2>
                <p class="small text-muted">Preencha somente se o placar em gols terminar empatado.</p>
                <div class="d-flex gap-2"><label>Participante 1<input class="form-control" id="game-home-penalties" type="number" min="0" max="30"></label><label>Participante 2<input class="form-control" id="game-away-penalties" type="number" min="0" max="30"></label></div>
            </div>
        <?php endif; ?>
        <div class="game-score-actions mt-4">
            <button type="button" class="btn btn-primary" data-action="start">Iniciar</button>
            <button type="button" class="btn btn-outline-primary" data-action="await_result">Aguardar resultado</button>
            <button type="button" class="btn btn-outline-primary" data-action="resume">Retomar</button>
            <button type="button" class="btn btn-success" data-action="finish">Finalizar partida</button>
            <button type="button" class="btn btn-outline-warning" data-action="wo" data-team="home">W.O. participante 1</button>
            <button type="button" class="btn btn-outline-warning" data-action="wo" data-team="away">W.O. participante 2</button>
            <?php if (($user['role'] ?? '') === 'admin'): ?><button type="button" class="btn btn-outline-danger" data-action="cancel">Cancelar partida</button><?php endif; ?>
        </div>
        <p class="game-save-feedback mt-3 mb-0" id="game-feedback" role="status" aria-live="polite">Pronto para registrar.</p>
        <button type="button" class="btn btn-outline-primary mt-2" id="game-retry" hidden>Reenviar atualização</button>
        <?php if (($user['role'] ?? '') === 'admin' && in_array($match['status'], ['encerrada','wo'], true)): ?>
        <div class="mt-4 border-top pt-3"><h2 class="h6">Correção administrativa</h2><p class="small text-muted">Se a fase seguinte já começou, a correção será bloqueada.</p><a class="btn btn-outline-secondary" href="index.php?page=match_manager&event_id=<?= (int) $match['event_id'] ?>&modality=<?= e($match['modality']) ?>">Abrir correção no gerenciador</a></div>
        <?php endif; ?>
    </div>
</section>
<script src="assets/js/game-scoreboard.js"></script>
