<?php

require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    require_api_roles(['admin']);
    $data = json_input();
    verify_api_csrf($data);
    
    $classStandingId = (int) ($data['id'] ?? 0);
    $pointsToDeduct = (int) ($data['deduct_points'] ?? 0);
    
    if (!$classStandingId) {
        json_response(['error' => 'ID da classificação inválido.'], 422);
    }
    
    if (!in_array($pointsToDeduct, [5, 10], true)) {
        json_response(['error' => 'Valor de pontos inválido. Deve ser 5 ou 10.'], 422);
    }
    
    // Get current total points
    $stmt = db()->prepare('SELECT total_points FROM class_standings WHERE id = ?');
    $stmt->execute([$classStandingId]);
    $currentStanding = $stmt->fetch();
    
    if (!$currentStanding) {
        json_response(['error' => 'Classificação não encontrada.'], 404);
    }
    
    $newTotalPoints = max(0, (int) $currentStanding['total_points'] - $pointsToDeduct);
    
    // Update total points
    $updateStmt = db()->prepare('UPDATE class_standings SET total_points = ? WHERE id = ?');
    $updateStmt->execute([$newTotalPoints, $classStandingId]);
    
    json_response(['ok' => true, 'new_total' => $newTotalPoints]);
}

json_response(['error' => 'Método não permitido.'], 405);
