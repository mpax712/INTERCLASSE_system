(function () {
    const root = document.querySelector('[data-scoreboard-match]');
    if (!root) return;

    const matchId = root.dataset.scoreboardMatch;
    const csrfToken = root.dataset.csrf;
    const modalityLabel = root.dataset.modalityLabel || 'Partida';
    const homeName = root.dataset.homeName || 'Casa';
    const awayName = root.dataset.awayName || 'Visitante';
    const isIndividual = root.dataset.isIndividual === '1';
    const isQuiz = root.dataset.modality === 'quiz';
    const isCube = root.dataset.modality === 'cubo_magico';
    const cubePlayers = window.cubePlayerInfo || [];
    let selectedCubePlayerId = null;
    let latestCubeScoreData = {};
    let cubeAttemptEditorDirty = false;
    const numberingButton = document.getElementById('open-numbering');
    const numberingForm = document.getElementById('numbering-form');
    const saveNumberingButton = document.getElementById('save-numbering');
    const cancelNumberingButton = document.getElementById('cancel-numbering');
    const syncState = document.getElementById('score-sync-state');
    let refreshTimer = null;
    let lastUpdatedAt = null;
    let version = Number(root.dataset.version || 0);
    let saving = false;
    let uncertainPayload = null;
    const retryButton = document.getElementById('score-retry');

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

    function setSyncState(state, message) {
        if (!syncState) return;
        syncState.className = 'score-sync-state text-center mt-2 sync-' + state;
        syncState.textContent = message;
    }

    function showMessage(message, title, variant) {
        if (typeof askConfirmation === 'function') {
            return askConfirmation({
                title: title || 'Aviso',
                message,
                confirmText: 'Entendi',
                variant: variant || 'btn-primary'
            });
        }
        alert(message);
        return Promise.resolve(true);
    }

    function scoreErrorTitle(error) {
        if (!error || !error.status) return 'Falha de comunicação';
        if (error.status === 401) return 'Sessão expirada';
        if (error.status === 403) return 'Permissão negada';
        if (error.status === 419) return 'Sessão expirada';
        if (error.status === 422) return 'Ação não permitida agora';
        return 'Não foi possível salvar';
    }

    function confirmAsync(message, options) {
        if (typeof askConfirmation === 'function') {
            return askConfirmation(Object.assign({
                message,
                confirmText: 'Confirmar',
                variant: 'btn-primary'
            }, options || {}));
        }
        return Promise.resolve(confirmAction(message));
    }

    function currentScoreText() {
        const detail = document.getElementById('score-detail');
        return detail && detail.textContent.trim() ? detail.textContent.trim() : '';
    }

    function actionConfirmation(action, team) {
        const teamName = team === 'home' ? homeName : awayName;
        const opponentName = team === 'home' ? awayName : homeName;

        if (action === 'start') {
            return `Iniciar ${modalityLabel}: ${homeName} x ${awayName}?`;
        }
        if (action === 'finish') {
            const score = currentScoreText();
            return `Encerrar ${modalityLabel}: ${homeName} x ${awayName}${score ? ' (' + score + ')' : ''}?`;
        }
        if (action === 'wo') {
            return `Confirmar W.O. para ${teamName}? ${opponentName} será marcado como vencedor.`;
        }
        if (action === 'set_winner') {
            return `Confirmar ${teamName} como vencedor?`;
        }

        return 'Confirma esta alteração?';
    }

    function setVisible(selector, visible) {
        document.querySelectorAll(selector).forEach(element => {
            element.classList.toggle('d-none', !visible);
        });
    }

    function renderControls(status) {
        const scheduled = status === 'agendada';
        const running = status === 'em_andamento';
        const awaiting = status === 'aguardando_resultado';

        setVisible('.start-action', scheduled);
        setVisible('.finish-action', running || awaiting);
        setVisible('.wo-action', scheduled);
        setVisible('.await-action', running);
        setVisible('.resume-action', awaiting);
        setVisible('.cancel-action', scheduled || running || awaiting);
        setVisible('.quarter-control', running);

        document.querySelectorAll('.score-action').forEach(button => {
            if (button.dataset.action === 'wo') return;
            if (button.dataset.action === 'set_winner') return; // Winner buttons always visible when running
            button.classList.toggle('d-none', !running);
        });

        // For individual modalities, winner buttons are visible when running
        if (isIndividual) {
            document.querySelectorAll('.winner-action').forEach(button => {
                button.classList.toggle('d-none', !running);
            });
        }

        if (numberingButton) {
            numberingButton.classList.toggle('d-none', !scheduled);
        }
        if (numberingForm && root.dataset.status !== 'agendada') {
            numberingForm.classList.add('d-none');
        }

        if (isCube) {
            document.querySelectorAll('#add-cube-attempt, #save-cube-attempts').forEach(element => {
                element.classList.toggle('d-none', !running);
            });
            document.querySelectorAll('.attempt-time-input, .remove-attempt').forEach(element => {
                element.disabled = !running;
                if (element.matches('.remove-attempt')) {
                    element.classList.toggle('d-none', !running);
                }
            });
        }
    }

    function renderRoster(payload) {
        const playerNumbers = (payload.score_data && payload.score_data.player_numbers) || {};
        document.querySelectorAll('.player-number').forEach(span => {
            const id = Number(span.dataset.playerId);
            if (!id) return;
            const number = playerNumbers[id] !== undefined ? playerNumbers[id] : span.textContent.trim();
            if (playerNumbers[id] !== undefined && playerNumbers[id] !== null && playerNumbers[id] !== '') {
                span.textContent = playerNumbers[id];
                span.classList.remove('text-muted');
            } else {
                span.textContent = '-';
                span.classList.add('text-muted');
            }
        });
    }

    function getSelectedCubePlayerId() {
        const selected = document.querySelector('input[name="cube_player"]:checked');
        return selected ? Number(selected.value) : null;
    }

    function addCubeAttemptRow(value = '') {
        const attemptsList = document.getElementById('cube-attempts-list');
        if (!attemptsList) return;
        const row = document.createElement('div');
        row.className = 'input-group mb-2 attempt-row';
        row.innerHTML = `
            <input type="number" step="0.01" min="0" class="form-control attempt-time-input" placeholder="Tempo em segundos" value="${value}">
            <button type="button" class="btn btn-outline-danger remove-attempt">Remover</button>
        `;
        attemptsList.appendChild(row);
    }

    function populateCubeAttemptRows(playerId) {
        selectedCubePlayerId = playerId;
        cubeAttemptEditorDirty = false;
        const attemptsList = document.getElementById('cube-attempts-list');
        if (!attemptsList) return;
        attemptsList.innerHTML = '';
        const attempts = (latestCubeScoreData.player_attempts && latestCubeScoreData.player_attempts[playerId]) || [];
        if (attempts.length === 0) {
            addCubeAttemptRow('');
            return;
        }
        attempts.forEach(attempt => addCubeAttemptRow(attempt));
    }

    function renderCubeSummary(scoreData) {
        latestCubeScoreData = scoreData || {};
        const summary = document.getElementById('cube-score-summary');
        if (!summary) return;
        const attemptsByPlayer = scoreData.player_attempts || {};
        const averages = scoreData.player_averages || {};
        if (!cubePlayers.length) {
            summary.innerHTML = '<p class="small text-muted">Nenhum jogador disponível para Cubo Mágico.</p>';
            return;
        }

        const rows = cubePlayers.map(player => {
            const attempts = attemptsByPlayer[player.id] || [];
            const times = attempts.length
                ? attempts.map(time => `${Number(time).toFixed(2).replace('.', ',')}s`).join(', ')
                : '<span class="text-muted">sem tentativas</span>';
            const average = averages[player.id] !== undefined
                ? `${Number(averages[player.id]).toFixed(2).replace('.', ',')}s`
                : '<span class="text-muted">-</span>';

            return `<tr>
                <td>${player.name}</td>
                <td>${player.class_name || '-'}</td>
                <td>${times}</td>
                <td>${average}</td>
            </tr>`;
        }).join('');

        summary.innerHTML = `
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Aluno</th>
                            <th>Turma</th>
                            <th>Tempos</th>
                            <th>Média</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;

        if (selectedCubePlayerId && !cubeAttemptEditorDirty) {
            populateCubeAttemptRows(selectedCubePlayerId);
        }
    }

    function render(payload) {
        version = Number(payload.version ?? version);
        root.dataset.version = String(version);
        if (payload.updated_at && payload.updated_at === lastUpdatedAt) {
            setSyncState('ok', 'Sincronizado');
            return;
        }
        lastUpdatedAt = payload.updated_at || lastUpdatedAt;

        if (isQuiz) {
            Object.entries((payload.score_data && payload.score_data.scores) || {}).forEach(([teamId, score]) => {
                document.querySelectorAll(`.quiz-score[data-team-id="${teamId}"]`).forEach(element => {
                    element.textContent = Number(score);
                });
            });
        } else if (isCube) {
            renderCubeSummary(payload.score_data || {});
        } else if (!isIndividual) {
            document.getElementById('home-score').textContent = payload.home_score;
            document.getElementById('away-score').textContent = payload.away_score;
        }

        const scoreDetail = document.getElementById('score-detail');
        const matchStatuses = document.querySelectorAll('#match-status, #match-status-header');
        if (scoreDetail) {
            scoreDetail.textContent = payload.score_text || '';
        }
        matchStatuses.forEach(matchStatus => {
            matchStatus.textContent = statusLabel(payload.status);
            matchStatus.className = statusClass(payload.status);
        });
        root.dataset.status = payload.status;
        if (!isQuiz && !isCube) {
            renderRoster(payload);
        }
        renderControls(payload.status);
    }

    function pollDelay(payload) {
        if (document.hidden) {
            return 60000;
        }
        if (payload && Number(payload.next_poll_ms)) {
            return Number(payload.next_poll_ms);
        }
        return root.dataset.status === 'em_andamento' ? 20000 : 120000;
    }

    function scheduleRefresh(payload) {
        window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(refresh, pollDelay(payload));
    }

    function refresh() {
        if (document.hidden) {
            scheduleRefresh();
            return;
        }
        setSyncState('updating', 'Atualizando...');
        fetch('api/score.php?id=' + encodeURIComponent(matchId), {headers: {'Accept': 'application/json'}})
            .then(response => response.json())
            .then(payload => {
                render(payload);
                setSyncState('ok', 'Sincronizado');
                scheduleRefresh(payload);
            })
            .catch(() => {
                setSyncState('error', 'Falha ao sincronizar');
                scheduleRefresh({next_poll_ms: 120000});
            });
    }

    function markLastAction(payload) {
        const team = payload.team;
        if (!team) return;
        document.querySelectorAll('.scoreboard-team').forEach(card => {
            card.classList.remove('scoreboard-team-active');
        });
        const teamControls = document.querySelector(`.score-controls[data-team="${team}"]`);
        const card = teamControls ? teamControls.closest('.scoreboard-team') : null;
        if (card) {
            card.classList.add('scoreboard-team-active');
        }
    }

    async function sendAction(payload, message, needsConfirmation) {
        if (saving) return null;
        if (uncertainPayload) {
            setSyncState('error', 'Reenvie a atualização pendente antes de registrar outra ação.');
            return null;
        }
        if (needsConfirmation&&!window.AdminScoreTransport) {
            const confirmed = await confirmAsync(message, {
                title: payload.action === 'wo' || payload.action === 'finish' ? 'Confirmar resultado' : 'Confirmar ação',
                variant: payload.action === 'wo' || payload.action === 'finish' ? 'btn-danger' : 'btn-primary'
            });
            if (!confirmed) return Promise.resolve();
        }
        payload.match_id = Number(matchId);
        payload.csrf_token = csrfToken;
        payload.expected_version = version;
        payload.request_id = window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(36).slice(2);

        window.clearTimeout(refreshTimer);
        saving = true;
        document.querySelectorAll('.score-action, .match-action').forEach(button => button.disabled = true);
        setSyncState('updating', 'Salvando...');
        return postJson('api/score.php', payload)
            .then(responsePayload => {
                render(responsePayload);
                markLastAction(payload);
                setSyncState('ok', 'Salvo');
                scheduleRefresh(responsePayload);
                return responsePayload;
            })
            .catch(error => {
                if(error.cancelled){setSyncState('ok','Operação cancelada');scheduleRefresh();return null;}
                if (error.status === 409 && error.payload && error.payload.current) render(error.payload.current);
                if (!error.status || error.status >= 500) {
                    uncertainPayload = payload;
                    if (retryButton) retryButton.hidden = false;
                }
                setSyncState('error', 'Falha ao salvar');
                showMessage(uncertainPayload ? 'Não foi possível confirmar o salvamento. Use “Reenviar atualização” para repetir a mesma operação com segurança.' : (error.message || 'Verifique sua conexão e tente novamente.'), scoreErrorTitle(error), 'btn-danger');
                scheduleRefresh({next_poll_ms: 5000});
                return null;
            }).finally(() => {
                saving = false;
                document.querySelectorAll('.score-action, .match-action').forEach(button => button.disabled = !!uncertainPayload);
            });
    }

    if (retryButton) retryButton.addEventListener('click', () => {
        if (!uncertainPayload || saving) return;
        saving = true;
        retryButton.disabled = true;
        setSyncState('updating', 'Verificando atualização...');
        postJson('api/score.php', uncertainPayload).then(payload => {
            uncertainPayload = null;
            retryButton.hidden = true;
            render(payload);
            setSyncState('ok', 'Salvo');
            scheduleRefresh(payload);
        }).catch(error => {
            if (error.status && error.status < 500) {
                uncertainPayload = null;
                retryButton.hidden = true;
                if (error.status === 409 && error.payload && error.payload.current) render(error.payload.current);
            }
            setSyncState('error', 'Falha ao confirmar');
            showMessage(error.message || 'Verifique a conexão e reenvie a mesma operação.', scoreErrorTitle(error), 'btn-danger');
        }).finally(() => {
            saving = false;
            retryButton.disabled = false;
            document.querySelectorAll('.score-action, .match-action').forEach(button => button.disabled = !!uncertainPayload);
        });
    });

    document.querySelectorAll('.score-action').forEach(button => {
        button.addEventListener('click', () => {
            const action = button.dataset.action;
            const amount = Number(button.dataset.amount || 0);
            const quarter = document.getElementById('quarter') ? Number(document.getElementById('quarter').value) : null;
            const teamId = button.dataset.teamId ? Number(button.dataset.teamId) : null;
            const team = button.closest('[data-team]') ? button.closest('[data-team]').dataset.team : null;

            if (action === 'set_winner') {
                confirmAsync(actionConfirmation(action, team), {title: 'Definir vencedor'}).then(confirmed => {
                    if (!confirmed) return;
                    sendAction({action: 'set_winner', team}, '', false);
                });
                return;
            }

            if (isQuiz) {
                sendAction({action, team_id: teamId, amount, quarter}, 'Confirma esta alteração de pontuação?', false);
                return;
            }

            sendAction({action, team, amount, quarter}, actionConfirmation(action, team), action === 'wo');
        });
    });

    document.querySelectorAll('.match-action').forEach(button => {
        button.addEventListener('click', () => {
            const action = button.dataset.action;
            const reason = action === 'cancel' ? prompt('Motivo do cancelamento:') : null;
            if (action === 'cancel' && !reason) return;
            sendAction({action, reason}, actionConfirmation(action), ['start', 'finish', 'cancel'].includes(action));
        });
    });

    if (isCube) {
        document.querySelectorAll('.cube-player-radio').forEach(radio => {
            radio.addEventListener('change', () => {
                cubeAttemptEditorDirty = false;
                populateCubeAttemptRows(Number(radio.value));
            });
        });

        const cubeAttemptsList = document.getElementById('cube-attempts-list');
        if (cubeAttemptsList) {
            cubeAttemptsList.addEventListener('click', (event) => {
                if (!event.target.matches('.remove-attempt')) return;
                const row = event.target.closest('.attempt-row');
                if (row) {
                    cubeAttemptEditorDirty = true;
                    row.remove();
                }
            });
            cubeAttemptsList.addEventListener('input', (event) => {
                if (event.target.matches('.attempt-time-input')) {
                    cubeAttemptEditorDirty = true;
                }
            });
        }

        const addCubeAttemptButton = document.getElementById('add-cube-attempt');
        if (addCubeAttemptButton) {
            addCubeAttemptButton.addEventListener('click', () => {
                cubeAttemptEditorDirty = true;
                addCubeAttemptRow('');
            });
        }

        const saveCubeAttemptsButton = document.getElementById('save-cube-attempts');
        if (saveCubeAttemptsButton) {
            saveCubeAttemptsButton.addEventListener('click', () => {
                const playerId = getSelectedCubePlayerId();
                if (!playerId) {
                    showMessage('Selecione um jogador antes de salvar as tentativas.');
                    return;
                }

                const attemptInputs = document.querySelectorAll('.attempt-time-input');
                const attempts = [];
                let invalidAttempt = false;

                attemptInputs.forEach(input => {
                    const value = input.value.trim();
                    if (value === '') {
                        return;
                    }
                    const number = Number(value.replace(',', '.'));
                    if (Number.isNaN(number) || number < 0) {
                        invalidAttempt = true;
                        return;
                    }
                    attempts.push(Number(number.toFixed(2)));
                });

                if (invalidAttempt) {
                    showMessage('Insira tempos válidos nas tentativas.');
                    return;
                }

                if (attempts.length === 0) {
                    showMessage('Adicione pelo menos uma tentativa para salvar.');
                    return;
                }

                saveCubeAttemptsButton.disabled = true;
                sendAction({action: 'save_cube_attempts', player_id: playerId, attempts}, 'Salvar tentativas para este jogador?', false)
                    .then(responsePayload => {
                        if (responsePayload && responsePayload.score_data) {
                            latestCubeScoreData = responsePayload.score_data;
                            cubeAttemptEditorDirty = false;
                            populateCubeAttemptRows(playerId);
                        }
                    })
                    .finally(() => {
                        saveCubeAttemptsButton.disabled = false;
                    });
            });
        }

        const initialPlayer = getSelectedCubePlayerId() || (cubePlayers[0] && cubePlayers[0].id);
        if (initialPlayer) {
            const initialRadio = document.querySelector(`input[name="cube_player"][value="${initialPlayer}"]`);
            if (initialRadio) {
                initialRadio.checked = true;
            }
            populateCubeAttemptRows(initialPlayer);
        }
    }

    if (numberingButton && numberingForm) {
        numberingButton.addEventListener('click', () => {
            numberingForm.classList.toggle('d-none');
        });
    }

    if (cancelNumberingButton && numberingForm) {
        cancelNumberingButton.addEventListener('click', () => {
            numberingForm.classList.add('d-none');
        });
    }

    if (saveNumberingButton) {
        saveNumberingButton.addEventListener('click', () => {
            const inputs = document.querySelectorAll('.player-number-input');
            const numbers = {};
            inputs.forEach(input => {
                const id = Number(input.name.replace('player_', ''));
                if (!id) {
                    return;
                }
                const value = input.value.trim();
                numbers[id] = value === '' ? null : value;
            });
            const applyToEvent = document.getElementById('apply-to-event')?.checked ? 1 : 0;
            saveNumberingButton.disabled = true;
            sendAction({action: 'assign_numbers', numbers, apply_to_event: applyToEvent}, '', false)
                .finally(() => {
                    saveNumberingButton.disabled = false;
                    if (numberingForm) {
                        numberingForm.classList.add('d-none');
                    }
                });
        });
    }

    window.addEventListener('admin-score-refresh',event=>render(event.detail));
    renderControls(root.dataset.status || 'agendada');
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refresh();
        }
    });
    refresh();
})();
