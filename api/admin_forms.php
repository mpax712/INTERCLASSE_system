<?php
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../includes/admin_ui.php';
$actor=require_api_roles(['admin']);
if($_SERVER['REQUEST_METHOD']!=='POST')json_response(['error'=>'Método não permitido.'],405);
verify_api_csrf($_POST);
try{
    $command=admin_ui_command((string)($_POST['context_page']??''),$_POST);$command['policy']=$_POST['policy']??'update';
    $preview=(new AdminCompetitionService())->preview($command,$actor);
    $extra=[];if(($command['entity']??'')==='events'&&empty($command['id'])){$s=db()->prepare('SELECT plan_json FROM admin_operation_previews WHERE id=?');$s->execute([$preview['preview_id']]);$plan=json_decode($s->fetchColumn(),true);$extra['created_event_id']=(int)array_key_first($plan['changes']['events']??[]);}
    json_response($preview+['command'=>$command,'reason'=>$_POST['correction_reason']??$_POST['reason']??'']+$extra);
}catch(AdminOperationException|GameScoreException $e){json_response(['error'=>$e->getMessage()],$e->httpStatus);}
catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','23505','23503'],true))json_response(['error'=>'Confira os dados: já existe um cadastro igual ou há referências a resolver.'],422);throw $e;}
