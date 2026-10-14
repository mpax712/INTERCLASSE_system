<?php

class ExportService
{
    /**
     * Check if all matches for a modality/gender are finished
     */
    public static function areAllMatchesFinished(int $eventId, string $modality, string $gender = 'masculino'): bool
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status NOT IN ("encerrada", "wo") THEN 1 ELSE 0 END) AS pending
             FROM matches
             WHERE event_id = ? AND modality = ? AND gender = ?'
        );
        $stmt->execute([$eventId, $modality, $gender]);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0) > 0 && (int) ($row['pending'] ?? 0) === 0;
    }

    /**
     * Check if there are any matches for a modality/gender
     */
    public static function hasMatches(int $eventId, string $modality, string $gender = 'masculino'): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM matches WHERE event_id = ? AND modality = ?';
        $params = [$eventId, $modality];

        if (!is_mixed_modality($modality)) {
            $sql .= ' AND gender = ?';
            $params[] = $gender;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0) > 0;
    }

    public static function hasMatchesAnyGender(int $eventId, string $modality): bool
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total FROM matches
             WHERE event_id = ? AND modality = ?'
        );
        $stmt->execute([$eventId, $modality]);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0) > 0;
    }

    /**
     * Get event data
     */
    public static function getEventData(int $eventId): ?array
    {
        $stmt = db()->prepare('SELECT id, name, start_date, end_date, place FROM events WHERE id = ?');
        $stmt->execute([$eventId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get all matches for a modality with team and player data
     */
    public static function getModalityMatches(int $eventId, string $modality, string $gender = 'masculino'): array
    {
        $sql = 'SELECT m.id, m.event_id, m.modality, m.gender, m.stage, m.group_name,
                    m.home_team_id, m.away_team_id, m.home_score, m.away_score,
                    m.score_data, m.status, m.starts_at, m.place, m.winner_team_id, m.home_player_id, m.away_player_id, m.winner_player_id, m.sort_order,
                    ht.name AS home_team_name, ht.class_name AS home_class_name,
                    at.name AS away_team_name, at.class_name AS away_class_name,
                    ht.name AS home_name, at.name AS away_name,
                    COALESCE(nhp.name, hp.name) AS home_bracket_player_name, COALESCE(nhpt.class_name, hpt.class_name) AS home_bracket_player_class_name,
                    COALESCE(nap.name, ap.name) AS away_bracket_player_name, COALESCE(napt.class_name, apt.class_name) AS away_bracket_player_class_name
             FROM matches m
             LEFT JOIN teams ht ON m.home_team_id = ht.id
             LEFT JOIN teams at ON m.away_team_id = at.id
             LEFT JOIN players hp ON hp.id = CAST(m.score_data ->> "bracket_home_player_id" AS INTEGER)
             LEFT JOIN teams hpt ON hpt.id = hp.team_id
             LEFT JOIN players ap ON ap.id = CAST(m.score_data ->> "bracket_away_player_id" AS INTEGER)
             LEFT JOIN teams apt ON apt.id = ap.team_id
             LEFT JOIN players nhp ON nhp.id = m.home_player_id
             LEFT JOIN teams nhpt ON nhpt.id = nhp.team_id
             LEFT JOIN players nap ON nap.id = m.away_player_id
             LEFT JOIN teams napt ON napt.id = nap.team_id
             WHERE m.event_id = ? AND m.modality = ?';
        $params = [$eventId, $modality];

        if (!is_mixed_modality($modality)) {
            $sql .= ' AND m.gender = ?';
            $params[] = $gender;
        }

        $sql .= ' ORDER BY m.gender, array_position(ARRAY["grupo", "repescagem", "preliminar", "dezesseis_avos", "oitavas", "quartas", "semifinal", "final", "individual"], m.stage::text), m.sort_order, m.starts_at, m.id';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $matches = $stmt->fetchAll();

        $teamIds = [];
        foreach ($matches as $match) {
            foreach (['home_team_id', 'away_team_id'] as $field) {
                $teamId = (int) ($match[$field] ?? 0);
                if ($teamId > 0) {
                    $teamIds[$teamId] = $teamId;
                }
            }
        }

        $playersByTeam = [];
        if ($teamIds) {
            $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
            $playersStmt = db()->prepare(
                "SELECT id, team_id, name, email, birth_date, jersey_number
                 FROM players
                 WHERE team_id IN ($placeholders)
                 ORDER BY team_id, jersey_number, name"
            );
            $playersStmt->execute(array_values($teamIds));
            foreach ($playersStmt->fetchAll() as $player) {
                $playersByTeam[(int) $player['team_id']][] = $player;
            }
        }

        foreach ($matches as &$match) {
            $homeTeamId = (int) ($match['home_team_id'] ?? 0);
            $awayTeamId = (int) ($match['away_team_id'] ?? 0);
            $match['home_players'] = $homeTeamId > 0 ? ($playersByTeam[$homeTeamId] ?? []) : [];
            $match['away_players'] = $awayTeamId > 0 ? ($playersByTeam[$awayTeamId] ?? []) : [];
        }
        unset($match);

        return $matches;
    }

    /**
     * Get players for a team
     */
    public static function getTeamPlayers(int $teamId): array
    {
        $stmt = db()->prepare(
            'SELECT id, name, email, birth_date, jersey_number 
             FROM players 
             WHERE team_id = ? 
             ORDER BY jersey_number, name'
        );
        $stmt->execute([$teamId]);
        return $stmt->fetchAll();
    }

    /**
     * Get final standings for a modality
     */
    public static function getModalityStandings(int $eventId, string $modality, string $gender = 'masculino'): array
    {
        if (game_is_new_modality($modality)) {
            require_once __DIR__ . '/GameCompetition.php';
            return bracket_type_for_event_modality($eventId, $modality) === 'groups'
                ? game_group_standings($eventId, $modality)
                : game_knockout_standings($eventId, $modality);
        }
        return calculate_standings($eventId, $modality, null, $gender);
    }

    /**
     * Get general class standings
     */
    public static function getClassStandings(int $eventId): array
    {
        return get_class_standings($eventId);
    }

    public static function generateModalityAllGendersSummaryCsv(int $eventId, string $modality): string
    {
        $event = self::getEventData($eventId);
        $output = fopen('php://memory', 'r+');
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, ['SÚMULA GERAL - ' . strtoupper(modality_label($modality))], ';');
        fputcsv($output, ['Evento', $event['name']], ';');
        fputcsv($output, ['Período', self::formatPeriod($event)], ';');
        fputcsv($output, ['Local', $event['place'] ?: 'A definir'], ';');
        fputcsv($output, ['Gerado em', date('d/m/Y H:i')], ';');
        fputcsv($output, [], ';');

        $genders = is_mixed_modality($modality) ? ['masculino'] : ['masculino', 'feminino'];
        foreach ($genders as $gender) {
            $matches = self::getModalityMatches($eventId, $modality, $gender);
            $standings = self::getModalityStandings($eventId, $modality, $gender);

            fputcsv($output, ['=== ' . (is_mixed_modality($modality) ? 'GERAL' : strtoupper($gender)) . ' ==='], ';');
            fputcsv($output, [], ';');

            fputcsv($output, ['JOGOS'], ';');
            fputcsv($output, ['Fase', 'Grupo', 'Data/Hora', 'Local', 'Casa', 'Visitante', 'Placar/Resultado', 'Vencedor', 'Status'], ';');

            if (!$matches) {
                fputcsv($output, ['Nenhuma partida cadastrada.'], ';');
            }

            foreach ($matches as $match) {
                fputcsv($output, [
                    self::getStageLabel($match['stage']),
                    $match['group_name'] ?: '-',
                    $match['starts_at'] ? date('d/m/Y H:i', strtotime($match['starts_at'])) : 'A definir',
                    $match['place'] ?: $event['place'] ?: 'A definir',
                    self::sideLabel($match, 'home'),
                    self::sideLabel($match, 'away'),
                    match_score_text($match),
                    self::winnerLabel($match),
                    match_status_label($match['status']),
                ], ';');
            }

            fputcsv($output, [], ';');
            fputcsv($output, ['CLASSIFICAÇÃO'], ';');
            fputcsv($output, ['Posição', 'Grupo', 'Time/Turma', 'Pontos', 'Jogos', 'Vitórias', 'Derrotas', 'Saldo'], ';');

            if (!$standings) {
                fputcsv($output, ['Classificação ainda indisponível.'], ';');
            }

            foreach ($standings as $index => $row) {
                fputcsv($output, [
                    $index + 1,
                    ($row['group_name'] ?? '') ?: '-',
                    $row['player_name'] ?? $row['team_name'] ?? '-',
                    (int) ($row['P'] ?? 0),
                    (int) ($row['J'] ?? 0),
                    (int) ($row['V'] ?? 0),
                    (int) ($row['D'] ?? 0),
                    (int) ($row['SG'] ?? 0),
                ], ';');
            }

            fputcsv($output, [], ';');
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Generate general standings summary CSV
     */
    public static function generateGeneralStandingsCsv(int $eventId): string
    {
        $event = self::getEventData($eventId);
        $classStandings = self::getClassStandings($eventId);

        $output = fopen('php://memory', 'r+');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM

        // Header section
        fputcsv($output, ['CLASSIFICAÇÃO GERAL DO INTERCLASSE'], ';');
        fputcsv($output, ['Evento', $event['name']], ';');
        fputcsv($output, ['Período', date('d/m/Y', strtotime($event['start_date'])) . ($event['end_date'] ? ' a ' . date('d/m/Y', strtotime($event['end_date'])) : '')], ';');
        fputcsv($output, ['Local', $event['place'] ?: 'A definir'], ';');
        fputcsv($output, ['Gerado em', date('d/m/Y H:i')], ';');
        fputcsv($output, [], ';');

        // Scoring rules
        fputcsv($output, ['PONTUAÇÃO POR POSIÇÃO'], ';');
        fputcsv($output, ['1º lugar = 10 pontos | 2º lugar = 8 pontos | 3º lugar = 6 pontos | 4º lugar ou abaixo = 2 pontos (perde os 2 pontos se tiver W.O.)'], ';');
        fputcsv($output, [], ';');

        fputcsv($output, ['=== RESUMO POR MODALIDADE ==='], ';');
        fputcsv($output, [], ';');
        fputcsv($output, ['Modalidade', 'Categoria', 'Campeão', 'Grupo campeão', 'Vice', 'Grupo vice', '3º lugar', 'Grupo 3º', 'Pontos do campeão', 'Pontos do vice', 'Status'], ';');

        foreach (self::getEventModalityResults($eventId) as $result) {
            fputcsv($output, [
                modality_label($result['modality']),
                self::genderLabel($result['gender'], $result['modality']),
                $result['champion'] ?: 'A definir',
                $result['champion_group'] ?: '-',
                $result['runner_up'] ?: 'A definir',
                $result['runner_up_group'] ?: '-',
                $result['third_place'] ?: 'A definir',
                $result['third_place_group'] ?: '-',
                $result['champion_points'] !== null ? (int) $result['champion_points'] : '-',
                $result['runner_up_points'] !== null ? (int) $result['runner_up_points'] : '-',
                $result['status'],
            ], ';');
        }

        fputcsv($output, [], ';');

        // Standings
        fputcsv($output, ['=== CLASSIFICAÇÃO FINAL ==='], ';');
        fputcsv($output, [], ';');
        fputcsv($output, ['Posição', 'Turma', 'Total de Pontos', 'Detalhes por Modalidade'], ';');

        foreach ($classStandings as $index => $row) {
            fputcsv($output, [
                $index + 1,
                $row['class_name'],
                (int) $row['total_points'],
                $row['details'] ?? 'Aguardando finais'
            ], ';');
        }

        fputcsv($output, [], ';');
        fputcsv($output, ['Observações Gerais', ''], ';');

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Get stage label
     */
    private static function getStageLabel(string $stage): string
    {
        $labels = [
            'grupo' => 'Fase de Grupos',
            'repescagem' => 'Repescagem',
            'preliminar' => 'Preliminar',
            'dezesseis_avos' => '16 avos de Final',
            'oitavas' => 'Oitavas de Final',
            'quartas' => 'Quartas de Final',
            'semifinal' => 'Semifinal',
            'final' => 'Final'
        ];

        return $labels[$stage] ?? ucfirst($stage);
    }

    private static function getEventModalityResults(int $eventId): array
    {
        $modalitiesStmt = db()->prepare('SELECT modality FROM event_modalities WHERE event_id = ? ORDER BY array_position(ARRAY["futsal", "handbol", "queimada", "volei", "basquete", "cubo_magico", "xadrez", "quiz", "fifa", "clash_royale"], modality::text)');
        $modalitiesStmt->execute([$eventId]);
        $modalities = array_column($modalitiesStmt->fetchAll(), 'modality');
        $results = [];

        $pointsStmt = db()->prepare(
            'SELECT cmp.modality, cmp.gender, cmp.position, cmp.points, cs.class_name
             FROM class_modality_points cmp
             JOIN class_standings cs ON cs.id = cmp.class_standings_id
             WHERE cs.event_id = ?
             ORDER BY cmp.modality, cmp.gender, cmp.position'
        );
        $pointsStmt->execute([$eventId]);
        $pointsByKey = [];
        foreach ($pointsStmt->fetchAll() as $row) {
            $key = $row['modality'] . ':' . $row['gender'];
            $pointsByKey[$key][(int) $row['position']] = $row;
        }

        foreach ($modalities as $modality) {
            $genders = is_mixed_modality($modality) ? ['masculino'] : ['masculino', 'feminino'];
            foreach ($genders as $gender) {
                $key = $modality . ':' . $gender;
                $rows = $pointsByKey[$key] ?? [];
                $fallbackStandings = [];
                if (!$rows && self::hasMatches($eventId, $modality, $gender)) {
                    $fallbackStandings = self::getModalityStandings($eventId, $modality, $gender);
                }
                $groupsByClass = self::getGroupsByClassForModality($eventId, $modality, $gender);
                $champion = $rows[1]['class_name'] ?? ($fallbackStandings[0]['team_name'] ?? null);
                $runnerUp = $rows[2]['class_name'] ?? ($fallbackStandings[1]['team_name'] ?? null);
                $thirdPlace = $rows[3]['class_name'] ?? ($fallbackStandings[2]['team_name'] ?? null);

                $results[] = [
                    'modality' => $modality,
                    'gender' => $gender,
                    'champion' => $champion,
                    'champion_group' => $champion ? ($groupsByClass[$champion] ?? ($fallbackStandings[0]['group_name'] ?? null)) : null,
                    'runner_up' => $runnerUp,
                    'runner_up_group' => $runnerUp ? ($groupsByClass[$runnerUp] ?? ($fallbackStandings[1]['group_name'] ?? null)) : null,
                    'third_place' => $thirdPlace,
                    'third_place_group' => $thirdPlace ? ($groupsByClass[$thirdPlace] ?? ($fallbackStandings[2]['group_name'] ?? null)) : null,
                    'champion_points' => $rows[1]['points'] ?? ($fallbackStandings[0]['P'] ?? null),
                    'runner_up_points' => $rows[2]['points'] ?? ($fallbackStandings[1]['P'] ?? null),
                    'status' => $rows ? 'Finalizada' : ($fallbackStandings ? 'Parcial' : 'Aguardando resultado'),
                ];
            }
        }

        return $results;
    }

    private static function getGroupsByClassForModality(int $eventId, string $modality, string $gender): array
    {
        if (!uses_grouping($modality)) {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT class_name, group_name
             FROM teams
             WHERE event_id = ? AND modality = ? AND gender = ?
             ORDER BY class_name'
        );
        $stmt->execute([$eventId, $modality, $gender]);

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $className = trim((string) ($row['class_name'] ?? ''));
            if ($className !== '') {
                $groups[$className] = $row['group_name'] ?: '-';
            }
        }

        return $groups;
    }

    private static function formatPeriod(array $event): string
    {
        return date('d/m/Y', strtotime($event['start_date']))
            . ($event['end_date'] ? ' a ' . date('d/m/Y', strtotime($event['end_date'])) : '');
    }

    private static function genderLabel(string $gender, string $modality): string
    {
        if (is_mixed_modality($modality)) {
            return 'Misto';
        }

        return $gender === 'feminino' ? 'Feminino' : 'Masculino';
    }

    private static function bracketUsesPlayerNames(string $modality): bool
    {
        return in_array($modality, ['xadrez', 'cubo_magico', 'fifa', 'clash_royale'], true);
    }

    private static function sideLabel(array $match, string $side): string
    {
        $prefix = $side === 'home' ? 'home' : 'away';
        if (self::bracketUsesPlayerNames($match['modality'] ?? '')) {
            $playerName = $match[$prefix . '_bracket_player_name'] ?? null;
            $playerClassName = $match[$prefix . '_bracket_player_class_name'] ?? null;

            if ($playerName) {
                return $playerClassName ? $playerName . ' · ' . $playerClassName : $playerName;
            }

            $players = $match[$prefix . '_players'] ?? [];
            if ($players) {
                $playerNames = array_filter(array_column($players, 'name'));
                if ($playerNames) {
                    $className = $match[$prefix . '_class_name'] ?? null;
                    $label = implode(' / ', $playerNames);

                    return $className ? $label . ' · ' . $className : $label;
                }
            }
        }

        $teamName = $match[$prefix . '_team_name'] ?? null;
        $className = $match[$prefix . '_class_name'] ?? null;

        if (!$teamName) {
            return 'A definir';
        }

        return $className && $className !== $teamName ? $teamName . ' (' . $className . ')' : $teamName;
    }

    private static function winnerLabel(array $match): string
    {
        if (!in_array($match['status'], ['encerrada', 'wo'], true)) {
            return 'Pendente';
        }

        $winnerId = (int) ($match['winner_team_id'] ?? 0);
        if (game_is_new_modality((string) $match['modality'])) {
            $winnerPlayerId = (int) ($match['winner_player_id'] ?? 0);
            if ($winnerPlayerId && $winnerPlayerId === (int) ($match['home_player_id'] ?? 0)) return self::sideLabel($match, 'home');
            if ($winnerPlayerId && $winnerPlayerId === (int) ($match['away_player_id'] ?? 0)) return self::sideLabel($match, 'away');
            return 'Empate';
        }
        if ($winnerId && $winnerId === (int) ($match['home_team_id'] ?? 0)) {
            return self::sideLabel($match, 'home');
        }
        if ($winnerId && $winnerId === (int) ($match['away_team_id'] ?? 0)) {
            return self::sideLabel($match, 'away');
        }

        return 'Empate';
    }
}
