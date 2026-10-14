(function () {
    'use strict';
    const root = document.getElementById('event-workspace');
    if (!root || !window.fetch || !window.AbortController) return;

    let initializerController;
    let readController;
    let sequence = 0;
    let writing = false;
    let renderedUrl = location.href;
    const localFilters = ['match-search', 'match-stage-filter', 'match-status-filter',
        'match-manager-search', 'match-manager-status-filter'];

    function initialize() {
        initializerController?.abort();
        initializerController = new AbortController();
        // Keep native inline confirmations as the non-AJAX fallback. Extract only
        // their literal messages; never execute code from the fetched document.
        root.querySelectorAll('[onclick], [onsubmit]').forEach(element => {
            ['onclick', 'onsubmit'].forEach(attribute => {
                const source = element.getAttribute(attribute) || '';
                const match = source.match(/^return (?:confirmAction|confirm)\('([^']*)'\);?$/);
                if (match) {
                    const form = element.closest('form');
                    if (form) form.dataset.confirmSubmit = match[1];
                    element.removeAttribute(attribute);
                }
            });
        });
        root.querySelectorAll('select[onchange]').forEach(select => {
            if (select.getAttribute('onchange') === 'this.form.submit()') {
                select.removeAttribute('onchange');
                select.dataset.workspaceAutoSubmit = 'true';
            }
        });
        root.querySelectorAll('[data-workspace-config]').forEach(config => {
            const initializer = window.EventWorkspaceInitializers?.[config.dataset.workspaceConfig];
            if (initializer) initializer(root, JSON.parse(config.textContent), initializerController.signal);
        });
    }

    function isWorkspaceUrl(url) {
        const eventId = url.searchParams.get('event_id') || url.searchParams.get('id');
        return url.origin === location.origin && url.searchParams.get('page') === root.dataset.page
            && eventId === root.dataset.eventId
            && (url.pathname === location.pathname || url.pathname === new URL('index.php', location.href).pathname);
    }

    function clearNotices() {
        root.querySelectorAll('[data-workspace-message], [data-workspace-error]').forEach(item => item.remove());
    }

    function showError(message, login = false) {
        clearNotices();
        const notice = document.createElement('div');
        notice.className = 'alert alert-danger';
        notice.dataset.workspaceError = 'true';
        notice.setAttribute('role', 'alert');
        notice.textContent = message;
        if (login) {
            const link = document.createElement('a');
            link.href = 'index.php?page=login';
            link.className = 'alert-link ms-2';
            link.textContent = 'Entrar novamente';
            notice.append(link);
        }
        // Notices inside an open modal remain visible, and failed forms stay intact.
        (root.querySelector('.modal.show .modal-body') || root).prepend(notice);
    }

    function setBusy(busy) {
        root.setAttribute('aria-busy', String(busy));
        let status = root.querySelector('[data-workspace-loading]');
        if (busy && !status) {
            status = document.createElement('div');
            status.dataset.workspaceLoading = 'true';
            status.className = 'alert alert-info py-2';
            status.setAttribute('role', 'status');
            status.textContent = writing ? 'Salvando…' : 'Carregando…';
            root.prepend(status);
        } else if (!busy) status?.remove();
    }

    async function closeModals() {
        if (!window.bootstrap?.Modal) return;
        await Promise.all(Array.from(root.querySelectorAll('.modal')).map(async element => {
            const modal = bootstrap.Modal.getInstance(element);
            if (!modal) return;
            if (element.classList.contains('show')) {
                await new Promise(resolve => {
                    element.addEventListener('hidden.bs.modal', resolve, {once: true});
                    modal.hide();
                });
            }
            modal.dispose();
        }));
    }

    async function update(url, {method = 'GET', body, historyMode = 'push'} = {}) {
        readController?.abort();
        const requestSequence = ++sequence;
        const controller = new AbortController();
        if (method === 'GET') readController = controller;
        const filters = new Map(localFilters.map(id => [id, root.querySelector('#' + id)?.value]));
        const scroll = {x: window.scrollX, y: window.scrollY};
        setBusy(true);
        try {
            const response = await fetch(url, {
                method, body, credentials: 'same-origin', cache: 'no-store',
                signal: method === 'GET' ? controller.signal : undefined,
                headers: {'X-Requested-With': 'EventWorkspace'}
            });
            if (requestSequence !== sequence) return;
            const finalUrl = new URL(response.url || url, location.href);
            if (finalUrl.searchParams.get('page') === 'login' || response.status === 401) {
                showError('Sua sessão expirou. Entre novamente para continuar.', true);
                return;
            }
            if (!response.ok) {
                showError(response.status === 419 ? 'A sessão de edição expirou. Reabra esta tela antes de salvar.'
                    : response.status === 403 ? 'Você não tem permissão para realizar esta ação.'
                    : 'Não foi possível atualizar a tela. Seus campos foram mantidos.');
                return;
            }
            const html = await response.text();
            if (requestSequence !== sequence) return;
            const documentResponse = new DOMParser().parseFromString(html, 'text/html');
            const content = documentResponse.getElementById('event-workspace');
            if (!content || content.dataset.page !== root.dataset.page
                || content.dataset.eventId !== root.dataset.eventId || !isWorkspaceUrl(finalUrl)) {
                showError('O servidor retornou uma resposta inesperada. Seus campos foram mantidos.');
                return;
            }
            const notices = Array.from(content.querySelectorAll('[data-workspace-message]'));
            if (method === 'POST' && notices.some(item => ['warning', 'danger'].includes(item.dataset.workspaceMessage))) {
                clearNotices();
                (root.querySelector('.modal.show .modal-body') || root).prepend(...notices);
                return;
            }
            // Only declarative JSON data is imported. Executable scripts stay out.
            content.querySelectorAll('script:not([type="application/json"])').forEach(script => script.remove());
            await closeModals();
            if (requestSequence !== sequence) return;
            root.replaceChildren(...Array.from(content.childNodes));
            initialize();
            filters.forEach((value, id) => {
                const control = root.querySelector('#' + id);
                if (!control || value === undefined) return;
                if (control.tagName === 'SELECT' && !Array.from(control.options).some(option => option.value === value)) return;
                control.value = value;
                control.dispatchEvent(new Event(control.tagName === 'SELECT' ? 'change' : 'input'));
            });
            document.title = documentResponse.title;
            renderedUrl = finalUrl.href;
            if (historyMode === 'push' && location.href !== renderedUrl) history.pushState({eventWorkspace: true}, '', renderedUrl);
            else if (historyMode === 'replace') history.replaceState({eventWorkspace: true}, '', renderedUrl);
            window.scrollTo(scroll.x, scroll.y);
            if (method === 'POST') window.AppRamCache?.clear('workspace-mutation');
        } catch (error) {
            if (error.name !== 'AbortError' && requestSequence === sequence) {
                showError(method === 'POST'
                    ? 'A conexão falhou. Confira o resultado antes de tentar salvar novamente; a operação pode ter sido concluída.'
                    : 'A conexão falhou. O conteúdo anterior foi mantido. Tente novamente.');
            }
        } finally {
            if (requestSequence === sequence) {
                setBusy(false);
                if (historyMode === 'pop' && location.href !== renderedUrl) history.replaceState({eventWorkspace: true}, '', renderedUrl);
            }
        }
    }

    root.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || link.target || link.getAttribute('href').startsWith('#')) return;
        const url = new URL(link.href, location.href);
        if (!isWorkspaceUrl(url)) return;
        event.preventDefault();
        if (!writing) update(url.href);
    });

    root.addEventListener('submit', async event => {
        const form = event.target;
        if (form.hasAttribute('data-admin-form')) return;
        const url = new URL(form.getAttribute('action') || location.href, location.href);
        if (!isWorkspaceUrl(url)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (writing) return;
        const method = form.method.toUpperCase();
        const data = new FormData(form);
        if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
        if (method === 'GET') {
            url.search = new URLSearchParams(data).toString();
            if (isWorkspaceUrl(url)) update(url.href);
            return;
        }
        if (method !== 'POST') return;
        writing = true;
        readController?.abort();
        ++sequence;
        setBusy(false);
        let disabled = [];
        try {
            const message = form.dataset.confirmSubmit;
            if (message && !(await (window.askConfirmation && window.bootstrap?.Modal
                ? askConfirmation(message) : Promise.resolve(window.confirm(message))))) return;
            disabled = Array.from(root.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)'));
            disabled.forEach(button => { button.disabled = true; });
            await update(url.href, {method: 'POST', body: data, historyMode: 'replace'});
        } finally {
            disabled.forEach(button => { if (button.isConnected) button.disabled = false; });
            writing = false;
        }
    }, true);

    root.addEventListener('change', event => {
        const select = event.target.closest('[data-workspace-auto-submit]');
        if (select && !writing) select.form.requestSubmit();
    });
    window.addEventListener('popstate', () => {
        if (writing) {
            history.pushState({eventWorkspace: true}, '', renderedUrl);
            return;
        }
        const url = new URL(location.href);
        if (isWorkspaceUrl(url)) update(url.href, {historyMode: 'pop'});
    });
    initialize();
    history.replaceState({eventWorkspace: true}, '', location.href);
})();
