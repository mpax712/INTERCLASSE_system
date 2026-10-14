CREATE TABLE `classes` (`id` INT NOT NULL AUTO_INCREMENT,
`name` VARCHAR(120) NOT NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (`id` INT NOT NULL AUTO_INCREMENT,
`name` VARCHAR(120) NOT NULL,
`email` VARCHAR(160) NOT NULL,
`password_hash` VARCHAR(255) NOT NULL,
`role` VARCHAR(20) NOT NULL DEFAULT 'representante',
`class_id` INT NULL,
`representative_gender` VARCHAR(20) NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_modalities` (`id` INT NOT NULL AUTO_INCREMENT,
`user_id` INT NOT NULL,
`modality` VARCHAR(40) NOT NULL,
`created_at` DATETIME(6) NULL DEFAULT CURRENT_TIMESTAMP(6),
PRIMARY KEY (id),
UNIQUE (user_id, modality)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `events` (`id` INT NOT NULL AUTO_INCREMENT,
`name` VARCHAR(160) NOT NULL,
`description` LONGTEXT NULL,
`created_by` INT NOT NULL,
`start_date` DATE NOT NULL,
`end_date` DATE NULL,
`place` VARCHAR(160) NULL,
`status` VARCHAR(30) NOT NULL DEFAULT 'rascunho',
`group_count` INT NOT NULL DEFAULT 2,
`best_thirds_count` INT NOT NULL DEFAULT 0,
`teams_advancing_per_group` INT NOT NULL DEFAULT 2,
`advancement_mode` VARCHAR(20) NOT NULL DEFAULT 'global',
`roster_edit_until` DATETIME(6) NULL,
`archived` SMALLINT NOT NULL DEFAULT 0,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_modalities` (`id` INT NOT NULL AUTO_INCREMENT,
`event_id` INT NOT NULL,
`modality` VARCHAR(40) NOT NULL,
`score_type` VARCHAR(20) NOT NULL,
`set_best_of` INT NULL,
`set_points` INT NULL,
`quarters_count` INT NULL,
`max_players_per_team` INT NULL,
`bracket_type` VARCHAR(30) NOT NULL DEFAULT 'groups',
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
`rules_json` JSON NULL,
UNIQUE (event_id, modality),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_group_advancement` (`id` INT NOT NULL AUTO_INCREMENT,
`event_id` INT NOT NULL,
`modality` VARCHAR(40) NOT NULL,
`gender` VARCHAR(20) NOT NULL DEFAULT 'masculino',
`group_name` VARCHAR(10) NOT NULL,
`teams_advancing` INT NOT NULL DEFAULT 0,
`created_at` DATETIME(6) NULL DEFAULT CURRENT_TIMESTAMP(6),
`updated_at` DATETIME(6) NULL ON UPDATE CURRENT_TIMESTAMP(6),
UNIQUE (event_id, modality, gender, group_name),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teams` (`id` INT NOT NULL AUTO_INCREMENT,
`event_id` INT NOT NULL,
`modality` VARCHAR(40) NOT NULL,
`name` VARCHAR(120) NOT NULL,
`class_name` VARCHAR(80) NULL,
`gender` VARCHAR(20) NULL DEFAULT 'masculino',
`group_name` VARCHAR(10) NOT NULL DEFAULT 'A',
`representative_id` INT NULL,
`representative_name` VARCHAR(160) NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `players` (`id` INT NOT NULL AUTO_INCREMENT,
`team_id` INT NOT NULL,
`user_id` INT NULL,
`name` VARCHAR(120) NOT NULL,
`email` VARCHAR(160) NULL,
`birth_date` DATE NULL,
`jersey_number` INT NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
`group_name` VARCHAR(10) NULL,
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `matches` (`id` INT NOT NULL AUTO_INCREMENT,
`event_id` INT NOT NULL,
`modality` VARCHAR(40) NOT NULL,
`stage` VARCHAR(30) NOT NULL DEFAULT 'grupo',
`group_name` VARCHAR(10) NULL,
`home_team_id` INT NULL,
`away_team_id` INT NULL,
`home_score` INT NOT NULL DEFAULT 0,
`away_score` INT NOT NULL DEFAULT 0,
`score_data` JSON NULL,
`gender` VARCHAR(20) NULL DEFAULT 'masculino',
`status` VARCHAR(30) NOT NULL DEFAULT 'agendada',
`starts_at` DATETIME(6) NULL,
`place` VARCHAR(160) NULL,
`winner_team_id` INT NULL,
`sort_order` INT NOT NULL DEFAULT 0,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
`home_player_id` INT NULL,
`away_player_id` INT NULL,
`winner_player_id` INT NULL,
`home_source_match_id` INT NULL,
`away_source_match_id` INT NULL,
`rules_snapshot` JSON NULL,
`version` INT NOT NULL DEFAULT 0,
`cancel_reason` VARCHAR(240) NULL,
`cancelled_by` INT NULL,
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `score_logs` (`id` INT NOT NULL AUTO_INCREMENT,
`match_id` INT NOT NULL,
`user_id` INT NULL,
`action` VARCHAR(120) NOT NULL,
`description` VARCHAR(255) NULL,
`old_home_score` INT NULL,
`old_away_score` INT NULL,
`new_home_score` INT NULL,
`new_away_score` INT NULL,
`old_status` VARCHAR(40) NULL,
`new_status` VARCHAR(40) NULL,
`old_score_data` JSON NULL,
`new_score_data` JSON NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
`request_id` VARCHAR(64) NULL,
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `occurrences` (`id` INT NOT NULL AUTO_INCREMENT,
`event_id` INT NOT NULL,
`match_id` INT NOT NULL,
`team_id` INT NOT NULL,
`player_id` INT NULL,
`target_type` VARCHAR(20) NOT NULL,
`title` VARCHAR(160) NOT NULL,
`description` LONGTEXT NOT NULL,
`action_text` LONGTEXT NULL,
`ineligible_next_game` SMALLINT NOT NULL DEFAULT 0,
`points_deducted` INT NOT NULL DEFAULT 0,
`created_by` INT NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `suggestions` (`id` INT NOT NULL AUTO_INCREMENT,
`user_id` INT NULL,
`type` VARCHAR(20) NOT NULL DEFAULT 'melhoria',
`title` VARCHAR(160) NOT NULL,
`description` LONGTEXT NOT NULL,
`status` VARCHAR(30) NOT NULL DEFAULT 'nova',
`admin_note` LONGTEXT NULL,
`created_at` DATETIME(6) NULL DEFAULT CURRENT_TIMESTAMP(6),
`updated_at` DATETIME(6) NULL ON UPDATE CURRENT_TIMESTAMP(6),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `class_standings` (`id` INT NOT NULL AUTO_INCREMENT,
`event_id` INT NOT NULL,
`class_name` VARCHAR(120) NOT NULL,
`total_points` INT NOT NULL DEFAULT 0,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
`updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
UNIQUE (event_id, class_name),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `class_modality_points` (`id` INT NOT NULL AUTO_INCREMENT,
`class_standings_id` INT NOT NULL,
`modality` VARCHAR(40) NOT NULL,
`gender` VARCHAR(20) NOT NULL,
`position` INT NOT NULL,
`points` INT NOT NULL,
`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
UNIQUE (class_standings_id, modality, gender),
PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL;

CREATE INDEX idx_users_class_id ON users (class_id);

ALTER TABLE `user_modalities` ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

CREATE INDEX idx_user_modalities_user ON user_modalities (user_id);

CREATE INDEX idx_user_modalities_modality ON user_modalities (modality);

ALTER TABLE `events` ADD FOREIGN KEY (created_by) REFERENCES users(id);

CREATE INDEX idx_events_created_by ON events (created_by);

CREATE INDEX idx_events_status_start ON events (status, start_date);

CREATE INDEX idx_events_archived ON events (archived);

ALTER TABLE `event_modalities` ADD FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

ALTER TABLE `event_group_advancement` ADD FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

CREATE INDEX idx_event_group_advancement_event ON event_group_advancement (event_id);

ALTER TABLE `teams` ADD FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

ALTER TABLE `teams` ADD FOREIGN KEY (representative_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_teams_event_modality ON teams (event_id, modality, group_name);

CREATE INDEX idx_teams_gender ON teams (gender);

CREATE INDEX idx_teams_representative_id ON teams (representative_id);

ALTER TABLE `players` ADD FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE;

ALTER TABLE `players` ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_players_team_id ON players (team_id);

CREATE INDEX idx_players_user_id ON players (user_id);

CREATE INDEX idx_players_email ON players (email);

ALTER TABLE `matches` ADD FOREIGN KEY (away_player_id) REFERENCES players(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (away_source_match_id) REFERENCES matches(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (away_team_id) REFERENCES teams(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

ALTER TABLE `matches` ADD FOREIGN KEY (home_player_id) REFERENCES players(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (home_source_match_id) REFERENCES matches(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (home_team_id) REFERENCES teams(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (winner_player_id) REFERENCES players(id) ON DELETE SET NULL;

ALTER TABLE `matches` ADD FOREIGN KEY (winner_team_id) REFERENCES teams(id) ON DELETE SET NULL;

CREATE INDEX idx_matches_public ON matches (event_id, modality, starts_at, status);

CREATE INDEX idx_matches_gender_stage ON matches (event_id, modality, gender, stage);

CREATE INDEX idx_matches_home_team_id ON matches (home_team_id);

CREATE INDEX idx_matches_away_team_id ON matches (away_team_id);

CREATE INDEX idx_matches_winner_team_id ON matches (winner_team_id);

CREATE INDEX idx_matches_player_home ON matches (home_player_id);

CREATE INDEX idx_matches_player_away ON matches (away_player_id);

CREATE INDEX idx_matches_source_home ON matches (home_source_match_id);

CREATE INDEX idx_matches_source_away ON matches (away_source_match_id);

CREATE INDEX idx_matches_event_filters ON matches (event_id, modality, gender, stage, status, starts_at, id);

ALTER TABLE `score_logs` ADD FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE;

ALTER TABLE `score_logs` ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_score_logs_match_id ON score_logs (match_id);

CREATE INDEX idx_score_logs_user_id ON score_logs (user_id);

CREATE INDEX idx_score_logs_created_at ON score_logs (created_at);

ALTER TABLE `occurrences` ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE `occurrences` ADD FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

ALTER TABLE `occurrences` ADD FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE;

ALTER TABLE `occurrences` ADD FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL;

ALTER TABLE `occurrences` ADD FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE;

CREATE INDEX idx_occurrences_event ON occurrences (event_id);

CREATE INDEX idx_occurrences_match ON occurrences (match_id);

CREATE INDEX idx_occurrences_team ON occurrences (team_id);

CREATE INDEX idx_occurrences_player ON occurrences (player_id);

CREATE INDEX idx_occurrences_created_by ON occurrences (created_by);

ALTER TABLE `suggestions` ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_suggestions_status ON suggestions (status);

CREATE INDEX idx_suggestions_user_id ON suggestions (user_id);

ALTER TABLE `class_standings` ADD FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE;

ALTER TABLE `class_modality_points` ADD FOREIGN KEY (class_standings_id) REFERENCES class_standings(id) ON DELETE CASCADE;
CREATE UNIQUE INDEX uniq_classes_name ON classes (name);
CREATE UNIQUE INDEX uniq_users_email ON users (email);
CREATE UNIQUE INDEX uniq_score_logs_request ON score_logs (match_id, request_id);