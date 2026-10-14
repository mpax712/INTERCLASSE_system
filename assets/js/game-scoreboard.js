(function () {
    const root = document.getElementById('game-scoreboard');
    if (!root) return;
    const feedback = document.getElementById('game-feedback');
    const retryButton = document.getElementById('game-retry');
    let version = Number(root.dataset.version);
    let status = root.dataset.status;
    let busy = false;
    let pendingPayload = null;

    function requestId() {
        return window.crypto && typeof window.crypto.randomUUID === 'function'
            ? window.crypto.randomUUID()
            : String(Date.now()) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }
    function setFeedback(message, type) {
        feedback.textContent = message;
        feedback.className = 'game-save-feedback mt-3 text-' + type;
    }
    function showControls() {
        root.querySelectorAll('[data-action]').forEach(button => {
            const action = button.dataset.action;
            const scheduled = status === 'agendada';
            const running = status === 'em_andamento';
            const awaiting = status === 'aguardando_resultado';
            button.hidden = !(
                (['start', 'wo'].includes(action) && scheduled) ||
                (['await_result', 'adjust', 'record_battle', 'remove_battle'].includes(action) && running) ||
                (action === 'resume' && awaiting) ||
                (action === 'finish' && (running || awaiting)) ||
                (action === 'cancel' && (scheduled || running || awaiting))
            );
            button.disabled = busy || !!pendingPayload;
        });
        retryButton.hidden = !pendingPayload;
        retryButton.disabled = busy;
    }
    function render(data) {
        version = Number(data.version);
        root.dataset.version = String(version);
        status = data.status;
        document.getElementById('game-home-score').textContent = status === 'agendada' ? '–' : data.home_score;
        document.getElementById('game-away-score').textContent = status === 'agendada' ? '–' : data.away_score;
        const badge = document.getElementById('game-status');
        badge.textContent = {agendada:'Agendada',em_andamento:'Em andamento',aguardando_resultado:'Aguardando resultado',encerrada:'Encerrada',wo:'W.O.',cancelada:'Cancelada'}[status] || status;
        badge.className = 'status-pill status-' + status;
        const list = document.getElementById('game-battle-list');
        if (list) {
            list.replaceChildren();
            (data.score_data?.battles || []).forEach((battle, index) => {
                const item = document.createElement('li');
                item.textContent = `Batalha ${index + 1}: ${battle.home_crowns} x ${battle.away_crowns} coroas · vencedor ${battle.winner === 'home' ? '1' : '2'}`;
                list.appendChild(item);
            });
        }
        showControls();
    }
    async function refresh() {
        if (busy || pendingPayload) return;
        try {
            const response = await fetch('api/score.php?id=' + encodeURIComponent(root.dataset.id));
            if (response.ok) render(await response.json());
        } catch (_) {
            setFeedback('Sem conexão. O placar pode estar desatualizado.', 'danger');
        }
    }
    async function submitPending() {
        if (busy || !pendingPayload) return;
        busy = true;
        showControls();
        setFeedback('Salvando...', 'muted');
        try {
            if(window.AdminScoreTransport){const data=await window.AdminScoreTransport.send(pendingPayload);pendingPayload=null;render(data);setFeedback('Placar atualizado com sucesso.','success');return;}
            const response = await fetch('api/score.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(pendingPayload)
            });
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 409 && data.current) render(data.current);
                if (response.status < 500) pendingPayload = null;
                throw new Error(data.error || 'Não foi possível atualizar o placar.');
            }
            pendingPayload = null;
            render(data);
            setFeedback(data.last_action_message || 'Placar atualizado com sucesso.', 'success');
        } catch (error) {
            if(error.cancelled){pendingPayload=null;setFeedback('Operação cancelada.','muted');return;}
            if(error.saved||(error.status&&error.status<500))pendingPayload=null;
            setFeedback(pendingPayload
                ? 'Não foi possível confirmar o salvamento. Toque em “Reenviar atualização” para repetir a mesma operação com segurança.'
                : (error.message || 'Não foi possível atualizar o placar.'), 'danger');
        } finally {
            busy = false;
            showControls();
        }
    }
    function send(button) {
        if (busy || pendingPayload) return;
        const action = button.dataset.action;
        const payload = {match_id: Number(root.dataset.id), action, expected_version: version, request_id: requestId(), csrf_token: root.dataset.csrf};
        if (button.dataset.team) payload.team = button.dataset.team;
        if (button.dataset.amount) payload.amount = Number(button.dataset.amount);
        if (action === 'record_battle') {
            payload.home_crowns = document.getElementById('game-home-crowns').value;
            payload.away_crowns = document.getElementById('game-away-crowns').value;
            payload.winner = document.getElementById('game-battle-winner').value;
            payload.tiebreak = document.getElementById('game-tiebreak').checked;
        }
        if (action === 'finish' && root.dataset.modality === 'fifa' && root.dataset.stage !== 'grupo') {
            payload.home_penalties = document.getElementById('game-home-penalties').value;
            payload.away_penalties = document.getElementById('game-away-penalties').value;
        }
        if (action === 'cancel') {
            payload.reason = prompt('Motivo do cancelamento:') || '';
            if (!payload.reason) return;
        }
        if (!window.AdminScoreTransport&&['finish', 'wo', 'cancel'].includes(action) && !confirm('Confirmar esta alteração? Ela pode mudar a classificação.')) return;
        pendingPayload = payload;
        submitPending();
    }
    root.addEventListener('click', event => {
        const button = event.target.closest('button[data-action]');
        if (button) send(button);
    });
    retryButton.addEventListener('click', submitPending);
    window.addEventListener('admin-score-refresh',event=>render(event.detail));
    showControls();
    refresh();
    setInterval(() => { if (!document.hidden) refresh(); }, 20000);
})();
