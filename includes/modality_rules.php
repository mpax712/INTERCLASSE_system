<?php

/** One definition per modality. Event overrides and match snapshots only contain values. */
function modality_definitions(): array
{
    return [
        'futsal' => ['label' => 'Futsal', 'score_type' => 'gols', 'participant' => 'team', 'mixed' => false, 'formats' => ['groups', 'simple', 'round_robin'], 'default_format' => 'groups', 'player_limit' => 10],
        'handbol' => ['label' => 'Handbol', 'score_type' => 'gols', 'participant' => 'team', 'mixed' => false, 'formats' => ['groups', 'simple', 'round_robin'], 'default_format' => 'groups', 'player_limit' => 10],
        'queimada' => ['label' => 'Queimada', 'score_type' => 'pontos', 'participant' => 'team', 'mixed' => false, 'formats' => ['groups', 'simple', 'round_robin'], 'default_format' => 'groups', 'player_limit' => 999],
        'volei' => ['label' => 'Vôlei', 'score_type' => 'sets', 'participant' => 'team', 'mixed' => false, 'formats' => ['groups', 'simple', 'round_robin'], 'default_format' => 'groups', 'player_limit' => 10],
        'basquete' => ['label' => 'Basquete', 'score_type' => 'pontos', 'participant' => 'team', 'mixed' => false, 'formats' => ['groups', 'simple', 'round_robin'], 'default_format' => 'groups', 'player_limit' => 10],
        'cubo_magico' => ['label' => 'Cubo Mágico 3x3', 'score_type' => 'tempo', 'participant' => 'player', 'mixed' => true, 'formats' => ['simple', 'round_robin'], 'default_format' => 'simple', 'player_limit' => 2],
        'xadrez' => ['label' => 'Xadrez', 'score_type' => 'resultado', 'participant' => 'player', 'mixed' => true, 'formats' => ['simple'], 'default_format' => 'simple', 'player_limit' => 2],
        'quiz' => ['label' => 'Quiz', 'score_type' => 'pontos', 'participant' => 'team', 'mixed' => true, 'formats' => ['simple', 'round_robin'], 'default_format' => 'simple', 'player_limit' => 2],
        'fifa' => ['label' => 'FIFA', 'score_type' => 'gols', 'participant' => 'player', 'mixed' => true, 'formats' => ['groups', 'simple'], 'default_format' => 'groups', 'player_limit' => 32, 'rules' => ['win_points' => 3, 'draw_points' => 1, 'loss_points' => 0, 'wo_goals' => 3, 'class_points_first' => 10, 'class_points_second' => 8, 'class_points_third' => 6, 'class_points_other' => 2]],
        'clash_royale' => ['label' => 'Clash Royale', 'score_type' => 'batalhas', 'participant' => 'player', 'mixed' => true, 'formats' => ['simple'], 'default_format' => 'simple', 'player_limit' => 32, 'rules' => ['best_of' => 3, 'class_points_first' => 10, 'class_points_second' => 8, 'class_points_third' => 6, 'class_points_other' => 2]],
    ];
}

function modality_definition(string $modality): array
{
    return modality_definitions()[$modality] ?? [];
}

function game_is_new_modality(string $modality): bool
{
    return in_array($modality, ['fifa', 'clash_royale'], true);
}

function validate_game_rules(string $modality, array $input): array
{
    $defaults = modality_definition($modality)['rules'] ?? [];
    $classPoints = [];
    if (game_is_new_modality($modality)) {
        foreach (['class_points_first', 'class_points_second', 'class_points_third', 'class_points_other'] as $key) {
            $value = filter_var($input[$key] ?? $defaults[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 50) throw new InvalidArgumentException('Pontuação por colocação inválida: ' . $key);
            $classPoints[$key] = $value;
        }
        if ($classPoints['class_points_first'] < $classPoints['class_points_second'] || $classPoints['class_points_second'] < $classPoints['class_points_third'] || $classPoints['class_points_third'] < $classPoints['class_points_other']) {
            throw new InvalidArgumentException('Pontos por colocação devem seguir 1º ≥ 2º ≥ 3º ≥ demais.');
        }
    }
    if ($modality === 'clash_royale') {
        $bestOf = (int) ($input['best_of'] ?? $defaults['best_of']);
        if (!in_array($bestOf, [1, 3, 5], true)) {
            throw new InvalidArgumentException('Melhor de deve ser 1, 3 ou 5.');
        }
        return ['best_of' => $bestOf] + $classPoints;
    }
    if ($modality === 'fifa') {
        $rules = [];
        foreach (['win_points', 'draw_points', 'loss_points', 'wo_goals'] as $key) {
            $value = filter_var($input[$key] ?? $defaults[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > 20) {
                throw new InvalidArgumentException('Regra de FIFA inválida: ' . $key);
            }
            $rules[$key] = $value;
        }
        if ($rules['win_points'] < $rules['draw_points'] || $rules['draw_points'] < $rules['loss_points']) {
            throw new InvalidArgumentException('A pontuação deve seguir vitória ≥ empate ≥ derrota.');
        }
        return $rules + $classPoints;
    }
    return [];
}

function game_event_rules(int $eventId, string $modality): array
{
    $defaults = modality_definition($modality)['rules'] ?? [];
    if (!game_is_new_modality($modality)) {
        return $defaults;
    }
    $stmt = db()->prepare('SELECT rules_json FROM event_modalities WHERE event_id = ? AND modality = ?');
    $stmt->execute([$eventId, $modality]);
    $stored = $stmt->fetchColumn();
    $decoded = $stored ? json_decode((string) $stored, true) : [];
    return validate_game_rules($modality, is_array($decoded) ? array_replace($defaults, $decoded) : $defaults);
}

function game_match_rules(array $match): array
{
    $snapshot = $match['rules_snapshot'] ?? null;
    $decoded = $snapshot ? json_decode((string) $snapshot, true) : [];
    return validate_game_rules((string) $match['modality'], is_array($decoded) && $decoded ? $decoded : game_event_rules((int) $match['event_id'], (string) $match['modality']));
}
