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
            $stmt = db()->query('SELECT a.id,a.user_id,u.name AS actor_name,a.created_at,a.operation,a.reason,a.impact_json
                FROM admin_operations a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100');
            json_response(['data'=>$stmt->fetchAll()]);
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
