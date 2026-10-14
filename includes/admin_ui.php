<?php
require_once __DIR__.'/../services/AdminCompetitionService.php';

function admin_ui_tools(array $context=[]):void
{
    if(!has_role(['admin']))return;
    $GLOBALS['adminWorkspaceLoaded']=true;
    $context+=['embedded'=>true,'csrfToken'=>csrf_token(),'page'=>$_GET['page']??'event','eventId'=>(int)($_GET['event_id']??$_GET['id']??0),'teamId'=>(int)($_GET['team_id']??0),'modality'=>$_GET['modality']??'','gender'=>$_GET['gender']??'masculino','tab'=>'matches'];
    echo '<div data-admin-panel data-admin-embedded class="admin-context-tools mb-3"><div data-admin-notice role="status"></div><div class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-sm btn-outline-secondary" data-admin-context-history>Histórico de alterações</button><button type="button" class="btn btn-sm btn-outline-secondary" data-admin-context-archives>Arquivados e restauração</button></div><div data-admin-content hidden></div></div>';
    if($context['tab']==='matches'&&in_array($context['page'],['event','match_manager'],true))echo '<button type="button" class="btn btn-sm btn-outline-secondary mb-3" data-admin-context-regenerate>Reorganizar confrontos</button>';
    echo '<script type="application/json" data-workspace-config="admin">'.json_encode($context,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE).'</script>';
}

