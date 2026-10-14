<?php

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../services/ExportService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$user = require_api_roles(['admin']);
verify_api_csrf($_GET);
$type = $_GET['type'] ?? '';
$eventId = (int) ($_GET['event_id'] ?? 0);
$modality = $_GET['modality'] ?? '';
$gender = $_GET['gender'] ?? 'masculino';

if (!$eventId) {
    json_response(['error' => 'ID do evento não informado.'], 422);
}

if (!can_manage_event_structure($eventId)) {
    json_response(['error' => 'Apenas administradores podem exportar súmulas.'], 403);
}

try {
    if ($type === 'modality_general') {
        if (!$modality) {
            json_response(['error' => 'Modalidade não informada.'], 422);
        }

        if (!in_array($modality, allowed_modalities(), true)) {
            json_response(['error' => 'Modalidade inválida.'], 422);
        }

        if (!ExportService::hasMatchesAnyGender($eventId, $modality)) {
            json_response(['error' => 'Não há jogos cadastrados nesta modalidade para exportar.'], 422);
        }

        $csvContent = ExportService::generateModalityAllGendersSummaryCsv($eventId, $modality);
        $filename = "sumula-geral-{$modality}-evento-{$eventId}.csv";

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csvContent));

        echo $csvContent;
        exit;

    } elseif ($type === 'general') {
        $csvContent = ExportService::generateGeneralStandingsCsv($eventId);
        
        $filename = "classificacao-geral-evento-{$eventId}.csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csvContent));
        
        echo $csvContent;
        exit;

    } else {
        json_response(['error' => 'Tipo de exportação inválido. Use "modality_general" ou "general".'], 422);
    }

} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}
