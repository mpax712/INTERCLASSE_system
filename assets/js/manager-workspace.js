window.EventWorkspaceInitializers = window.EventWorkspaceInitializers || {};
window.EventWorkspaceInitializers.manager = function (root, data, signal) {
const managerTeams = data.teams;
    const currentBracketType = data.bracketType;
    const defaultCreateStage = data.createStage;
    const defaultMatchPlace = data.place;
    const search = document.getElementById('match-manager-search');
    const status = document.getElementById('match-manager-status-filter');
    const rows = Array.from(document.querySelectorAll('.match-manager-row'));
    const visibleCount = document.getElementById('match-manager-visible-count');
    const emptySearch = document.getElementById('match-manager-empty-search');
    const openCreateButton = document.getElementById('match-manager-open-create');
    const addGroupButton = document.getElementById('match-manager-add-group');
    const advancementModeInputs = Array.from(document.querySelectorAll('input[name="advancement_mode"]'));
    const editModalElement = document.getElementById('match-manager-edit-modal');
    const createModalElement = document.getElementById('match-manager-create-modal');
    const correctModalElement = document.getElementById('match-manager-correct-modal');
    const editFields = {
        id: document.getElementById('match-edit-id'),
        stage: document.getElementById('match-edit-stage'),
        groupHidden: document.getElementById('match-edit-group-hidden'),
        groupDisplay: document.getElementById('match-edit-group-display'),
        home: document.getElementById('match-edit-home'),
        away: document.getElementById('match-edit-away'),
        starts: document.getElementById('match-edit-starts'),
        place: document.getElementById('match-edit-place'),
        sort: document.getElementById('match-edit-sort'),
        summary: document.getElementById('match-edit-summary'),
        hint: document.getElementById('match-edit-hint'),
        save: document.getElementById('match-edit-save')
    };
    const createFields = {
        stage: document.getElementById('match-create-stage'),
        groupHidden: document.getElementById('match-create-group-hidden'),
        groupDisplay: document.getElementById('match-create-group-display'),
        home: document.getElementById('match-create-home'),
        away: document.getElementById('match-create-away'),
        starts: document.getElementById('match-create-starts'),
        place: document.getElementById('match-create-place'),
        sort: document.getElementById('match-create-sort'),
        summary: document.getElementById('match-create-summary'),
        hint: document.getElementById('match-create-hint'),
        save: document.getElementById('match-create-save')
    };
    const correctFields = {
        id: document.getElementById('match-correct-id'),
        version: document.getElementById('match-correct-version'),
        summary: document.getElementById('match-correct-summary'),
        homeWinner: document.getElementById('match-correct-home-winner'),
        awayWinner: document.getElementById('match-correct-away-winner'),
        homeLabel: document.getElementById('match-correct-home-label'),
        awayLabel: document.getElementById('match-correct-away-label'),
        homeScore: document.getElementById('match-correct-home-score'),
        awayScore: document.getElementById('match-correct-away-score'),
        reason: document.getElementById('match-correct-reason')
    };
    let currentEditAway = '';
    let currentCreateAway = '';

    function normalize(value) {
        return (value || '').toLocaleLowerCase('pt-BR').trim();
    }

    function labelForTeam(team) {
        if (!team) return '';
        return team.name + (team.group_name ? ' · Grupo ' + team.group_name : ' · Sem grupo');
    }

    function findTeam(id) {
        const numericId = Number(id || 0);
        return managerTeams.find(team => Number(team.id) === numericId) || null;
    }

    function fillTeamSelect(select, teams, selectedValue) {
        if (!select) return;
        select.innerHTML = '<option value="">Selecione um time</option>';
        teams.forEach(team => {
            const option = new Option(labelForTeam(team), String(team.id));
            option.dataset.group = team.group_name || '';
            select.add(option);
        });
        select.value = selectedValue ? String(selectedValue) : '';
    }

    function showModal(element) {
        if (!element) return;
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(element).show();
            return;
        }
        element.classList.add('show');
        element.style.display = 'block';
        element.removeAttribute('aria-hidden');
    }

    function updateMatchModalOpponents(fields, state) {
        if (!fields.home || !fields.away || !fields.stage) return;

        const homeTeam = findTeam(fields.home.value);
        const isGroupStage = fields.stage.value === 'grupo' && currentBracketType === 'groups';
        let opponents = managerTeams.filter(team => String(team.id) !== String(fields.home.value || ''));
        let groupName = '';

        if (isGroupStage && homeTeam) {
            groupName = homeTeam.group_name || '';
            opponents = opponents.filter(team => team.group_name !== '' && team.group_name === groupName);
        }

        fillTeamSelect(fields.away, opponents, state.away);
        if (state.away && fields.away.value !== String(state.away)) {
            fields.away.value = '';
        }

        const groupText = groupName ? 'Grupo ' + groupName : (isGroupStage ? 'Escolha um time com grupo' : 'Sem grupo');
        if (fields.groupHidden) fields.groupHidden.value = isGroupStage ? groupName : '';
        if (fields.groupDisplay) fields.groupDisplay.value = groupText;
        if (fields.summary) {
            const homeText = homeTeam ? homeTeam.name : 'Time escolhido';
            const availableText = isGroupStage && groupName ? 'adversários do Grupo ' + groupName : 'todos os adversários da modalidade';
            fields.summary.textContent = homeText + ' -> ' + availableText;
        }
        if (fields.hint) {
            fields.hint.textContent = isGroupStage
                ? 'Na fase de grupos, o adversário precisa estar no mesmo grupo do time escolhido.'
                : 'Fora da fase de grupos, você pode escolher qualquer time desta modalidade e gênero.';
        }
        if (fields.save) {
            fields.save.disabled = isGroupStage && (!homeTeam || !groupName || !fields.away.value);
        }
    }

    function updateEditOpponents() {
        updateMatchModalOpponents(editFields, {away: currentEditAway});
    }

    function updateCreateOpponents() {
        updateMatchModalOpponents(createFields, {away: currentCreateAway});
    }

    function updateAdvancementPanels() {
        const selected = (advancementModeInputs.find(input => input.checked) || {}).value || 'global';
        document.querySelectorAll('[data-advance-panel]').forEach(panel => {
            const active = panel.dataset.advancePanel === selected;
            panel.classList.toggle('is-active', active);
            panel.querySelectorAll('input').forEach(input => {
                input.disabled = !active || input.closest('form').querySelector('button[disabled]') !== null;
            });
        });
        advancementModeInputs.forEach(input => {
            const label = input.closest('label');
            if (label) {
                label.classList.toggle('is-active', input.checked);
            }
        });
    }

    function updateFilters() {
        const query = normalize(search ? search.value : '');
        const selectedStatus = status ? status.value : '';
        let count = 0;

        rows.forEach(row => {
            const matchesQuery = query === '' || (row.dataset.search || '').includes(query);
            const matchesStatus = selectedStatus === '' || row.dataset.status === selectedStatus;
            const visible = matchesQuery && matchesStatus;
            row.classList.toggle('d-none', !visible);
            if (visible) count++;
        });

        if (visibleCount) {
            visibleCount.textContent = count + ' partida' + (count === 1 ? '' : 's');
        }
        if (emptySearch) {
            emptySearch.classList.toggle('d-none', count > 0 || rows.length === 0);
        }
    }

    function setSelectValue(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.value = value || '';
        }
    }

    search && search.addEventListener('input', updateFilters);
    status && status.addEventListener('change', updateFilters);
    advancementModeInputs.forEach(input => input.addEventListener('change', updateAdvancementPanels));
    updateAdvancementPanels();

    openCreateButton && openCreateButton.addEventListener('click', () => {
        currentCreateAway = '';
        if (createFields.stage) {
            createFields.stage.value = Array.from(createFields.stage.options).some(option => option.value === 'grupo') ? 'grupo' : defaultCreateStage;
        }
        if (createFields.starts) createFields.starts.value = '';
        if (createFields.place) createFields.place.value = defaultMatchPlace || '';
        if (createFields.sort) createFields.sort.value = '0';
        fillTeamSelect(createFields.home, managerTeams, '');
        fillTeamSelect(createFields.away, [], '');
        updateCreateOpponents();
        showModal(createModalElement);
    });

    root.addEventListener('click', event => {
        const button = event.target.closest('[data-edit-match]');
        if (!button) return;

        currentEditAway = button.dataset.away || '';
        if (editFields.id) editFields.id.value = button.dataset.id || '';
        if (editFields.stage) editFields.stage.value = button.dataset.stage || 'grupo';
        if (editFields.starts) editFields.starts.value = button.dataset.starts || '';
        if (editFields.place) editFields.place.value = button.dataset.place || '';
        if (editFields.sort) editFields.sort.value = button.dataset.sort || '0';
        fillTeamSelect(editFields.home, managerTeams, button.dataset.home || '');
        updateEditOpponents();
        showModal(editModalElement);
    }, {signal});

    root.addEventListener('click', event => {
        const button = event.target.closest('[data-correct-match]');
        if (!button) return;

        const homeName = button.dataset.homeName || 'Mandante';
        const awayName = button.dataset.awayName || 'Visitante';
        if (correctFields.id) correctFields.id.value = button.dataset.id || '';
        if (correctFields.version) correctFields.version.value = button.dataset.version || '0';
        if (correctFields.summary) {
            correctFields.summary.textContent = (button.dataset.stage || 'fase') + ': ' + homeName + ' x ' + awayName;
        }
        if (correctFields.homeLabel) correctFields.homeLabel.textContent = homeName;
        if (correctFields.awayLabel) correctFields.awayLabel.textContent = awayName;
        if (correctFields.homeScore) correctFields.homeScore.value = button.dataset.homeScore || '0';
        if (correctFields.awayScore) correctFields.awayScore.value = button.dataset.awayScore || '0';
        if (correctFields.reason) correctFields.reason.value = '';

        const winner = Number(button.dataset.winner || 0);
        const homeId = Number(button.dataset.home || 0);
        const awayId = Number(button.dataset.away || 0);
        if (correctFields.homeWinner) correctFields.homeWinner.checked = winner > 0 && winner === homeId;
        if (correctFields.awayWinner) correctFields.awayWinner.checked = winner > 0 && winner === awayId;

        showModal(correctModalElement);
    }, {signal});

    editFields.stage && editFields.stage.addEventListener('change', () => {
        currentEditAway = editFields.away ? editFields.away.value : '';
        updateEditOpponents();
    });
    editFields.home && editFields.home.addEventListener('change', () => {
        currentEditAway = '';
        updateEditOpponents();
    });
    editFields.away && editFields.away.addEventListener('change', () => {
        currentEditAway = editFields.away.value;
        updateEditOpponents();
    });
    createFields.stage && createFields.stage.addEventListener('change', () => {
        currentCreateAway = createFields.away ? createFields.away.value : '';
        updateCreateOpponents();
    });
    createFields.home && createFields.home.addEventListener('change', () => {
        currentCreateAway = '';
        updateCreateOpponents();
    });
    createFields.away && createFields.away.addEventListener('change', () => {
        currentCreateAway = createFields.away.value;
        updateCreateOpponents();
    });

    addGroupButton && addGroupButton.addEventListener('click', () => {
        const nextGroup = addGroupButton.dataset.nextGroup || 'A';
        document.querySelectorAll('.match-manager-group-select').forEach(select => {
            if (!Array.from(select.options).some(option => option.value === nextGroup)) {
                const option = new Option('Grupo ' + nextGroup, nextGroup);
                select.add(option);
            }
            select.value = nextGroup;
        });

        const kanban = document.getElementById('match-manager-kanban');
        if (kanban && !kanban.querySelector('[data-group-column="' + nextGroup + '"]')) {
            const column = document.createElement('div');
            column.className = 'match-manager-column';
            column.dataset.groupColumn = nextGroup;
            column.innerHTML = `
                <div class="match-manager-column-head">
                    <strong>Grupo ${nextGroup}</strong>
                    <span>0</span>
                </div>
                <div class="match-manager-team-stack">
                    <div class="match-manager-empty-card">Nenhum time aqui.</div>
                </div>
            `;
            kanban.appendChild(column);
            column.scrollIntoView({behavior: 'smooth', inline: 'center', block: 'nearest'});
        }
    });
};