/** Convert the familiar page forms to the same audited commands used by the API. */
function admin_ui_command(string $page,array $input):array
{
    $actor=current_user();if(($actor['role']??'')!=='admin')throw new AdminOperationException('Acesso negado.',403);
    $event=(int)($input['event_id']??0);$mod=(string)($input['modality']??'');$gender=(string)($input['gender']??'masculino');$action=(string)($input['action']??'');
    $service=new AdminCompetitionService();$state=$service->snapshot();
    $save=fn($entity,$id,$data)=>['operation'=>'save_entity','entity'=>$entity,'id'=>(int)$id,'data'=>$data];
    $archive=fn($entity,$id)=>['operation'=>'archive','entity'=>$entity,'id'=>(int)$id];
    $batch=fn($commands)=>['operation'=>'batch','commands'=>$commands];
    if($page==='classes'){
        if($action==='delete')return $archive('classes',$input['class_id']??0);
        if($action==='bulk_import'){
            $commands=[];$known=array_column($state['classes'],'name');foreach(preg_split('/\R/u',(string)($input['bulk_names']??'')) as $name){$name=trim($name);if($name!==''&&!in_array($name,$known,true)){$commands[]=$save('classes',0,['name'=>$name]);$known[]=$name;}}return $batch($commands);
        }
        return $save('classes',$input['class_id']??0,['name'=>$input['name']??'']);
    }
    if($page==='players'){
        $team=(int)($input['context_team_id']??$input['team_id']??0);
        if(!isset($state['teams'][$team]))throw new AdminOperationException('Equipe não encontrada.');
        if($action==='delete')return $archive('players',$input['player_id']??0);
        if($action==='bulk_import'){$commands=[];foreach(preg_split('/\R/u',(string)($input['bulk_data']??'')) as $name){$name=trim($name);if($name!=='')$commands[]=$save('players',0,['name'=>$name,'team_id'=>$team]);}return $batch($commands);}
        return $save('players',$input['player_id']??0,array_intersect_key($input,array_flip(['name','email','birth_date','jersey_number']))+['team_id'=>$team]);
    }
    if($page==='teams'||$action==='create_team'){
        if($action==='update_representative')return $save('teams',$input['team_id']??0,['representative_id'=>empty($input['representative_id'])?null:(int)$input['representative_id'],'representative_name'=>$input['representative_name']??'']);
        $ids=$action==='bulk'?($input['class_ids']??[]):[$input['class_id']??0];$commands=[];$counts=[];
        foreach($state['teams'] as $t)if(!$t['archived_at']&&$t['event_id']===$event&&$t['modality']===$mod&&(is_mixed_modality($mod)||$t['gender']===$gender)&&$t['group_name']!=='')$counts[$t['group_name']]=($counts[$t['group_name']]??0)+1;
        foreach($ids as $id){$class=$state['classes'][(int)$id]??null;if(!$class)throw new AdminOperationException('Turma não encontrada.');
            $group=$input['group_name']??'';if($group==='__new__')$group=$input['new_group_name']??'';
            if(!array_key_exists('group_name',$input)&&uses_grouping($mod)){
                $required=group_count_for_team_count(array_sum($counts)+1,4);for($i=0;$i<$required;$i++)$counts[chr(65+$i)]??=0;
                ksort($counts);$group=array_keys($counts,min($counts),true)[0];if($counts[$group]>=4)$group=chr(65+count($counts));$counts[$group]=($counts[$group]??0)+1;
            }
            $commands[]=$save('teams',0,['event_id'=>$event,'modality'=>$mod,'gender'=>$gender,'name'=>$input['name']??$class['name'],'class_name'=>$class['name'],'group_name'=>$group,'representative_id'=>empty($input['representative_id'])?null:(int)$input['representative_id'],'representative_name'=>$input['representative_name']??'']);
        }return count($commands)===1?$commands[0]:$batch($commands);
    }
    if($page==='create_event'||($page==='events'&&$action!=='status')){
        $data=array_intersect_key($input,array_flip(['name','description','start_date','end_date','place','status','group_count','best_thirds_count','teams_advancing_per_group','roster_edit_until','modalities','rules']));
        if(empty($data['modalities']))throw new AdminOperationException('Selecione pelo menos uma modalidade.');
        $data['modality_settings']=$input['formats']??$input['bracket_type']??[];
        $data['set_best_of']=$input['set_best_of']??3;$data['set_points']=$input['set_points']??25;$data['quarters_count']=$input['quarters_count']??4;
        return $save('events',0,$data);
    }
    if($page==='events'&&$action==='status')return $save('events',$event,['status'=>$input['status']??'']);
    if(in_array($action,['save_format','save_bracket_type','save_rules'],true))return ['operation'=>'save_settings','event_id'=>$event,'modality'=>$mod,'data'=>$action==='save_rules'?['rules'=>$input['rules']??[]]:['bracket_type'=>$input['bracket_type']??'']];
    if($action==='save_advancement')return ['operation'=>'save_advancement','event_id'=>$event,'modality'=>$mod,'gender'=>$gender,'data'=>['advancement_mode'=>$input['advancement_mode']??'global','teams_advancing_per_group'=>$input['teams_advancing_per_group']??2,'best_thirds_count'=>$input['best_thirds_count']??0,'groups'=>$input['group_advancing']??[]]];
    if($action==='move_team')return $save('teams',$input['team_id']??0,['group_name'=>$input['group_name']??'']);
    if($action==='delete_team')return $archive('teams',$input['team_id']??0);
    if($action==='delete_match')return $archive('matches',$input['match_id']??0);
    if(in_array($action,['generate_group','generate_groups','generate_round_robin','generate_bracket','generate_knockout','generate_repechage','generate_repechage_matches'],true))return ['operation'=>'regenerate','event_id'=>$event,'modality'=>$mod,'gender'=>$gender,'mode'=>'missing','kind'=>match($action){'generate_group','generate_groups'=>'groups','generate_round_robin'=>'round_robin','generate_repechage','generate_repechage_matches'=>'repescagem',default=>'simple'}];
    if(in_array($action,['save_match','create_match','save_schedule','correct_match','correct_match_result','update_match_place'],true)){
        $id=(int)($input['match_id']??0);$data=array_intersect_key($input,array_flip(['stage','group_name','home_team_id','away_team_id','home_player_id','away_player_id','starts_at','place','sort_order','home_score','away_score']));
        if(!$id)$data+=['event_id'=>$event,'modality'=>$mod,'gender'=>$gender,'status'=>'agendada'];
        if($id&&isset($state['matches'][$id])){
            $old=$state['matches'][$id];$sources=json_decode($old['qualification_sources']??'{}',true)??[];
            foreach(['home','away'] as $side){$changed=false;foreach(['team','player'] as $kind){$key=$side.'_'.$kind.'_id';if(!empty($data[$key])&&$data[$key]!=$old[$key])$changed=true;}if($changed){$data[$side.'_source_match_id']=null;$sources[$side]=['type'=>'direct'];}}
            if($sources)$data['qualification_sources']=$sources;
        }
        if(in_array($action,['correct_match','correct_match_result'],true)){
            $m=$state['matches'][$id]??throw new AdminOperationException('Partida não encontrada.');$data['status']='encerrada';$score=json_decode($m['score_data']??'{}',true)??[];
            if($m['modality']==='fifa')$score['penalties']=($input['home_penalties']??'')!==''&&($input['away_penalties']??'')!==''?['home'=>(int)$input['home_penalties'],'away'=>(int)$input['away_penalties']]:null;
            if($m['modality']==='clash_royale')$score['battles']=array_values(array_filter($input['battles']??[],fn($b)=>!empty($b['winner'])));
            if($m['status']==='wo'&&isset($input['winner_side'])){$data['status']='wo';$data['winner_side']=$input['winner_side'];}
            $data['score_data']=$score;
        }
        return ['operation'=>'save_match','id'=>$id,'data'=>$data];
    }
    throw new AdminOperationException('Ação não reconhecida. Use as opções desta página.');
}

