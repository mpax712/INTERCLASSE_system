(function () {
    'use strict';
    window.EventWorkspaceInitializers = window.EventWorkspaceInitializers || {};
    const names = {matches:'partida',players:'jogador',teams:'equipe / inscrição',classes:'turma global',events:'evento'};
    const phases = {grupo:'Grupos',repescagem:'Repescagem',preliminar:'Preliminar',dezesseis_avos:'16 avos',oitavas:'Oitavas',quartas:'Quartas',semifinal:'Semifinal',final:'Final',individual:'Individual'};
    const statuses = {agendada:'Agendada',em_andamento:'Em andamento',aguardando_resultado:'Aguardando resultado',encerrada:'Encerrada',wo:'W.O.',cancelada:'Cancelada'};
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const parse = value => { try { return typeof value === 'string' ? JSON.parse(value) : (value || {}); } catch { return {}; } };
    const options = (items, selected, blank = true) => (blank ? '<option value="">Selecione</option>' : '') + items.map(([value,label])=>`<option value="${esc(value)}" ${String(value)===String(selected)?'selected':''}>${esc(label)}</option>`).join('');
    let embeddedController;

    window.EventWorkspaceInitializers.admin = function (root, context, signal) {
        const panel = root.querySelector('[data-admin-panel]'); if (!panel) return;
        const embedded=!!context.embedded;
        if(embedded){const heading=root.querySelector('.page-title');if(heading)heading.after(panel);}
        if(embedded){embeddedController?.abort();const controller=new AbortController();embeddedController=controller;const outerSignal=signal;signal=controller.signal;outerSignal?.addEventListener('abort',()=>controller.abort(),{once:true});}
        let state = {}, definitions = {}, userOptions = [], token = context.csrfToken||'', tab = context.tab || 'matches';
        let eventId = context.eventId || 0, modality = context.modality || '', gender = context.gender || 'masculino';
        let busy = false, pending, formNodes, lastCommand, sequence = 0, renderSequence = 0, sourceForm=null, scoreWaiting=null;
        const content = panel.querySelector('[data-admin-content]');
        const notice = panel.querySelector('[data-admin-notice]');
        const old = document.getElementById('admin-operation-dialog');
        if (old) {const instance=window.bootstrap?.Modal.getInstance(old);if(instance&&old.classList.contains('show')){old.id='admin-operation-dialog-closing';old.addEventListener('hidden.bs.modal',()=>{instance.dispose();old.remove();},{once:true});instance.hide();}else{instance?.dispose();old.remove();}}
        const dialog = document.createElement('div');
        dialog.id = 'admin-operation-dialog'; dialog.className = 'modal fade'; dialog.tabIndex = -1;dialog.dataset.bsBackdrop='static';dialog.dataset.bsKeyboard='false';
        dialog.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5"></h2><button type="button" class="btn-close" data-dismiss-editor aria-label="Fechar"></button></div><div class="modal-body"></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-dialog-cancel>Cancelar</button><button class="btn btn-primary" type="button" data-dialog-submit>Analisar consequências</button></div></div></div>';
        document.body.append(dialog);
        const body = dialog.querySelector('.modal-body'), title = dialog.querySelector('.modal-title');
        const submit = dialog.querySelector('[data-dialog-submit]');
        const show = () => {submit.hidden=false;submit.disabled=!!pending&&!pending.can_execute;return window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(dialog).show() : (dialog.style.display='block');};
        const hide = () => window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(dialog).hide() : (dialog.style.display='none');
        const hideAsync=()=>dialog.classList.contains('show')&&window.bootstrap?.Modal?new Promise(resolve=>{dialog.addEventListener('hidden.bs.modal',resolve,{once:true});hide();}):Promise.resolve(hide());
        signal?.addEventListener('abort',()=> { hide(); },{once:true});
        const items = entity => Object.values(state[entity] || {});
        const scoped = row => Number(row.event_id)===Number(eventId) && row.modality===modality && (definitions[modality]?.mixed || row.gender===gender);
        const teams = () => items('teams').filter(t=>scoped(t)&&!t.archived_at);
        const players = () => items('players').filter(p=>teams().some(t=>Number(t.id)===Number(p.team_id))&&!p.archived_at);
        const teamName = id => state.teams?.[id]?.name || (id ? `Equipe #${id}` : 'A definir');
        const playerName = id => state.players?.[id]?.name || (id ? `Jogador #${id}` : 'A definir');
        const sideName = (m,side) => m[side+'_player_id'] ? playerName(m[side+'_player_id']) : m[side+'_team_id'] ? teamName(m[side+'_team_id']) : parse(m.participant_snapshot)[side]?.name || (m[side+'_source_match_id'] ? `Vencedor de #${m[side+'_source_match_id']}` : 'A definir');
        const alert = (message, target = notice) => { target.innerHTML=`<div class="alert alert-danger" role="alert">${esc(message)}</div>`; };
        async function request(data) {
            const response = await fetch('api/admin_operations.php', {method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:data?{'Content-Type':'application/json'}:{},body:data?JSON.stringify({...data,csrf_token:token}):undefined});
            if(response.status===401){notice.innerHTML='<div class="alert alert-warning">Sua sessão expirou. <a href="index.php?page=login">Entrar novamente</a></div>';throw new Error('Sua sessão expirou. Entre novamente para continuar.');}
            const payload = await response.json();
            if (!response.ok){const error=new Error(payload.error||'Não foi possível concluir a operação.');error.status=response.status;error.payload=payload;throw error;}
            return payload;
        }
        async function load() {
            const seq=++sequence;
            try { const payload=await request(); if(seq!==sequence)return;state=payload.data;definitions=payload.definitions;userOptions=payload.user_options||[];token=payload.csrf_token;
                const events=items('events').sort((a,b)=>Number(b.id)-Number(a.id));
                if(!events.some(e=>Number(e.id)===Number(eventId)))eventId=events[0]?.id||0;
                if(context.teamId&&state.teams?.[context.teamId]){const t=state.teams[context.teamId];eventId=t.event_id;modality=t.modality;gender=t.gender;if(!embedded)context.teamId=0;}
                if(!embedded){selectScope();await render();}
                if(context.editMatchId){const id=context.editMatchId;context.editMatchId=0;openEditor('matches',id);}
                panel.dataset.adminReady='true';
            } catch(error){alert(error.message);}
        }
        function selectScope() {
            panel.querySelector('[data-admin-scope=event]').innerHTML=options(items('events').sort((a,b)=>Number(b.id)-Number(a.id)).map(e=>[e.id,`${e.name}${e.archived_at?' (arquivado)':''}`]),eventId,false);
            const mods=items('event_modalities').filter(m=>Number(m.event_id)===Number(eventId));
            if(!mods.some(m=>m.modality===modality))modality=mods[0]?.modality||'';
            panel.querySelector('[data-admin-scope=modality]').innerHTML=options(mods.map(m=>[m.modality,definitions[m.modality]?.label||m.modality]),modality,false);
            const g=panel.querySelector('[data-admin-scope=gender]');g.value=gender;g.closest('label').hidden=!!definitions[modality]?.mixed;
        }
        async function render() {
            const renderId=++renderSequence;
            panel.querySelectorAll('[data-admin-tab]').forEach(b=>b.classList.toggle('active',b.dataset.adminTab===tab));
            if(panel.querySelector('[data-admin-create]'))panel.querySelector('[data-admin-create]').hidden=!names[tab];
            if(panel.querySelector('[data-admin-regenerate]'))panel.querySelector('[data-admin-regenerate]').hidden=tab!=='matches';
            if(tab==='history'){
                const params=new URLSearchParams({action:'history'});if(embedded){if(['classes','events'].includes(context.tab))params.set('entity',context.tab);else{if(context.eventId)params.set('event_id',context.eventId);if(modality)params.set('modality',modality);if(context.teamId)params.set('team_id',context.teamId);if(context.matchId)params.set('match_id',context.matchId);}}
                let response,payload;try{response=await fetch('api/admin_operations.php?'+params,{credentials:'same-origin',cache:'no-store'});payload=await response.json();}catch(error){if(renderId===renderSequence)alert('Não foi possível consultar o histórico.');return;}
                if(renderId!==renderSequence)return;
                if(!response.ok){alert(payload.error);return;}
                content.innerHTML=payload.data.map(h=>`<article class="card mb-2"><div class="card-body"><strong>${esc(h.actor_name)} · ${esc(h.created_at)}</strong><p>${esc(({save_match:'Alterar partida',save_entity:'Alterar cadastro',score_action:'Atualizar placar',regenerate:'Reorganizar confrontos',archive:'Arquivar',restore:'Restaurar',purge:'Excluir definitivamente',restart:'Reiniciar partida',cancel:'Cancelar partida',restore_operation:'Restaurar altera??o',save_settings:'Alterar regras',save_advancement:'Alterar classifica??o',batch:'Cadastrar em massa'})[h.operation]||'Altera??o administrativa')} — ${esc(h.reason)}</p><p>${Number(parse(h.impact_json).match_count||0)} partida(s) afetada(s)</p><button class="btn btn-outline-secondary me-2" data-history-details="${esc(h.id)}">Ver alterações</button><button class="btn btn-outline-primary" data-restore-operation="${esc(h.id)}">Analisar restauração</button></div></article>`).join('')||'<p>Nenhuma operação administrativa registrada.</p>';return;
            }
            if(tab==='settings'){const m=items('event_modalities').find(m=>Number(m.event_id)===Number(eventId)&&m.modality===modality);content.innerHTML=m?`<div class="card"><div class="card-body"><h2 class="h5">${esc(definitions[modality]?.label)}</h2><p>Formato: ${esc(m.bracket_type)}. Limite por equipe: ${Number(m.max_players_per_team)||'padrão'}.</p><p>Resultados anteriores mantêm as regras registradas na partida. Alterar a estrutura existente exige prévia de regeneração.</p><button class="btn btn-primary" data-edit-settings>Editar regras e formato</button> <button class="btn btn-outline-primary" data-edit-advancement>Classificação por grupos</button></div></div>`:'<p>Cadastre uma modalidade no evento para configurar suas regras.</p>';return;}
            let rows=items(tab);
            if(tab==='matches'||tab==='teams')rows=rows.filter(scoped);
            if(tab==='players')rows=rows.filter(p=>items('teams').some(t=>Number(t.id)===Number(p.team_id)&&scoped(t)));
            if(!panel.querySelector('[data-admin-show-archived]').checked)rows=rows.filter(r=>!r.archived_at);
            const query=panel.querySelector('[data-admin-search]').value.toLocaleLowerCase('pt-BR');
            rows=rows.filter(r=>JSON.stringify(r).toLocaleLowerCase('pt-BR').includes(query));
            content.innerHTML=`<p class="text-muted">${rows.length} registro(s). Remover arquiva por padrão e preserva o histórico.</p><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ID</th><th>Registro</th><th>Situação</th><th>Ações</th></tr></thead><tbody>${rows.map(r=>{
                const label=tab==='matches'?`${sideName(r,'home')} × ${sideName(r,'away')} · ${phases[r.stage]||r.stage}`:r.name;
                const status=r.archived_at?'Arquivado':r.review_state==='needs_review'?'Revisão necessária':tab==='matches'?`${statuses[r.status]} · ${r.home_score}×${r.away_score}`:tab==='players'?teamName(r.team_id):r.class_name||'Ativo';
                return `<tr><td>#${r.id}</td><td>${esc(label)}</td><td><span class="badge ${r.archived_at?'text-bg-secondary':r.review_state==='needs_review'?'text-bg-warning':'text-bg-light'}">${esc(status)}</span></td><td><div class="d-flex gap-1 flex-wrap"><button class="btn btn-sm btn-outline-primary" data-edit="${r.id}">Editar</button>${r.archived_at?`<button class="btn btn-sm btn-outline-success" data-op="restore" data-id="${r.id}">Restaurar</button>`:`<button class="btn btn-sm btn-outline-danger" data-op="archive" data-id="${r.id}">Arquivar</button>`}${tab==='matches'?`<button class="btn btn-sm btn-outline-warning" data-op="restart" data-id="${r.id}">Reiniciar</button><button class="btn btn-sm btn-outline-secondary" data-op="cancel" data-id="${r.id}">Cancelar partida</button>`:''}<button class="btn btn-sm btn-outline-danger" data-op="purge" data-id="${r.id}">Excluir definitivamente</button></div></td></tr>`;
            }).join('')}</tbody></table></div>`;
        }
        function field(name,label,value='',type='text',opts=null) {
            return `<label class="col-md-6 form-label">${esc(label)}${opts?`<select class="form-select" name="${esc(name)}">${options(opts,value)}</select>`:type==='textarea'?`<textarea class="form-control" name="${esc(name)}">${esc(value)}</textarea>`:`<input class="form-control" name="${esc(name)}" type="${esc(type)}" value="${esc(value)}">`}</label>`;
        }
        function policyFields() { return field('policy','Como tratar partidas dependentes','update','text',[['update','Atualizar com segurança; preservar resultados compatíveis'],['restart','Reiniciar resultados afetados'],['manual','Reorganizar manualmente; pausar partidas afetadas']]); }
        function openEditor(entity,id=0) {
            pending=null;lastCommand=null;const r=state[entity]?.[id]||{};title.textContent=`${id?'Editar':'Adicionar'} ${names[entity]}${id?' #'+id:''}`;
            let fields='';
            if(entity==='matches'){
                const data=parse(r.score_data),q=parse(r.qualification_sources),individual=definitions[modality]?.participant==='player';
                fields+=field('stage','Fase',r.stage||'grupo','text',Object.entries(phases));fields+=field('status','Estado',r.status||'agendada','text',Object.entries(statuses));
                const participantOptions=individual?players().map(p=>[p.id,`${p.name} · ${teamName(p.team_id)}`]):teams().map(t=>[t.id,t.name]);
                for(const side of ['home','away']){
                    const label=side==='home'?'Participante 1':'Participante 2';
                    fields+=`<fieldset class="col-12 border rounded p-3 mb-2"><legend class="h6">${label}</legend><div class="row">`;
                    fields+=field(side+'_origin','Origem da vaga',q[side]?.type|| (r[side+'_source_match_id']?'winner':'direct'),'text',[['direct','Definida diretamente'],['winner','Vencedor de outra partida'],['group_rank','Posição no grupo']]);
                    fields+=field(side+(individual?'_player_id':'_team_id'),label,r[side+(individual?'_player_id':'_team_id')],'text',participantOptions);
                    fields+=field(side+'_source_match_id','Partida de origem',r[side+'_source_match_id'],'text',items('matches').filter(m=>scoped(m)&&!m.archived_at&&Number(m.id)!==Number(id)).map(m=>[m.id,`#${m.id} ${sideName(m,'home')} × ${sideName(m,'away')}`]));
                    fields+=field(side+'_group','Grupo de origem',q[side]?.group||'');fields+=field(side+'_position','Posição no grupo',q[side]?.position||1,'number');fields+='</div></fieldset>';
                }
                fields+=field('group_name','Grupo',r.group_name||'');fields+=field('starts_at','Data e horário',(r.starts_at||'').replace(' ','T').slice(0,16),'datetime-local');fields+=field('place','Local',r.place||'');fields+=field('sort_order','Ordem',r.sort_order||0,'number');
                fields+=field('home_score','Placar participante 1',r.home_score||0,'number');fields+=field('away_score','Placar participante 2',r.away_score||0,'number');fields+=field('winner_side','Vencedor do W.O.',r.winner_team_id&&r.winner_team_id===r.away_team_id?'away':'home','text',[['home','Participante 1'],['away','Participante 2']]);
                if(modality==='fifa'){fields+=field('home_penalties','Pênaltis participante 1',data.penalties?.home??'','number');fields+=field('away_penalties','Pênaltis participante 2',data.penalties?.away??'','number');}
                const ruleValues={...(definitions[modality]?.rules||{}),...parse(r.rules_snapshot)};
                fields+='<fieldset class="col-12 border rounded p-2"><legend class="h6">Regras desta partida</legend><div class="row">'+Object.entries(ruleValues).map(([key,v])=>field('match_rule_'+key,({best_of:'Melhor de (1, 3 ou 5)',win_points:'Pontos por vitória',draw_points:'Pontos por empate',loss_points:'Pontos por derrota',wo_goals:'Gols no W.O.',class_points_first:'Pontos da turma: 1º',class_points_second:'Pontos da turma: 2º',class_points_third:'Pontos da turma: 3º',class_points_other:'Pontos da turma: demais'})[key]||key,v,'number')).join('')+'</div></fieldset>';
                if(modality==='volei')fields+=field('match_rule_set_best_of','Melhor de sets',parse(r.rules_snapshot).set_best_of||3,'number');
                if(modality==='clash_royale'){
                    const count=parse(r.rules_snapshot).best_of||parse(items('event_modalities').find(m=>Number(m.event_id)===Number(eventId)&&m.modality===modality)?.rules_json).best_of||3;
                    for(let i=0;i<count;i++){fields+=`<fieldset class="col-12 border rounded p-2"><legend class="h6">Batalha ${i+1}</legend><div class="row">${field(`battle_${i}_home`,'Coroas 1',data.battles?.[i]?.home_crowns??'','number')}${field(`battle_${i}_away`,'Coroas 2',data.battles?.[i]?.away_crowns??'','number')}${field(`battle_${i}_winner`,'Vencedor',data.battles?.[i]?.winner||'','text',[['home','Participante 1'],['away','Participante 2']])}<label><input type="checkbox" name="battle_${i}_tie" ${data.battles?.[i]?.tiebreak?'checked':''}> Houve desempate</label></div></fieldset>`;}
                }
                if(modality==='volei'||modality==='basquete')for(let i=0;i<(modality==='volei'?5:4);i++){const key=modality==='volei'?'sets':'quarters';fields+=field(`period_${i}_home`,`${modality==='volei'?'Set':'Quarto'} ${i+1} — participante 1`,data[key]?.[i]?.home??'','number');fields+=field(`period_${i}_away`,`${modality==='volei'?'Set':'Quarto'} ${i+1} — participante 2`,data[key]?.[i]?.away??'','number');}
                if(modality==='quiz'&&data.collective_match)for(const t of teams())fields+=field('quiz_'+t.id,'Pontuação '+t.name,data.scores?.[t.id]??0,'number');
                if(modality==='cubo_magico')for(const p of players())for(let i=0;i<5;i++)fields+=field(`cube_${p.id}_${i}`,`${p.name} — tentativa ${i+1} (segundos)`,data.player_attempts?.[p.id]?.[i]??'');
            }else{
                fields+=field('name','Nome',r.name||'');
                if(entity==='players'){fields+=field('team_id','Equipe / turma',r.team_id||teams()[0]?.id,'text',teams().map(t=>[t.id,t.name]));fields+=field('email','E-mail',r.email||'','email');fields+=field('birth_date','Nascimento',r.birth_date||'','date');fields+=field('jersey_number','Número',r.jersey_number??'','number');fields+=field('group_name','Grupo do jogador (quando aplicável)',r.group_name||'');}
                if(entity==='teams'){fields+=field('class_name','Turma',r.class_name||'','text',items('classes').filter(c=>!c.archived_at).map(c=>[c.name,c.name]));fields+=field('group_name','Grupo',r.group_name||'');fields+=field('representative_name','Representante',r.representative_name||'');fields+=field('representative_id','Usuário representante',r.representative_id||'','text',userOptions.map(u=>[u.id,u.name]));if(!definitions[modality]?.mixed)fields+=field('gender','Gênero da equipe',r.gender||gender,'text',[['masculino','Masculino'],['feminino','Feminino']]);}
                if(entity==='events'){fields+=field('start_date','Data inicial',r.start_date||new Date().toISOString().slice(0,10),'date');fields+=field('end_date','Data final',r.end_date||'','date');fields+=field('place','Local',r.place||'');fields+=field('status','Estado',r.status||'rascunho','text',[['rascunho','Rascunho'],['inscricoes','Inscrições'],['em_andamento','Em andamento'],['encerrado','Encerrado']]);fields+=field('description','Descrição',r.description||'','textarea');fields+='<fieldset><legend class="h6">Modalidades: marque para acrescentar (as existentes serão preservadas)</legend>'+Object.entries(definitions).map(([key,d])=>`<label class="d-block"><input type="checkbox" name="modalities" value="${esc(key)}" ${items('event_modalities').some(m=>Number(m.event_id)===Number(id)&&m.modality===key)?'checked':''}> ${esc(d.label)}</label>`).join('')+'</fieldset>';}
            }
            fields+=policyFields();
            body.innerHTML=`<div data-dialog-error></div>${entity==='matches'&&id?`<details class="mb-3"><summary>Outras ações desta partida</summary><div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-outline-warning" data-editor-op="restart" data-id="${id}">Reiniciar partida</button><button class="btn btn-sm btn-outline-secondary" data-editor-op="cancel" data-id="${id}">Cancelar partida</button><button class="btn btn-sm btn-outline-danger" data-editor-op="archive" data-id="${id}">Arquivar partida</button></div></details>`:''}<form id="admin-edit-form" data-admin-form class="row g-2">${fields}</form>`;submit.textContent='Analisar consequências';
            if(entity==='matches'){
                const form=body.querySelector('form');
                const rules=form.querySelector('[name^="match_rule_"]')?.closest('fieldset');
                if(rules){const details=document.createElement('details');details.className='col-12 mb-2';const summary=document.createElement('summary');summary.textContent='Regras específicas da partida';details.append(summary);rules.before(details);details.append(rules);const extra=form.querySelector('[name=match_rule_set_best_of]')?.closest('label');if(extra&&!details.contains(extra))rules.querySelector('.row').append(extra);}
                const adapt=()=>{for(const side of ['home','away']){const origin=form.elements[side+'_origin']?.value;for(const suffix of ['source_match_id','group','position']){const input=form.elements[side+'_'+suffix];if(input)input.closest('label').hidden=suffix==='source_match_id'?origin!=='winner':origin!=='group_rank';}const participant=form.elements[side+(definitions[modality]?.participant==='player'?'_player_id':'_team_id')];if(participant)participant.closest('label').hidden=origin!=='direct';}const winner=form.elements.winner_side;if(winner)winner.closest('label').hidden=form.elements.status.value!=='wo';};
                form.addEventListener('change',adapt);adapt();
            }
            lastCommand={operation:entity==='matches'?'save_match':'save_entity',entity,id};show();
        }
        function openOperation(command,message) {
            pending=null;lastCommand=command;title.textContent='Analisar operação administrativa';
            body.innerHTML=`<div data-dialog-error></div><p>${esc(message)}</p><form id="admin-edit-form" data-admin-form class="row">${policyFields()}${command.operation==='restore_operation'?'<label><input type="checkbox" name="overwrite"> Incluir na prévia a substituição de alterações posteriores</label>':''}</form>`;submit.textContent='Analisar consequências';show();
        }
        function collect() {
            const form=body.querySelector('form'),data=Object.fromEntries(new FormData(form));const command={...lastCommand,policy:data.policy};delete data.policy;
            if(command.operation==='save_match'){
                const r=state.matches?.[command.id]||{},score=parse(r.score_data);command.data={...data,event_id:eventId,modality,gender,qualification_sources:{}};
                for(const side of ['home','away']){
                    const type=data[side+'_origin'];if(type!=='winner')command.data[side+'_source_match_id']=null;
                    if(type==='group_rank')command.data.qualification_sources[side]={type,group:data[side+'_group'],position:Number(data[side+'_position'])};
                    if(type==='direct')command.data.qualification_sources[side]={type};
                    if(type!=='direct'){command.data[side+'_team_id']=null;command.data[side+'_player_id']=null;}
                }
                if(modality==='fifa')score.penalties=data.home_penalties!==''&&data.away_penalties!==''?{home:Number(data.home_penalties),away:Number(data.away_penalties)}:null;
                if(modality==='clash_royale'){score.battles=[];for(let i=0;i<5;i++)if(data[`battle_${i}_winner`])score.battles.push({home_crowns:Number(data[`battle_${i}_home`]),away_crowns:Number(data[`battle_${i}_away`]),winner:data[`battle_${i}_winner`],tiebreak:!!data[`battle_${i}_tie`]});}
                if(modality==='volei'||modality==='basquete'){const key=modality==='volei'?'sets':'quarters';score[key]=[];for(let i=0;i<5;i++)if(data[`period_${i}_home`]!==undefined&&data[`period_${i}_home`]!==''&&data[`period_${i}_away`]!=='')score[key].push({home:Number(data[`period_${i}_home`]),away:Number(data[`period_${i}_away`])});if(modality==='basquete'){command.data.home_score=score.quarters.reduce((sum,p)=>sum+p.home,0);command.data.away_score=score.quarters.reduce((sum,p)=>sum+p.away,0);}}
                if(modality==='volei'){score.sets=score.sets.filter(s=>s.home!==0||s.away!==0);score.sets_count_only=score.sets.length===0;if(score.sets_count_only){score.home_sets=Number(data.home_score);score.away_sets=Number(data.away_score);}}
                if(modality==='basquete'&&!score.quarters.length){command.data.home_score=Number(data.home_score);command.data.away_score=Number(data.away_score);}
                if(modality==='quiz'&&score.collective_match){score.scores={};for(const t of teams())score.scores[t.id]=Number(data['quiz_'+t.id]||0);}
                if(modality==='cubo_magico'){score.player_attempts={};for(const p of players()){const times=[];for(let i=0;i<5;i++)if(data[`cube_${p.id}_${i}`]!==undefined&&data[`cube_${p.id}_${i}`]!=='')times.push(data[`cube_${p.id}_${i}`]);if(times.length)score.player_attempts[p.id]=times;}}
                command.data.score_data=score;
                const matchRules={...parse(r.rules_snapshot)};for(const [key,value] of Object.entries(data))if(key.startsWith('match_rule_'))matchRules[key.slice(11)]=Number(value);if(Object.keys(matchRules).length)command.data.rules_snapshot=matchRules;
            }else if(command.operation==='save_entity'){command.data={...data};if(command.entity==='teams'){Object.assign(command.data,{event_id:eventId,modality,gender:data.gender||gender});if(!command.data.representative_id)command.data.representative_id=null;}if(command.entity==='events')command.data.modalities=Array.from(form.querySelectorAll('[name=modalities]:checked'),input=>input.value);}
            else if(command.operation==='regenerate'){Object.assign(command,{event_id:eventId,modality,gender,mode:data.mode,kind:data.kind,phase:data.phase,redistribute_groups:!!data.redistribute_groups,eligible_players:Array.from(form.querySelectorAll('[name=eligible]:checked'),i=>Number(i.value))});}
            else if(command.operation==='save_settings'){command.data={...data};const rules={};for(const [k,v] of Object.entries(data))if(k.startsWith('rule_'))rules[k.slice(5)]=Number(v);if(Object.keys(rules).length)command.data.rules=rules;}
            else if(command.operation==='save_advancement'){command.data={...data,groups:{}};for(const [key,value] of Object.entries(data))if(key.startsWith('group_adv_'))command.data.groups[key.slice(10)]=Number(value);}
            else if(command.operation==='restore_operation')command.overwrite=!!data.overwrite;
            return command;
        }
        function showPreview(result,reason='') {
                pending=result;
                const changes=Object.entries(result.impact.phases).map(([phase,rows])=>`<h3 class="h6 mt-3">${esc(phases[phase]||phase)}</h3><ul>${rows.map(r=>`<li>#${r.id}: ${esc(r.action)}${r.had_result?' — possui resultado/partida iniciada':''}<br><small>${esc(sideName({...r.before},'home'))} × ${esc(sideName({...r.before},'away'))} → ${esc(sideName({...r.after},'home'))} × ${esc(sideName({...r.after},'away'))}${r.after.status?' · '+esc(statuses[r.after.status])+' '+r.after.home_score+'×'+r.after.away_score:''}</small></li>`).join('')}</ul>`).join('');
                body.innerHTML=`<div data-dialog-error></div><h3 class="h5">Confira a alteração</h3><p>${result.impact.match_count} partida(s) afetada(s).</p>${result.warnings.map(w=>`<div class="alert alert-warning">${esc(w)}</div>`).join('')}${changes||'<p>Esta alteração atualiza somente os cadastros indicados.</p>'}${!result.can_execute?'<p class="fw-semibold">Como tratar as partidas afetadas?</p><div class="d-flex gap-2 mb-3"><button class="btn btn-outline-warning" data-preview-policy="restart">Reiniciar partidas afetadas</button><button class="btn btn-outline-primary" data-preview-policy="manual">Pausar para revisão manual</button></div>':''}<label class="form-label d-block">Motivo da alteração<textarea class="form-control" data-admin-reason minlength="3" maxlength="500">${esc(reason)}</textarea></label>${result.confirmation_word?'<label class="form-label d-block">Digite EXCLUIR para confirmar<input class="form-control" data-admin-confirm-word autocomplete="off"></label>':''}<p class="text-muted small">Cancelar preserva seus campos. Alterações confirmadas ficam no histórico.</p>`;
                submit.textContent='Confirmar e salvar';submit.disabled=!result.can_execute;
        }
        async function preview() {
            const form=body.querySelector('form');if(form&&!form.reportValidity())return;
            if(busy)return;busy=true;submit.disabled=true;
            try{
                const command=collect();lastCommand=command;const result=await request({action:'preview',command});formNodes=Array.from(body.childNodes);showPreview(result);return;
            }catch(error){alert(error.message,body.querySelector('[data-dialog-error]'));}
            finally{busy=false;if(!pending)submit.disabled=false;}
        }
        async function execute() {
            if(busy||!pending?.can_execute)return;const reason=body.querySelector('[data-admin-reason]').value.trim();if(reason.length<3){alert('Informe a justificativa.',body.querySelector('[data-dialog-error]'));return;}
            busy=true;submit.disabled=true;
            try{const createdEventId=pending.created_event_id;await request({action:'execute',preview_id:pending.preview_id,reason,confirmation_word:body.querySelector('[data-admin-confirm-word]')?.value});await hideAsync();pending=null;sourceForm=null;
                if(scoreWaiting){const response=await fetch('api/score.php?id='+scoreWaiting.id,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw new Error('O placar foi salvo. Atualize para conferir.');const payload=await response.json();const waiting=scoreWaiting;scoreWaiting=null;waiting.resolve(payload);return;}
                if(createdEventId){location.assign('index.php?page=event&id='+createdEventId);return;}
                if(embedded){await refreshPage();}else{await load();notice.innerHTML='<div class="alert alert-success" role="status">Alteração aplicada. O histórico e os confrontos foram atualizados.</div>';}}
            catch(error){if(scoreWaiting&&!pending){const waiting=scoreWaiting;scoreWaiting=null;error.saved=true;waiting.reject(error);alert(error.message);}else{alert(error.message,body.querySelector('[data-dialog-error]'));}}
            finally{busy=false;submit.disabled=false;}
        }
        dialog.addEventListener('click',event=>{
            const editorOp=event.target.closest('[data-editor-op]');if(editorOp&&!busy){openOperation({operation:editorOp.dataset.editorOp,entity:'matches',id:Number(editorOp.dataset.id)},'Confira o efeito nas fases seguintes antes de confirmar.');return;}
            if(scoreWaiting&&(event.target.closest('[data-dialog-cancel]')||event.target.closest('[data-dismiss-editor]'))&&!busy){const error=new Error('Alteração cancelada.');error.cancelled=true;error.status=400;scoreWaiting.reject(error);scoreWaiting=null;pending=null;hide();return;}
            const recovery=event.target.closest('[data-recover-id]');if(recovery){openOperation({operation:recovery.dataset.recoverOp,entity:context.tab,id:Number(recovery.dataset.recoverId)},'A prévia verificará as relações e as partidas antes de recuperar ou excluir este registro.');return;}
            const policyButton=event.target.closest('[data-preview-policy]');
            if(policyButton&&!busy){busy=true;const reason=body.querySelector('[data-admin-reason]')?.value||'';lastCommand.policy=policyButton.dataset.previewPolicy;request({action:'preview',command:lastCommand}).then(p=>showPreview(p,reason)).catch(e=>alert(e.message,body.querySelector('[data-dialog-error]'))).finally(()=>busy=false);return;}
            if(event.target.closest('[data-dialog-submit]'))pending?execute():preview();
            if(event.target.closest('[data-dismiss-editor]')){if(!busy){pending=null;sourceForm=null;formNodes=null;hide();}return;}
            if(event.target.closest('[data-dialog-cancel]')&&!busy){if(sourceForm){pending=null;hide();const modal=sourceForm.closest('.modal');if(modal)dialog.addEventListener('hidden.bs.modal',()=>bootstrap.Modal.getOrCreateInstance(modal).show(),{once:true});sourceForm=null;}else if(pending&&formNodes){body.replaceChildren(...formNodes);pending=null;submit.textContent='Analisar consequências';submit.disabled=false;}else hide();}
        },{signal});
        dialog.addEventListener('submit',event=>{event.preventDefault();preview();},{signal});
        function handleAdminClick(event){
            const b=event.target.closest('button');if(!b||busy)return;
            if(b.dataset.adminTab){tab=b.dataset.adminTab;render();}
            if(b.hasAttribute('data-admin-create'))openEditor(tab);
            if(b.dataset.edit)openEditor(tab,Number(b.dataset.edit));
            if(b.dataset.op)openOperation({operation:b.dataset.op,entity:tab,id:Number(b.dataset.id)},`${b.textContent} ${names[tab]} #${b.dataset.id}. A prévia lista inscrições, partidas, resultados e fases relacionados. Excluir uma turma global pode afetar vários eventos.`);
            if(b.dataset.restoreOperation)openOperation({operation:'restore_operation',operation_id:b.dataset.restoreOperation},'Recuperar os registros alterados por esta operação. Alterações posteriores serão verificadas antes da restauração.');
            if(b.dataset.historyDetails){
                busy=true;fetch('api/admin_operations.php?action=history&operation_id='+encodeURIComponent(b.dataset.historyDetails),{credentials:'same-origin',cache:'no-store'}).then(async response=>{const payload=await response.json();if(!response.ok)throw new Error(payload.error);return payload.data;}).then(h=>{
                    const labels={description:'Descrição',bracket_type:'Formato',teams_advancing:'Classificados',name:'Nome',class_name:'Turma',team_id:'Equipe',home_player_id:'Jogador 1',away_player_id:'Jogador 2',home_team_id:'Equipe 1',away_team_id:'Equipe 2',home_score:'Placar 1',away_score:'Placar 2',status:'Estado',stage:'Fase',archived_at:'Arquivado em',review_state:'Revisão',place:'Local',starts_at:'Horário',group_name:'Grupo',points:'Pontos',total_points:'Total de pontos'};
                    const changes=parse(h.changes_json);pending=null;formNodes=null;title.textContent='Histórico: '+h.actor_name+' · '+h.created_at;
                    body.innerHTML='<p>'+esc(h.reason)+'</p>'+Object.entries(changes).map(([table,rows])=>Object.entries(rows).map(([id,change])=>`<article class="border rounded p-3 mb-2"><h3 class="h6">${esc(names[table]||({class_standings:'Classificação',class_modality_points:'Pontuação',score_logs:'Histórico de placar',event_modalities:'Modalidade'})[table]||'Registro')} #${esc(id)} · ${esc(change.before?.name||change.after?.name||change.before?.class_name||change.after?.class_name||'')}</h3><p>${!change.after?'Excluído definitivamente':!change.before?'Criado':'Alterado'}</p><dl>${Object.entries(labels).filter(([key])=>change.before?.[key]!==change.after?.[key]).map(([key,label])=>`<dt>${esc(label)}</dt><dd>${esc(change.before?.[key]??'—')} → ${esc(change.after?.[key]??'—')}</dd>`).join('')}</dl></article>`).join('')).join('');show();submit.hidden=true;
                }).catch(error=>alert(error.message)).finally(()=>busy=false);
            }
            if(b.hasAttribute('data-admin-regenerate')||b.hasAttribute('data-admin-context-regenerate')){
                lastCommand={operation:'regenerate'};pending=null;title.textContent='Gerar ou reorganizar confrontos';
                body.innerHTML=`<div data-dialog-error></div><form data-admin-form class="row g-2">${field('mode','Abrangência','missing','text',[['missing','Gerar somente confrontos faltantes'],['phase','Reorganizar uma fase'],['all','Regenerar a modalidade / gênero selecionado']])}${field('kind','Formato / geração',items('event_modalities').find(m=>Number(m.event_id)===Number(eventId)&&m.modality===modality)?.bracket_type||'simple','text',[['simple','Mata-mata'],['groups','Fase de grupos'],['round_robin','Todos contra todos'],['repescagem','Repescagem']])}${field('phase','Fase a reorganizar','quartas','text',Object.entries(phases))}${policyFields()}<label><input type="checkbox" name="redistribute_groups"> Redistribuir os grupos; as definições manuais atuais serão substituídas</label>${definitions[modality]?.participant==='player'?'<fieldset><legend class="h6">Participantes elegíveis (os demais continuam inscritos)</legend>'+players().map(p=>`<label class="d-block"><input type="checkbox" name="eligible" value="${p.id}" checked> ${esc(p.name)} · ${esc(teamName(p.team_id))}</label>`).join('')+'</fieldset>':''}</form>`;submit.textContent='Analisar consequências';submit.disabled=false;show();
            }
            if(b.hasAttribute('data-edit-settings')){
                const m=items('event_modalities').find(m=>Number(m.event_id)===Number(eventId)&&m.modality===modality),rules=parse(m.rules_json);
                lastCommand={operation:'save_settings',event_id:eventId,modality};pending=null;title.textContent='Regras e formato';
                body.innerHTML=`<div data-dialog-error></div><form data-admin-form class="row g-2">${field('bracket_type','Formato',m.bracket_type,'text',(definitions[modality]?.formats||[]).map(v=>[v,{groups:'Grupos e mata-mata',simple:'Mata-mata',round_robin:'Todos contra todos'}[v]]))}${field('max_players_per_team','Limite de jogadores por equipe',m.max_players_per_team||definitions[modality]?.player_limit,'number')}${Object.entries({...definitions[modality]?.rules,...rules}).map(([key,v])=>field('rule_'+key,({best_of:'Melhor de (1, 3 ou 5)',win_points:'Pontos por vitória',draw_points:'Pontos por empate',loss_points:'Pontos por derrota',wo_goals:'Placar administrativo no W.O.',class_points_first:'Pontos da turma — 1º',class_points_second:'Pontos da turma — 2º',class_points_third:'Pontos da turma — 3º',class_points_other:'Pontos da turma — demais'})[key]||key,v,'number')).join('')}${policyFields()}</form>`;submit.textContent='Analisar consequências';submit.disabled=false;show();
            }
            if(b.hasAttribute('data-edit-advancement')){
                const e=state.events[eventId]||{};const groups=[...new Set(teams().map(t=>t.group_name).filter(Boolean))];
                lastCommand={operation:'save_advancement',event_id:eventId,modality,gender};pending=null;title.textContent='Classificação por grupos';
                body.innerHTML=`<div data-dialog-error></div><form data-admin-form class="row g-2">${field('advancement_mode','Critério do evento',e.advancement_mode||'global','text',[['global','Quantidade global'],['per_group','Quantidade por grupo']])}${field('teams_advancing_per_group','Classificados por grupo',e.teams_advancing_per_group??2,'number')}${field('best_thirds_count','Melhores terceiros adicionais',e.best_thirds_count??0,'number')}${groups.map(group=>field('group_adv_'+group,'Classificados do grupo '+group,items('event_group_advancement').find(a=>Number(a.event_id)===Number(eventId)&&a.modality===modality&&a.gender===gender&&a.group_name===group)?.teams_advancing??2,'number')).join('')}${policyFields()}</form>`;submit.textContent='Analisar consequências';submit.disabled=false;show();
            }
        }
        panel.addEventListener('click',handleAdminClick,{signal});
        dialog.addEventListener('click',handleAdminClick,{signal});

        panel.addEventListener('change',event=>{
            const key=event.target.dataset.adminScope;if(key){if(key==='event')eventId=Number(event.target.value);if(key==='modality')modality=event.target.value;if(key==='gender')gender=event.target.value;selectScope();render();}
            if(event.target.hasAttribute('data-admin-show-archived'))render();
        },{signal});
        panel.querySelector('[data-admin-search]')?.addEventListener('input',render,{signal});
        async function refreshPage(){
            const scroll=window.scrollY;
            if(context.page==='scoreboard'&&context.matchId){const response=await fetch('api/score.php?id='+context.matchId,{credentials:'same-origin',cache:'no-store'});if(response.ok){window.dispatchEvent(new CustomEvent('admin-score-refresh',{detail:await response.json()}));await load();notice.innerHTML='<div class="alert alert-success">Alteração salva. O placar foi atualizado.</div>';return;}notice.innerHTML='<div class="alert alert-success">Alteração salva. A partida está arquivada ou em revisão; confira a montagem para continuar.</div>';return;}
            if(window.EventWorkspaceNavigation&&document.getElementById('event-workspace'))await window.EventWorkspaceNavigation.refresh();
            else{const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw new Error('A alteração foi salva, mas a lista não pôde ser atualizada. Atualize a página.');const parsed=new DOMParser().parseFromString(await response.text(),'text/html');const next=parsed.querySelector('main');if(!next)throw new Error('Não foi possível atualizar a página.');next.querySelectorAll('script:not([type="application/json"])').forEach(s=>s.remove());document.querySelector('main')?.replaceChildren(...next.childNodes);const main=document.querySelector('main');main.querySelectorAll('[data-workspace-config]').forEach(c=>window.EventWorkspaceInitializers[c.dataset.workspaceConfig]?.(main,parse(c.textContent),new AbortController().signal));}
            window.scrollTo(0,scroll);const target=document.querySelector('[data-admin-notice]');if(target)target.innerHTML='<div class="alert alert-success" role="status">Alteração salva. A lista foi atualizada.</div>';
        }
        if(embedded){
            const surface=root===document?document:root;
            surface.querySelectorAll('form[method=post] [onclick],form[method=post][onsubmit]').forEach(el=>{el.removeAttribute('onclick');el.removeAttribute('onsubmit');});
            surface.addEventListener('click',async event=>{
                const button=event.target.closest('button');if(!button||busy)return;
                if(button.hasAttribute('data-correct-match')&&['volei','cubo_magico','quiz','xadrez'].includes(modality)){event.preventDefault();event.stopImmediatePropagation();openEditor('matches',Number(button.dataset.id));return;}
                if(button.hasAttribute('data-admin-context-regenerate')){event.preventDefault();handleAdminClick(event);return;}
                if(button.dataset.adminEdit){event.preventDefault();event.stopImmediatePropagation();sourceForm=null;openEditor(button.dataset.adminEdit,Number(button.dataset.id));return;}
                if(button.dataset.adminOp){event.preventDefault();event.stopImmediatePropagation();sourceForm=null;openOperation({operation:button.dataset.adminOp,entity:button.dataset.adminEntity||context.tab,id:Number(button.dataset.id)},'Confira as partidas e inscrições relacionadas antes de confirmar.');return;}
                if(button.hasAttribute('data-admin-context-history')){tab='history';await render();pending=null;formNodes=null;sourceForm=null;title.textContent='Histórico de alterações';body.innerHTML=content.innerHTML;show();submit.hidden=true;return;}
                if(button.hasAttribute('data-admin-context-archives')){await load();pending=null;formNodes=null;sourceForm=null;title.textContent='Arquivados · '+(names[context.tab]||'partidas');const rows=items(context.tab).filter(r=>r.archived_at).filter(r=>!r.event_id||!eventId||Number(r.event_id)===Number(eventId)).filter(r=>!r.modality||!modality||r.modality===modality).filter(r=>context.tab!=='players'||!context.teamId||Number(r.team_id)===Number(context.teamId));body.innerHTML=rows.map(r=>`<article class="border rounded p-3 mb-2"><strong>${esc(r.name||sideName(r,'home')+' × '+sideName(r,'away'))}</strong><div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-outline-success" data-recover-id="${r.id}" data-recover-op="restore">Restaurar</button><button class="btn btn-sm btn-outline-danger" data-recover-id="${r.id}" data-recover-op="purge">Excluir definitivamente</button></div></article>`).join('')||'<p>Nenhum registro arquivado nesta página.</p>';show();submit.hidden=true;return;}
            },{capture:true,signal});
            surface.addEventListener('submit',async event=>{
                const form=event.target;if(!(form instanceof HTMLFormElement)||form.closest('#admin-operation-dialog')||form.method.toLowerCase()!=='post')return;
                event.preventDefault();event.stopImmediatePropagation();if(busy)return;
                if(!form.reportValidity())return;busy=true;sourceForm=form;pending=null;formNodes=null;
                const data=new URLSearchParams(new FormData(form));data.set('context_page',context.page);data.set('context_team_id',context.teamId||0);if(!data.has('event_id'))data.set('event_id',eventId);if(!data.has('modality'))data.set('modality',modality);if(!data.has('gender'))data.set('gender',gender);
                try{const response=await fetch('api/admin_forms.php',{method:'POST',credentials:'same-origin',body:data});const result=await response.json();if(!response.ok)throw new Error(result.error||'Não foi possível analisar esta alteração.');lastCommand=result.command;title.textContent='Confirmar alteração';const parent=form.closest('.modal');if(parent){const modal=bootstrap.Modal.getInstance(parent);if(modal&&parent.classList.contains('show'))await new Promise(resolve=>{parent.addEventListener('hidden.bs.modal',resolve,{once:true});modal.hide();});}showPreview(result,result.reason);show();}
                catch(error){let target=form.querySelector('[data-admin-form-error]');if(!target){target=document.createElement('div');target.dataset.adminFormError='';form.prepend(target);}alert(error.message,target);sourceForm=null;}finally{busy=false;}
            },{capture:true,signal});
            if(context.page==='scoreboard')window.AdminScoreTransport={send:async data=>{
                const command={operation:'score_action',id:Number(data.match_id),data,policy:'update'};
                const result=await request({action:'preview',command});
                const simple=['assign_numbers','start','adjust','volleyball_point','volleyball_set','record_battle','remove_battle','save_cube_attempts','await_result','resume'].includes(data.action);
                if(simple&&result.can_execute&&result.impact.match_count<=1){await request({action:'execute',preview_id:result.preview_id,reason:'Lançamento de placar: '+data.action});const response=await fetch('api/score.php?id='+data.match_id,{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw new Error('Atualize para conferir o placar.');return response.json();}
                lastCommand=command;sourceForm=null;formNodes=null;title.textContent='Confirmar resultado';showPreview(result,data.reason||'Resultado registrado no painel de placar');show();
                return new Promise((resolve,reject)=>scoreWaiting={resolve,reject,id:Number(data.match_id)});
            }};
        }
        load();
    };
    if(!document.getElementById('event-workspace')){
        const config=document.querySelector('[data-workspace-config=admin]');
        if(config)window.EventWorkspaceInitializers.admin(document,parse(config.textContent),new AbortController().signal);
    }
})();
