<?php
require_roles(['admin']);

$eventId = (int) ($_GET['event_id'] ?? 0);

if (!$eventId) {
    redirect('index.php?page=events');
}

if (!can_manage_event_structure($eventId)) {
    http_response_code(403);
    exit('Apenas administradores podem exportar classificações.');
}

$eventStmt = db()->prepare('SELECT id, name, start_date, end_date, place FROM events WHERE id = ?');
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch();

if (!$event) {
    http_response_code(404);
    exit('Evento não encontrado.');
}

header('Content-Type: text/csv; charset=utf-8');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

header('Content-Disposition: attachment; filename="classificacao-geral-turmas-' . $eventId . '.csv"');

$classStandings = get_class_standings($eventId);

fputcsv($output, ['CLASSIFICACAO GERAL DAS TURMAS - ' . $event['name']], ';');
fputcsv($output, ['Periodo', date('d/m/Y', strtotime($event['start_date'])) . ($event['end_date'] ? ' a ' . date('d/m/Y', strtotime($event['end_date'])) : '')], ';');
fputcsv($output, ['Local do evento', $event['place'] ?: 'A definir'], ';');
fputcsv($output, ['Gerado em', date('d/m/Y H:i')], ';');
fputcsv($output, [], ';');
fputcsv($output, ['Pontuação por posição: 1º lugar = 10 pontos | 2º lugar = 8 pontos | 3º lugar = 6 pontos | 4º lugar ou abaixo = 4 pontos'], ';');
fputcsv($output, [], ';');
fputcsv($output, ['Posição', 'Turma', 'Total de pontos', 'Detalhes por modalidade'], ';');

foreach ($classStandings as $index => $row) {
    fputcsv($output, [
        $index + 1,
        $row['class_name'],
        (int) $row['total_points'],
        $row['details'] ?? '-'
    ], ';');
}

exit;
