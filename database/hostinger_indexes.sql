-- Execute uma vez no MySQL da Hostinger, preferencialmente pelo phpMyAdmin.
-- Se algum indice ja existir, remova a linha correspondente ou ignore o erro desse indice.

ALTER TABLE matches
  ADD INDEX idx_matches_event_modality_gender_stage_status (event_id, modality, gender, stage, status),
  ADD INDEX idx_matches_event_modality_gender_stage_sort (event_id, modality, gender, stage, sort_order, id),
  ADD INDEX idx_matches_event_modality_gender_group_stage_status (event_id, modality, gender, group_name, stage, status),
  ADD INDEX idx_matches_event_modality_starts_at (event_id, modality, starts_at),
  ADD INDEX idx_matches_status_starts_at (status, starts_at);

ALTER TABLE teams
  ADD INDEX idx_teams_event_modality_gender_group (event_id, modality, gender, group_name),
  ADD INDEX idx_teams_representative (representative_id);

ALTER TABLE players
  ADD INDEX idx_players_team_name (team_id, name);

ALTER TABLE score_logs
  ADD INDEX idx_score_logs_match_id_created_at (match_id, created_at),
  ADD INDEX idx_score_logs_match_id_id (match_id, id);

ALTER TABLE occurrences
  ADD INDEX idx_occurrences_event_match_team (event_id, match_id, team_id);