function admin_ui_match_prepare(string $sql):PDOStatement
{
    $pdo=db();$previous=$pdo->includeArchived;$pdo->includeArchived=true;
    try{return $pdo->prepare($sql);}finally{$pdo->includeArchived=$previous;}
}

/** Progressive enhancement: the same preview and frozen command without JavaScript. */
function admin_ui_post_fallback(string $page,array $input):void
{
    verify_csrf();
    $service=new AdminCompetitionService();$actor=current_user();$return=$_SERVER['REQUEST_URI']??'index.php?page='.$page;
    echo '<h1 class="page-title">Confirmar alteração</h1>';
    try{
        if(isset($input['admin_confirm_preview'])){
            $service->execute((string)$input['admin_confirm_preview'],trim((string)($input['reason']??'')),$input['confirmation_word']??null,$actor);
            echo '<div class="alert alert-success">Alteração salva com histórico.</div><a class="btn btn-primary" href="'.e($return).'">Voltar à tela</a>';return;
        }
        $command=admin_ui_command($page,$input+['context_team_id'=>$_GET['team_id']??0,'event_id'=>$_GET['event_id']??$_GET['id']??0,'modality'=>$_GET['modality']??'','gender'=>$_GET['gender']??'masculino']);
        $command['policy']=$input['policy']??'update';$preview=$service->preview($command,$actor);
        echo '<p>Confira as partidas afetadas antes de salvar.</p>';
        foreach($preview['warnings']??[] as $warning)echo '<div class="alert alert-warning">'.e($warning).'</div>';
        foreach($preview['impact']['phases']??[] as $phase=>$rows){echo '<h2 class="h6">'.e($phase).'</h2><ul>';foreach($rows as $row)echo '<li>Partida #'.(int)$row['id'].': '.e($row['action']).'</li>';echo '</ul>';}
        if(!$preview['can_execute']){
            echo '<div class="alert alert-warning">Há resultados incompatíveis. Escolha como tratar as partidas afetadas.</div>';
            foreach(['restart'=>'Reiniciar partidas afetadas','manual'=>'Pausar para revisão manual'] as $policy=>$label){echo '<form method="post" class="mb-2">';admin_ui_hidden_inputs($input);echo '<input type="hidden" name="policy" value="'.e($policy).'"><button class="btn btn-outline-primary">'.e($label).'</button></form>';}
        }else{
            echo '<form method="post"><input type="hidden" name="csrf_token" value="'.e(csrf_token()).'"><input type="hidden" name="admin_confirm_preview" value="'.e($preview['preview_id']).'"><label class="form-label d-block">Motivo da alteração<textarea class="form-control" name="reason" required minlength="3" maxlength="500">'.e($input['correction_reason']??$input['reason']??'').'</textarea></label>';
            if($preview['confirmation_word'])echo '<label class="form-label d-block">Digite EXCLUIR<input class="form-control" name="confirmation_word" required></label>';
            echo '<button class="btn btn-primary">Confirmar e salvar</button></form>';
        }
    }catch(AdminOperationException|GameScoreException $error){http_response_code($error->httpStatus);echo '<div class="alert alert-danger">'.e($error->getMessage()).'</div><form method="post">';admin_ui_hidden_inputs($input);echo '<button class="btn btn-outline-primary">Tentar novamente com os dados enviados</button></form>';}
    echo '<a class="btn btn-outline-secondary mt-3" href="'.e($return).'">Cancelar e voltar</a>';
}

function admin_ui_hidden_inputs(array $input,string $prefix=''):void
{
    foreach($input as $key=>$value){$name=$prefix!==''?$prefix.'['.$key.']':$key;if(is_array($value))admin_ui_hidden_inputs($value,$name);else echo '<input type="hidden" name="'.e($name).'" value="'.e((string)$value).'">';}
}
