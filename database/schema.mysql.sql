-- MySQL 8.0+ (InnoDB, utf8mb4). Requires CHECK support (8.0.16+).
-- Indexes are declared on tables so PDO can run one statement at a time.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  role VARCHAR(32) NOT NULL DEFAULT 'user',
  preferences TEXT NOT NULL DEFAULT ('{}'),
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email),
  CONSTRAINT users_role_chk CHECK (role IN ('admin', 'user'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(500) NOT NULL,
  description TEXT,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_members (
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) NOT NULL,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id),
  KEY idx_project_members_user (user_id),
  CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT project_members_role_chk CHECK (role IN ('member', 'tester', 'viewer'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_suites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(500) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_suites_project (project_id),
  CONSTRAINT fk_test_suites_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  suite_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(500) NOT NULL,
  precondition TEXT,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sections_suite (suite_id),
  CONSTRAINT fk_test_sections_suite FOREIGN KEY (suite_id) REFERENCES test_suites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_cases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  suite_id BIGINT UNSIGNED NOT NULL,
  section_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(1000) NOT NULL,
  precondition TEXT,
  priority VARCHAR(32) NOT NULL DEFAULT 'medium',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cases_suite (suite_id),
  KEY idx_cases_section (section_id),
  CONSTRAINT fk_test_cases_suite FOREIGN KEY (suite_id) REFERENCES test_suites (id) ON DELETE CASCADE,
  CONSTRAINT fk_test_cases_section FOREIGN KEY (section_id) REFERENCES test_sections (id) ON DELETE CASCADE,
  CONSTRAINT test_cases_priority_chk CHECK (priority IN ('low', 'medium', 'high', 'critical')),
  CONSTRAINT test_cases_status_chk CHECK (status IN ('draft', 'ready', 'deprecated'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_case_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_case_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_steps_case (test_case_id),
  CONSTRAINT fk_test_case_steps_case FOREIGN KEY (test_case_id) REFERENCES test_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_case_step_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  step_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  label VARCHAR(500) DEFAULT NULL,
  criteria TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_step_variants_step (step_id),
  CONSTRAINT fk_test_case_step_variants_step FOREIGN KEY (step_id) REFERENCES test_case_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  suite_id BIGINT UNSIGNED DEFAULT NULL,
  section_id BIGINT UNSIGNED DEFAULT NULL,
  created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
  assigned_to_user_id BIGINT UNSIGNED DEFAULT NULL,
  name VARCHAR(500) NOT NULL,
  run_kind VARCHAR(32) NOT NULL DEFAULT 'full_suite',
  state VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_runs_project (project_id),
  KEY idx_runs_suite (suite_id),
  KEY idx_runs_section (section_id),
  KEY idx_runs_created_by (created_by_user_id),
  KEY idx_runs_assigned_to (assigned_to_user_id),
  CONSTRAINT fk_test_runs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_test_runs_suite FOREIGN KEY (suite_id) REFERENCES test_suites (id) ON DELETE SET NULL,
  CONSTRAINT fk_test_runs_section FOREIGN KEY (section_id) REFERENCES test_sections (id) ON DELETE SET NULL,
  CONSTRAINT fk_test_runs_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_test_runs_assigned_to FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT test_runs_state_chk CHECK (state IN ('open', 'complete', 'locked', 'archived')),
  CONSTRAINT test_runs_run_kind_chk CHECK (run_kind IN ('full_suite', 'section', 'run_book'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_run_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  case_id BIGINT UNSIGNED NOT NULL,
  result VARCHAR(32) NOT NULL DEFAULT 'untested',
  severity VARCHAR(32) NOT NULL DEFAULT 'unclear',
  notes TEXT,
  screenshots_json TEXT NOT NULL DEFAULT '[]',
  video_url TEXT,
  executed_at DATETIME(0) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_run_case (run_id, case_id),
  KEY idx_run_items_run (run_id),
  CONSTRAINT fk_run_items_run FOREIGN KEY (run_id) REFERENCES test_runs (id) ON DELETE CASCADE,
  CONSTRAINT fk_run_items_case FOREIGN KEY (case_id) REFERENCES test_cases (id) ON DELETE CASCADE,
  CONSTRAINT test_run_items_result_chk CHECK (result IN ('untested', 'pass', 'fail', 'blocked', 'skipped')),
  CONSTRAINT test_run_items_severity_chk CHECK (severity IN ('breaking', 'ui_only', 'unclear'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_case_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  case_id BIGINT UNSIGNED NOT NULL,
  suite_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(1000) NOT NULL,
  precondition TEXT,
  priority VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_case_versions_case (case_id),
  CONSTRAINT fk_case_versions_case FOREIGN KEY (case_id) REFERENCES test_cases (id) ON DELETE CASCADE,
  CONSTRAINT fk_case_versions_suite FOREIGN KEY (suite_id) REFERENCES test_suites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_case_version_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_version_steps_version (version_id),
  CONSTRAINT fk_test_case_version_steps_version FOREIGN KEY (version_id) REFERENCES test_case_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS test_case_version_step_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_step_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  label VARCHAR(500) DEFAULT NULL,
  criteria TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_version_step_variants_step (version_step_id),
  CONSTRAINT fk_test_case_version_step_variants_step FOREIGN KEY (version_step_id) REFERENCES test_case_version_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
