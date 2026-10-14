<?php
require_roles(['admin']);

$eventId = (int) ($_GET['event_id'] ?? 0);
$modality = $_GET['modality'] ?? 'futsal';

if (!$eventId) {
    redirect('index.php?page=events');
}

if (!can_manage_event_structure($eventId)) {
    http_response_code(403);
    exit('Apenas administradores podem exportar classificações.');
}

$eventStmt = db()->prepare('SELECT id FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();

if (!$event) {
    http_response_code(404);
    exit('Evento não encontrado.');
}

header('Content-Type: text/csv; charset=utf-8');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

header('Content-Disposition: attachment; filename="classificacao-' . $eventId . '-' . $modality . '.csv"');
$rows = calculate_standings($eventId, $modality);
fputcsv($output, ['Grupo', 'Time', 'P', 'J', 'V', 'D', 'GP', 'GC', 'SG'], ';');

foreach ($rows as $row) {
    fputcsv($output, [$row['group_name'], $row['team_name'], $row['P'], $row['J'], $row['V'], $row['D'], $row['GP'], $row['GC'], $row['SG']], ';');
}

exit;
