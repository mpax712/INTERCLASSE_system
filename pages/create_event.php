<?php
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(428); echo '<div class="alert alert-warning">Confira os impactos antes de confirmar.</div>'; return; }
require_roles(['admin']);
ensure_interclasse_schema_updates();

$errors = [];
$allowedStatuses = ['rascunho', 'inscricoes', 'em_andamento', 'encerrado'];
$allowedModalities = allowed_modalities();


?>
<div class="page-title">
    <div>
        <h1 class="h3 mb-1">Novo evento</h1>
        <p class="text-muted mb-0">Crie um evento de forma organizada com modalidades e grupos definidos.</p>
    </div>
    <a class="btn btn-outline-secondary" href="index.php?page=events">Voltar aos eventos</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="form-stepper mb-3" aria-label="Etapas de criação do evento">
    <div class="form-stepper-item is-active"><span>1</span><strong>Dados</strong></div>
    <div class="form-stepper-item is-active"><span>2</span><strong>Regras</strong></div>
    <div class="form-stepper-item is-active"><span>3</span><strong>Modalidades</strong></div>
    <div class="form-stepper-item is-active"><span>4</span><strong>Limites</strong></div>
    <div class="form-stepper-item"><span>5</span><strong>Revisão</strong></div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div class="form-section-title">
                <span>1</span>
                <div>
                    <strong>Dados gerais</strong>
                    <small>Nome, local e período principal do interclasse.</small>
                </div>
            </div>

            <div class="mb-4">
                <h3 class="h6">Regras dos jogos eletrônicos</h3>
                <p class="text-muted small">Aplicadas às partidas criadas neste evento. Você poderá alterá-las para partidas futuras.</p>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label" for="clash-best-of">Clash Royale · melhor de</label><select class="form-select" id="clash-best-of" name="rules[clash_royale][best_of]"><option value="1">1 batalha</option><option value="3" selected>3 batalhas</option><option value="5">5 batalhas</option></select></div>
                    <div class="col-md-2"><label class="form-label">FIFA · vitória</label><input class="form-control" type="number" min="0" max="20" name="rules[fifa][win_points]" value="3"></div>
                    <div class="col-md-2"><label class="form-label">FIFA · empate</label><input class="form-control" type="number" min="0" max="20" name="rules[fifa][draw_points]" value="1"></div>
                    <div class="col-md-2"><label class="form-label">FIFA · derrota</label><input class="form-control" type="number" min="0" max="20" name="rules[fifa][loss_points]" value="0"></div>
                    <div class="col-md-2"><label class="form-label">FIFA · gols W.O.</label><input class="form-control" type="number" min="0" max="20" name="rules[fifa][wo_goals]" value="3"></div>
                </div>
                <details class="mt-3"><summary class="fw-semibold">Pontos das turmas por colocação</summary><p class="small text-muted mt-2">A pontuação de alunos da mesma turma será somada na classificação geral.</p>
                    <?php foreach (['fifa' => 'FIFA', 'clash_royale' => 'Clash Royale'] as $gameKey => $gameLabel): ?><div class="row g-2 mb-2"><strong class="col-12 small"><?= e($gameLabel) ?></strong><?php foreach (['class_points_first' => '1º', 'class_points_second' => '2º', 'class_points_third' => '3º', 'class_points_other' => 'Demais'] as $ruleKey => $label): ?><label class="col-6 col-md-3 small"><?= e($label) ?><input class="form-control" type="number" min="0" max="50" name="rules[<?= e($gameKey) ?>][<?= e($ruleKey) ?>]" value="<?= (int) ($_POST['rules'][$gameKey][$ruleKey] ?? modality_definition($gameKey)['rules'][$ruleKey]) ?>"></label><?php endforeach; ?></div><?php endforeach; ?>
                </details>
            </div>
            <div class="row">
                <div class="col-lg-5 mb-3">
                    <label class="form-label">Nome</label>
                    <input class="form-control" name="name" value="<?= e($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="col-lg-4 mb-3">
                    <label class="form-label">Local padrão</label>
                    <input class="form-control" name="place" value="<?= e($_POST['place'] ?? '') ?>">
                </div>
                <div class="col-lg-3 mb-3">
                    <label class="form-label">Prazo elenco</label>
                    <input class="form-control" type="datetime-local" name="roster_edit_until" value="<?= e($_POST['roster_edit_until'] ?? '') ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Descrição</label>
                <textarea class="form-control" name="description" rows="2"><?= e($_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="form-section-title">
                <span>2</span>
                <div>
                    <strong>Regras de calendário e avanço</strong>
                    <small>Defina prazos, repescagem e quantidade de classificados.</small>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Início</label>
                    <input class="form-control" type="date" name="start_date" value="<?= e($_POST['start_date'] ?? '') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Fim</label>
                    <input class="form-control" type="date" name="end_date" value="<?= e($_POST['end_date'] ?? '') ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Melhores 3º (classificação)</label>
                    <input class="form-control" type="number" name="best_thirds_count" min="0" value="<?= e($_POST['best_thirds_count'] ?? 0) ?>">
                    <small class="text-muted">Quantos terceiros colocados avançam para repescagem</small>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Times avançando por grupo</label>
                    <input class="form-control" type="number" name="teams_advancing_per_group" min="1" max="4" value="<?= e($_POST['teams_advancing_per_group'] ?? 2) ?>">
                    <small class="text-muted">Quantos times avançam de cada grupo (1-4)</small>
                </div>
            </div>

            <div class="mb-4">
                <div class="form-section-title">
                    <span>3</span>
                    <div>
                        <strong>Modalidades</strong>
                        <small>Escolha as disputas que farão parte deste evento.</small>
                    </div>
                </div>
                <label class="form-label fw-bold">Modalidades <span class="text-danger">*</span></label>
                <p class="text-muted small">Selecione uma ou mais modalidades para o evento</p>
                <div class="row g-3">
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('futsal', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="futsal">
                            <input type="checkbox" name="modalities[]" value="futsal" id="mod-futsal" class="d-none" <?= in_array('futsal', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-futsal" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">⚽</div>
                                <h5 class="mb-1">Futsal</h5>
                                <small class="text-muted">Futebol de salão</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('handbol', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="handbol">
                            <input type="checkbox" name="modalities[]" value="handbol" id="mod-handbol" class="d-none" <?= in_array('handbol', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-handbol" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">🤾</div>
                                <h5 class="mb-1">Handbol</h5>
                                <small class="text-muted">Handebol de quadra</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('queimada', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="queimada">
                            <input type="checkbox" name="modalities[]" value="queimada" id="mod-queimada" class="d-none" <?= in_array('queimada', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-queimada" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">🔥</div>
                                <h5 class="mb-1">Queimada</h5>
                                <small class="text-muted">Jogo de eliminação</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('volei', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="volei">
                            <input type="checkbox" name="modalities[]" value="volei" id="mod-volei" class="d-none" <?= in_array('volei', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-volei" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">🏐</div>
                                <h5 class="mb-1">Vôlei</h5>
                                <small class="text-muted">Vôlei de quadra</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('basquete', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="basquete">
                            <input type="checkbox" name="modalities[]" value="basquete" id="mod-basquete" class="d-none" <?= in_array('basquete', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-basquete" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">🏀</div>
                                <h5 class="mb-1">Basquete</h5>
                                <small class="text-muted">Basquetebol</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('cubo_magico', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="cubo_magico">
                            <input type="checkbox" name="modalities[]" value="cubo_magico" id="mod-cubo_magico" class="d-none" <?= in_array('cubo_magico', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-cubo_magico" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">🧊</div>
                                <h5 class="mb-1">Cubo Mágico 3x3</h5>
                                <small class="text-muted">Individual - tempo</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('xadrez', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="xadrez">
                            <input type="checkbox" name="modalities[]" value="xadrez" id="mod-xadrez" class="d-none" <?= in_array('xadrez', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-xadrez" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">♟️</div>
                                <h5 class="mb-1">Xadrez</h5>
                                <small class="text-muted">Individual - resultado</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array('quiz', $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="quiz">
                            <input type="checkbox" name="modalities[]" value="quiz" id="mod-quiz" class="d-none" <?= in_array('quiz', $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-quiz" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon">❓</div>
                                <h5 class="mb-1">Quiz</h5>
                                <small class="text-muted">Máximo 2 jogadores</small>
                            </label>
                        </div>
                    </div>
                    <?php foreach (['fifa' => ['🎮', 'Futebol digital individual'], 'clash_royale' => ['👑', 'Série individual de batalhas']] as $gameId => [$icon, $description]): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="modality-card <?= in_array($gameId, $_POST['modalities'] ?? [], true) ? 'selected' : '' ?>" data-modality="<?= e($gameId) ?>">
                            <input type="checkbox" name="modalities[]" value="<?= e($gameId) ?>" id="mod-<?= e($gameId) ?>" class="d-none" <?= in_array($gameId, $_POST['modalities'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="mod-<?= e($gameId) ?>" class="d-block p-3 text-center cursor-pointer">
                                <div class="modality-icon"><?= $icon ?></div>
                                <h5 class="mb-1"><?= e(modality_label($gameId)) ?></h5>
                                <small class="text-muted"><?= e($description) ?></small>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mb-4">
                <div class="form-section-title">
                    <span>4</span>
                    <div>
                        <strong>Limite de jogadores</strong>
                        <small>Defina quantos alunos cada time/turma pode ter em cada modalidade.</small>
                    </div>
                </div>
                <div class="row g-3">
                    <?php foreach ($allowedModalities as $limitModality): ?>
                        <?php
                        $defaultLimit = default_player_limit_for_modality($limitModality);
                        $postedLimit = $_POST['player_limits'][$limitModality] ?? $defaultLimit;
                        ?>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label"><?= e(modality_label($limitModality)) ?></label>
                            <input class="form-control" type="number" name="player_limits[<?= e($limitModality) ?>]" min="1" max="999" value="<?= e((string) $postedLimit) ?>">
                            <small class="text-muted"><?= is_mixed_modality($limitModality) || is_individual_modality($limitModality) ? 'Individual/mista' : 'Equipe' ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="volei-config" class="config-section <?= in_array('volei', $_POST['modalities'] ?? [], true) ? 'active' : '' ?>">
                <div class="card border-primary mb-3">
                    <div class="card-header bg-primary text-white">
                        <strong>🏐 Configurações do Vôlei</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Melhor de quantos sets?</label>
                                <select class="form-select" name="set_best_of">
                                    <option value="1" <?= ($_POST['set_best_of'] ?? 3) == 1 ? 'selected' : '' ?>>1 set (1 a 0)</option>
                                    <option value="3" <?= ($_POST['set_best_of'] ?? 3) == 3 ? 'selected' : '' ?>>3 sets (2 a 1)</option>
                                    <option value="5" <?= ($_POST['set_best_of'] ?? 3) == 5 ? 'selected' : '' ?>>5 sets (3 a 2)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pontos por set</label>
                                <input class="form-control" type="number" name="set_points" value="<?= e($_POST['set_points'] ?? 25) ?>" min="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="basquete-config" class="config-section <?= in_array('basquete', $_POST['modalities'] ?? [], true) ? 'active' : '' ?>">
                <div class="card border-danger mb-3">
                    <div class="card-header bg-danger text-white">
                        <strong>🏀 Configurações do Basquete</strong>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Quantidade de quartos</label>
                                <input class="form-control" type="number" name="quarters_count" value="<?= e($_POST['quarters_count'] ?? 4) ?>" min="1">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="review-panel mb-4">
                <div>
                    <span>Revisão</span>
                    <strong id="review-event-name"><?= e($_POST['name'] ?? 'Novo evento') ?></strong>
                    <small id="review-event-meta">Confira nome, datas, prazo e modalidades antes de criar.</small>
                </div>
                <div class="review-panel-count">
                    <strong id="review-modality-count">0</strong>
                    <span>modalidade(s)</span>
                </div>
            </div>

            <div class="d-grid">
                <button class="btn btn-primary btn-lg" type="submit">Criar evento</button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const modalityCards = document.querySelectorAll('.modality-card');
    const voleiConfig = document.getElementById('volei-config');
    const basqueteConfig = document.getElementById('basquete-config');
    const reviewName = document.getElementById('review-event-name');
    const reviewMeta = document.getElementById('review-event-meta');
    const reviewCount = document.getElementById('review-modality-count');
    const nameInput = document.querySelector('input[name="name"]');
    const startInput = document.querySelector('input[name="start_date"]');
    const endInput = document.querySelector('input[name="end_date"]');

    function updateConfigVisibility() {
        const voleiChecked = document.getElementById('mod-volei').checked;
        const basqueteChecked = document.getElementById('mod-basquete').checked;

        voleiConfig.classList.toggle('active', voleiChecked);
        basqueteConfig.classList.toggle('active', basqueteChecked);
        updateReview();
    }

    function formatDate(value) {
        if (!value) return 'início a definir';
        return value.split('-').reverse().join('/');
    }

    function updateReview() {
        const checked = document.querySelectorAll('input[name="modalities[]"]:checked').length;
        reviewCount.textContent = checked;
        reviewName.textContent = nameInput.value.trim() || 'Novo evento';
        reviewMeta.textContent = `${formatDate(startInput.value)}${endInput.value ? ' até ' + formatDate(endInput.value) : ''} · ${checked} modalidade(s) selecionada(s)`;
    }

    modalityCards.forEach(card => {
        card.addEventListener('click', function(e) {
            const checkbox = this.querySelector('input[type="checkbox"]');

            if (e.target === checkbox) {
                this.classList.toggle('selected', checkbox.checked);
                updateConfigVisibility();
                return;
            }

            if (e.target.tagName === 'LABEL' || e.target.closest('label')) {
                setTimeout(() => {
                    this.classList.toggle('selected', checkbox.checked);
                    updateConfigVisibility();
                }, 0);
                return;
            }

            e.preventDefault();
            checkbox.checked = !checkbox.checked;
            this.classList.toggle('selected', checkbox.checked);
            updateConfigVisibility();
        });
    });
    [nameInput, startInput, endInput].forEach(input => input.addEventListener('input', updateReview));
    updateConfigVisibility();
})();
</script>
