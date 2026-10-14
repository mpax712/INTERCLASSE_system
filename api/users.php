<?php

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    require_api_roles(['admin']);
    $data = json_input();
    verify_api_csrf($data);
    
    $userIds = $data['user_ids'] ?? [];
    
    if (empty($userIds) || !is_array($userIds)) {
        json_response(['error' => 'IDs de usuários inválidos.'], 422);
    }
    
    $deleted = 0;
    $errors = [];
    
    foreach ($userIds as $userId) {
        $userId = (int) $userId;
        
        if ($userId === 0) {
            continue;
        }
        
        // Check if user is the current admin
        $currentUser = current_user();
        if ($userId === (int) $currentUser['id']) {
            $errors[] = "Não é possível excluir o usuário atual.";
            continue;
        }
        
        // Check if user has teams or players associated
        $teamCheck = db()->prepare('SELECT COUNT(*) FROM teams WHERE representative_id = ?');
        $teamCheck->execute([$userId]);
        $teamCount = (int) $teamCheck->fetchColumn();
        
        if ($teamCount > 0) {
            $errors[] = "Usuário ID {$userId} é representante de times e não pode ser excluído.";
            continue;
        }
        
        try {
            $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $deleted++;
        } catch (PDOException $exception) {
            $errors[] = "Erro ao excluir usuário ID {$userId}.";
        }
    }
    
    if ($deleted > 0) {
        $message = "{$deleted} usuários excluídos com sucesso.";
        if ($errors) {
            $message .= " " . count($errors) . " não puderam ser excluídos.";
        }
        json_response(['ok' => true, 'message' => $message, 'deleted' => $deleted, 'errors' => $errors]);
    } else {
        json_response(['error' => 'Nenhum usuário foi excluído.' . ($errors ? ' ' . implode(' ', $errors) : '')], 422);
    }
}

json_response(['error' => 'Método não permitido.'], 405);
