-- PostgreSQL 13+

CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin', 'member', 'viewer')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS projects (
  id BIGSERIAL PRIMARY KEY,
  name TEXT NOT NULL,
  description TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_suites (
  id BIGSERIAL PRIMARY KEY,
  project_id BIGINT NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_sections (
  id BIGSERIAL PRIMARY KEY,
  suite_id BIGINT NOT NULL REFERENCES test_suites (id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  precondition TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_cases (
  id BIGSERIAL PRIMARY KEY,
  suite_id BIGINT NOT NULL REFERENCES test_suites (id) ON DELETE CASCADE,
  section_id BIGINT NOT NULL REFERENCES test_sections (id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  precondition TEXT,
  priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'critical')),
  status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'ready', 'deprecated')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_case_steps (
  id BIGSERIAL PRIMARY KEY,
  test_case_id BIGINT NOT NULL REFERENCES test_cases (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS test_case_step_variants (
  id BIGSERIAL PRIMARY KEY,
  step_id BIGINT NOT NULL REFERENCES test_case_steps (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS test_runs (
  id BIGSERIAL PRIMARY KEY,
  project_id BIGINT NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
  suite_id BIGINT REFERENCES test_suites (id) ON DELETE SET NULL,
  section_id BIGINT REFERENCES test_sections (id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  run_kind TEXT NOT NULL DEFAULT 'full_suite' CHECK (run_kind IN ('full_suite', 'section', 'run_book')),
  state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open', 'locked', 'archived')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_run_items (
  id BIGSERIAL PRIMARY KEY,
  run_id BIGINT NOT NULL REFERENCES test_runs (id) ON DELETE CASCADE,
  case_id BIGINT NOT NULL REFERENCES test_cases (id) ON DELETE CASCADE,
  result TEXT NOT NULL DEFAULT 'untested' CHECK (result IN ('untested', 'pass', 'fail', 'blocked', 'skipped')),
  severity TEXT NOT NULL DEFAULT 'unclear' CHECK (severity IN ('breaking', 'ui_only', 'unclear')),
  notes TEXT,
  screenshots_json TEXT NOT NULL DEFAULT '[]',
  video_url TEXT,
  executed_at TIMESTAMPTZ,
  UNIQUE (run_id, case_id)
);

CREATE TABLE IF NOT EXISTS test_case_versions (
  id BIGSERIAL PRIMARY KEY,
  case_id BIGINT NOT NULL REFERENCES test_cases (id) ON DELETE CASCADE,
  suite_id BIGINT NOT NULL REFERENCES test_suites (id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  precondition TEXT,
  priority TEXT NOT NULL,
  status TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_case_version_steps (
  id BIGSERIAL PRIMARY KEY,
  version_id BIGINT NOT NULL REFERENCES test_case_versions (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS test_case_version_step_variants (
  id BIGSERIAL PRIMARY KEY,
  version_step_id BIGINT NOT NULL REFERENCES test_case_version_steps (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_case_versions_case ON test_case_versions (case_id);
CREATE INDEX IF NOT EXISTS idx_test_case_steps_case ON test_case_steps (test_case_id);
CREATE INDEX IF NOT EXISTS idx_test_case_step_variants_step ON test_case_step_variants (step_id);
CREATE INDEX IF NOT EXISTS idx_test_case_version_steps_version ON test_case_version_steps (version_id);
CREATE INDEX IF NOT EXISTS idx_test_case_version_step_variants_step ON test_case_version_step_variants (version_step_id);
CREATE INDEX IF NOT EXISTS idx_cases_suite ON test_cases (suite_id);
CREATE INDEX IF NOT EXISTS idx_cases_section ON test_cases (section_id);
CREATE INDEX IF NOT EXISTS idx_sections_suite ON test_sections (suite_id);
CREATE INDEX IF NOT EXISTS idx_runs_project ON test_runs (project_id);
CREATE INDEX IF NOT EXISTS idx_run_items_run ON test_run_items (run_id);
