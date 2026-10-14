(function () {
    const root = document.querySelector('[data-live-match]');
    if (!root) return;

    const matchId = root.dataset.liveMatch;
    const modality = root.dataset.modality;
    let refreshTimer = null;
    let lastUpdatedAt = null;

    function statusLabel(status) {
        return {
            agendada: 'Agendada',
            em_andamento: 'Em andamento',
            encerrada: 'Encerrada',
            wo: 'W.O.',
            aguardando_resultado: 'Aguardando resultado',
            cancelada: 'Cancelada'
        }[status] || String(status || '').replace('_', ' ');
    }

    function statusClass(status) {
        return 'status-pill status-' + String(status || '').replace(/[^a-z0-9_-]/gi, '-');
    }

    function renderStatus(payload) {
        const scoreDetail = document.getElementById('score-detail');
        const matchStatus = document.getElementById('match-status');
        if (scoreDetail) {
            scoreDetail.textContent = payload.status === 'agendada' ? 'Placar pendente' : (payload.status === 'cancelada' ? 'Partida cancelada' : (payload.score_text || ''));
        }
        if (matchStatus) {
            matchStatus.textContent = statusLabel(payload.status);
            matchStatus.className = statusClass(payload.status);
        }
    }

    function renderCube(payload) {
        const scoreData = payload.score_data || {};
        const attempts = scoreData.player_attempts || {};
        const averages = scoreData.player_averages || {};

        document.querySelectorAll('.cube-attempts').forEach(span => {
            const playerId = span.dataset.playerId;
            const playerAttempts = attempts[playerId] || [];
            if (!playerAttempts.length) {
                span.innerHTML = '<span class="text-muted">sem tentativas</span>';
                return;
            }
            span.textContent = playerAttempts.map(time => Number(time).toFixed(2).replace('.', ',') + 's').join(', ');
        });

        document.querySelectorAll('.cube-average').forEach(span => {
            const playerId = span.dataset.playerId;
            const average = averages[playerId];
            span.innerHTML = average !== undefined && average !== null ? Number(average).toFixed(2).replace('.', ',') + 's' : '<span class="text-muted">-</span>';
        });

        renderStatus(payload);
    }

    function renderQuiz(payload) {
        const scores = (payload.score_data && payload.score_data.scores) || {};
        document.querySelectorAll('.quiz-score').forEach(element => {
            const teamId = element.dataset.teamId;
            element.textContent = Number(scores[teamId] || 0);
        });

        renderStatus(payload);
    }

    function render(payload) {
        if (payload.updated_at && payload.updated_at === lastUpdatedAt) {
            return;
        }
        lastUpdatedAt = payload.updated_at || lastUpdatedAt;

        if (modality === 'cubo_magico') {
            renderCube(payload);
            return;
        }

        if (modality === 'quiz') {
            renderQuiz(payload);
            return;
        }

        const homeScore = document.getElementById('home-score');
        const awayScore = document.getElementById('away-score');

        if (homeScore) {
            homeScore.textContent = ['agendada','cancelada'].includes(payload.status) ? '–' : payload.home_score;
        }
        if (awayScore) {
            awayScore.textContent = ['agendada','cancelada'].includes(payload.status) ? '–' : payload.away_score;
        }
        renderStatus(payload);
    }

    function refresh() {
        if (document.hidden) {
            scheduleRefresh({next_poll_ms: 120000});
            return;
        }

        fetch('api/score.php?id=' + encodeURIComponent(matchId), {headers: {'Accept': 'application/json'}})
            .then(response => response.json())
            .then(payload => {
                render(payload);
                scheduleRefresh(payload);
            })
            .catch(() => {
                scheduleRefresh({next_poll_ms: 120000});
            });
    }

    function scheduleRefresh(payload) {
        window.clearTimeout(refreshTimer);
        const delay = document.hidden ? 120000 : Number(payload && payload.next_poll_ms ? payload.next_poll_ms : 120000);
        refreshTimer = window.setTimeout(refresh, delay);
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refresh();
        }
    });
    refresh();
})();
