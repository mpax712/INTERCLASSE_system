window.EventWorkspaceInitializers = window.EventWorkspaceInitializers || {};
window.EventWorkspaceInitializers.event = function (root, data) {
const csrfToken = data.csrfToken;

const matchSearchInput = document.getElementById('match-search');
const matchStageFilter = document.getElementById('match-stage-filter');
const matchStatusFilter = document.getElementById('match-status-filter');
const matchRows = Array.from(document.querySelectorAll('.event-match-row'));
const matchVisibleCount = document.getElementById('match-visible-count');
const matchEmptyState = document.getElementById('event-match-empty');

function normalizeMatchText(value) {
    return (value || '').toLocaleLowerCase('pt-BR').trim();
}

function updateMatchFilters() {
    if (!matchRows.length) {
        return;
    }

    const query = normalizeMatchText(matchSearchInput ? matchSearchInput.value : '');
    const stage = matchStageFilter ? matchStageFilter.value : '';
    const status = matchStatusFilter ? matchStatusFilter.value : '';
    let visible = 0;

    matchRows.forEach(row => {
        const matchesQuery = query === '' || (row.dataset.search || '').includes(query);
        const matchesStage = stage === '' || row.dataset.stage === stage;
        const matchesStatus = status === '' || row.dataset.status === status;
        const shouldShow = matchesQuery && matchesStage && matchesStatus;

        row.classList.toggle('d-none', !shouldShow);
        if (shouldShow) {
            visible += 1;
        }
    });

    if (matchVisibleCount) {
        matchVisibleCount.textContent = `${visible} jogo${visible === 1 ? '' : 's'}`;
    }

    if (matchEmptyState) {
        matchEmptyState.classList.toggle('d-none', visible > 0);
    }
}

[matchSearchInput, matchStageFilter, matchStatusFilter].forEach(control => {
    if (control) {
        control.addEventListener('input', updateMatchFilters);
        control.addEventListener('change', updateMatchFilters);
    }
});

// Handle export buttons
document.querySelectorAll('.export-modality-btn').forEach(exportBtn => {
    exportBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const eventId = this.dataset.eventId;
        const modality = this.dataset.modality;
        const gender = this.dataset.gender;
        const type = this.dataset.exportType || 'modality_general';
        
        const url = `api/export.php?type=${type}&event_id=${eventId}&modality=${modality}&gender=${gender}&csrf_token=${encodeURIComponent(csrfToken)}`;
        window.location.href = url;
    });
});

const exportGeneralEventBtn = document.querySelector('.export-general-event-btn');
if (exportGeneralEventBtn) {
    exportGeneralEventBtn.addEventListener('click', function(e) {
        e.preventDefault();
        const eventId = this.dataset.eventId;
        const url = `api/export.php?type=general&event_id=${eventId}&csrf_token=${encodeURIComponent(csrfToken)}`;
        window.location.href = url;
    });
}
};
