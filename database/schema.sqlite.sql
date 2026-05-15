-- SQLite schema: projects → suites → cases → runs → results
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  display_name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
  preferences TEXT NOT NULL DEFAULT '{}',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS project_members (
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK (role IN ('member', 'tester', 'viewer')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (project_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_project_members_user ON project_members(user_id);

CREATE TABLE IF NOT EXISTS test_suites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS test_sections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  suite_id INTEGER NOT NULL REFERENCES test_suites(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  precondition TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS test_cases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  suite_id INTEGER NOT NULL REFERENCES test_suites(id) ON DELETE CASCADE,
  section_id INTEGER NOT NULL REFERENCES test_sections(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  precondition TEXT,
  priority TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'critical')),
  status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'ready', 'deprecated')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS test_case_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  test_case_id INTEGER NOT NULL REFERENCES test_cases(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS test_case_step_variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  step_id INTEGER NOT NULL REFERENCES test_case_steps(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_test_case_steps_case ON test_case_steps(test_case_id);
CREATE INDEX IF NOT EXISTS idx_test_case_step_variants_step ON test_case_step_variants(step_id);

CREATE TABLE IF NOT EXISTS test_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  suite_id INTEGER REFERENCES test_suites(id) ON DELETE SET NULL,
  section_id INTEGER REFERENCES test_sections(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  run_kind TEXT NOT NULL DEFAULT 'full_suite' CHECK (run_kind IN ('full_suite', 'section', 'run_book')),
  state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open', 'locked', 'archived')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS test_run_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  run_id INTEGER NOT NULL REFERENCES test_runs(id) ON DELETE CASCADE,
  case_id INTEGER NOT NULL REFERENCES test_cases(id) ON DELETE CASCADE,
  result TEXT NOT NULL DEFAULT 'untested' CHECK (result IN ('untested', 'pass', 'fail', 'blocked', 'skipped')),
  severity TEXT NOT NULL DEFAULT 'unclear' CHECK (severity IN ('breaking', 'ui_only', 'unclear')),
  notes TEXT,
  screenshots_json TEXT NOT NULL DEFAULT '[]',
  video_url TEXT,
  executed_at TEXT,
  UNIQUE(run_id, case_id)
);

CREATE TABLE IF NOT EXISTS test_case_versions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id INTEGER NOT NULL REFERENCES test_cases(id) ON DELETE CASCADE,
  suite_id INTEGER NOT NULL REFERENCES test_suites(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  precondition TEXT,
  priority TEXT NOT NULL,
  status TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS test_case_version_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version_id INTEGER NOT NULL REFERENCES test_case_versions(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS test_case_version_step_variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version_step_id INTEGER NOT NULL REFERENCES test_case_version_steps(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_test_case_version_steps_version ON test_case_version_steps(version_id);
CREATE INDEX IF NOT EXISTS idx_test_case_version_step_variants_step ON test_case_version_step_variants(version_step_id);

CREATE INDEX IF NOT EXISTS idx_suites_project ON test_suites(project_id);
CREATE INDEX IF NOT EXISTS idx_sections_suite ON test_sections(suite_id);
CREATE INDEX IF NOT EXISTS idx_cases_suite ON test_cases(suite_id);
CREATE INDEX IF NOT EXISTS idx_runs_project ON test_runs(project_id);
CREATE INDEX IF NOT EXISTS idx_run_items_run ON test_run_items(run_id);
CREATE INDEX IF NOT EXISTS idx_case_versions_case ON test_case_versions(case_id);
