<?php
// Existing fixture helpers enforce an isolated database before any writes.
require __DIR__.'/admin_integration.php';
require_once __DIR__.'/../includes/admin_ui.php';

$f=fixture('handbol',2);$id=createMatch($f,0,1,'final');
function liveAction(int $id,string $action,array $extra=[]):void {
    operation(['operation'=>'score_action','id'=>$id,'data'=>['action'=>$action,'expected_version'=>matchRow($id)['version'],'request_id'=>bin2hex(random_bytes(16))]+$extra,'policy'=>'update']);
}
liveAction($id,'assign_numbers',['numbers'=>[$f['players'][0]=>1,$f['players'][1]=>1]]);
liveAction($id,'start');liveAction($id,'adjust',['team'=>'home','amount'=>1]);liveAction($id,'finish');
check(matchRow($id)['status']==='encerrada'&&matchRow($id)['home_score']===1,'placar tradicional mantém controles e permite evento encerrado');
$g=fixture('fifa',2);$game=createMatch($g,0,1,'final');liveAction($game,'start');liveAction($game,'adjust',['team'=>'home','amount'=>1]);liveAction($game,'finish');check(matchRow($game)['winner_player_id']===$g['players'][0],'placar FIFA usa regras e vencedor individual existentes');
$c=fixture('clash_royale',2);$clash=createMatch($c,0,1,'final');liveAction($clash,'start');foreach([2,3] as $crowns)liveAction($clash,'record_battle',['home_crowns'=>$crowns,'away_crowns'=>0,'winner'=>'home']);liveAction($clash,'finish');check(matchRow($clash)['home_score']===2,'placar Clash preserva melhor de três');
$suffix=bin2hex(random_bytes(4));$command=admin_ui_command('classes',['action'=>'bulk_import','bulk_names'=>"Turma UI $suffix A\nTurma UI $suffix B"]);$beforeCount=count($service->snapshot()['classes']);operation($command);check(count($service->snapshot()['classes'])===$beforeCount+2,'importação da tela de turmas usa prévia atômica');
$e=admin_ui_command('create_event',['name'=>'Evento UI','start_date'=>'2026-10-20','end_date'=>'','roster_edit_until'=>'','modalities'=>['volei','clash_royale'],'set_best_of'=>5,'set_points'=>21,'rules'=>['clash_royale'=>['best_of'=>5]]]);operation($e);$ev=max(array_keys($service->snapshot()['events']));$configs=array_values(array_filter($service->snapshot()['event_modalities'],fn($r)=>$r['event_id']===$ev));check(count($configs)===2&&($configs[0]['set_best_of']??0)===5,'formulário de evento preserva regras por modalidade');
operation(['operation'=>'save_entity','entity'=>'classes','data'=>['name'=>'Turma inscrição '.bin2hex(random_bytes(4))]]);$classId=max(array_keys($service->snapshot()['classes']));
$groupsBefore=array_column(array_filter($service->snapshot()['teams'],fn($t)=>$t['event_id']===$f['event']),'group_name','id');
operation(admin_ui_command('teams',['action'=>'single','event_id'=>$f['event'],'modality'=>'handbol','gender'=>'masculino','class_id'=>$classId]));$newTeam=$service->snapshot()['teams'][max(array_keys($service->snapshot()['teams']))];
check($newTeam['group_name']==='A'&&array_intersect_key(array_column($service->snapshot()['teams'],'group_name','id'),$groupsBefore)===$groupsBefore,'inscrição usa grupo disponível sem redistribuir equipes anteriores');
foreach(['volei','basquete','xadrez'] as $sport){
    $h=fixture($sport,2);$m=createMatch($h,0,1,'final');
    if($sport==='xadrez'){liveAction($m,'start');liveAction($m,'set_winner',['team'=>'home']);}
    else{liveAction($m,'assign_numbers',['numbers'=>[$h['players'][0]=>1,$h['players'][1]=>1]]);liveAction($m,'start');if($sport==='volei'){liveAction($m,'volleyball_set',['team'=>'home','amount'=>1]);liveAction($m,'volleyball_set',['team'=>'home','amount'=>1]);}else liveAction($m,'adjust',['team'=>'home','amount'=>3]);liveAction($m,'finish');}
    check(matchRow($m)['status']==='encerrada'&&matchRow($m)['winner_team_id']===$h['teams'][0],"controles originais do placar $sport");
}
$pendingFixture=fixture('fifa',2);$pendingGame=createMatch($pendingFixture,0,1,'final');
start_app_session();$_SESSION['user_id']=$actor['id'];$_SESSION['last_activity_at']=time();$sessionId=session_id();session_write_close();
file_put_contents(sys_get_temp_dir().'/interclasse-ui-restore/fixture.json',json_encode(['database'=>$namespace,'event_id'=>$f['event'],'team_id'=>$f['teams'][0],'player_id'=>$f['players'][0],'match_id'=>$id,'fifa_event_id'=>$g['event'],'fifa_match_id'=>$game,'pending_fifa_match_id'=>$pendingGame,'session_id'=>$sessionId]));
echo "SUCESSO: fluxos administrativos da interface verificados.\n";
