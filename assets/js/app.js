function confirmAction(message) {
    return window.confirm(message || 'Confirma esta ação?');
}

function ensureConfirmModal() {
    let modal = document.getElementById('app-confirm-modal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'app-confirm-modal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content app-confirm-modal">
                <div class="modal-header">
                    <h5 class="modal-title">Confirmar ação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0" data-confirm-message>Confirma esta ação?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" data-confirm-accept>Confirmar</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    return modal;
}

function askConfirmation(options) {
    const settings = typeof options === 'string' ? {message: options} : (options || {});
    const modalElement = ensureConfirmModal();
    const title = modalElement.querySelector('.modal-title');
    const message = modalElement.querySelector('[data-confirm-message]');
    const accept = modalElement.querySelector('[data-confirm-accept]');
    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

    title.textContent = settings.title || 'Confirmar ação';
    message.textContent = settings.message || 'Confirma esta ação?';
    accept.textContent = settings.confirmText || 'Confirmar';
    accept.className = 'btn ' + (settings.variant || 'btn-primary');

    return new Promise(resolve => {
        let accepted = false;
        const cleanup = () => {
            accept.removeEventListener('click', onAccept);
            modalElement.removeEventListener('hidden.bs.modal', onHidden);
        };
        const onAccept = () => {
            accepted = true;
            cleanup();
            modal.hide();
            resolve(true);
        };
        const onHidden = () => {
            cleanup();
            resolve(accepted);
        };

        accept.addEventListener('click', onAccept, {once: true});
        modalElement.addEventListener('hidden.bs.modal', onHidden, {once: true});
        modal.show();
    });
}

function sendDebugLog(location, message, data, hypothesisId, runId) {
    // Debug telemetry disabled in this deployment.
    return;
}

function postJson(url, data) {
    if(url==='api/score.php'&&window.AdminScoreTransport)return window.AdminScoreTransport.send(data);
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify(data)
    }).then(async response => {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(payload.error || 'Erro na requisição.');
            error.status = response.status;
            error.payload = payload;
            throw error;
        }
        return payload;
    });
}
