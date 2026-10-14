<?php
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../services/AdminCompetitionService.php';
$actor = require_api_roles(['admin']);
if (!db()->activeViewsEnabled) json_response(['error'=>'A migração administrativa ainda não foi aplicada.'],503);
$service = new AdminCompetitionService();
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? '') === 'history') {
            if(!empty($_GET['operation_id'])){
                $detail=db()->prepare('SELECT a.*,u.name AS actor_name FROM admin_operations a LEFT JOIN users u ON u.id=a.user_id WHERE a.id=?');$detail->execute([$_GET['operation_id']]);$row=$detail->fetch();if(!$row)json_response(['error'=>'Histórico não encontrado.'],404);json_response(['data'=>$row]);
            }
            $stmt = db()->query('SELECT a.id,a.user_id,u.name AS actor_name,a.created_at,a.operation,a.reason,a.impact_json,a.changes_json
                FROM admin_operations a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 1000');
            $state=$service->snapshot();$rows=[];
            foreach($stmt->fetchAll() as $row){$changes=json_decode($row['changes_json'],true)??[];$context=['entities'=>array_keys($changes),'events'=>[],'modalities'=>[],'team_ids'=>[],'match_ids'=>[]];
                foreach($changes as $table=>$items)foreach($items as $change)foreach(['before','after'] as $side){$r=$change[$side]??null;if(!$r)continue;
                    if($table==='events')$context['events'][]=(int)$r['id'];
                    if($table==='matches')$context['match_ids'][]=(int)$r['id'];
                    if($table==='teams')$context['team_ids'][]=(int)$r['id'];
                    if($table==='players'){$teamId=(int)$r['team_id'];$context['team_ids'][]=$teamId;$r=$changes['teams'][$teamId][$side]??$state['teams'][$teamId]??[];}
                    if(isset($r['event_id']))$context['events'][]=(int)$r['event_id'];if(isset($r['modality']))$context['modalities'][]=$r['modality'];
                }
                foreach(['events','modalities','team_ids','match_ids'] as $key)$context[$key]=array_values(array_unique($context[$key]));unset($row['changes_json']);$row['context']=$context;
                if(!empty($_GET['event_id'])&&!in_array((int)$_GET['event_id'],$context['events'],true))continue;
                if(!empty($_GET['modality'])&&!in_array($_GET['modality'],$context['modalities'],true))continue;
                if(!empty($_GET['team_id'])&&!in_array((int)$_GET['team_id'],$context['team_ids'],true))continue;
                if(!empty($_GET['match_id'])&&!in_array((int)$_GET['match_id'],$context['match_ids'],true))continue;
                if(!empty($_GET['entity'])&&!in_array($_GET['entity'],$context['entities'],true))continue;
                $rows[]=$row;if(count($rows)>=100)break;
            }
            json_response(['data'=>$rows]);
        }
        json_response(['data'=>$service->snapshot(), 'definitions'=>modality_definitions(), 'user_options'=>db()->query('SELECT id,name,role FROM users ORDER BY name')->fetchAll(), 'csrf_token'=>csrf_token()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['error'=>'Método não permitido.'],405);
    $data = json_input(); verify_api_csrf($data);
    if (($data['action'] ?? '') === 'preview') json_response($service->preview($data['command'] ?? [],$actor));
    if (($data['action'] ?? '') === 'execute') json_response($service->execute((string)($data['preview_id']??''),(string)($data['reason']??''),$data['confirmation_word']??null,$actor));
    json_response(['error'=>'Operação inválida.'],422);
} catch (AdminOperationException $error) { json_response(['error'=>$error->getMessage()],$error->httpStatus); }
catch (GameScoreException $error) { json_response(['error'=>$error->getMessage()],$error->httpStatus); }
catch (PDOException $error) {
    if (in_array((string)$error->getCode(),['23000','23505','23503'],true)) json_response(['error'=>'Já existe um registro com esses dados ou há dependências a resolver. Confira a prévia e os registros relacionados.'],422);
    throw $error;
}
