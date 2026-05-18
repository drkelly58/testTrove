<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Database
{
    /** @return 'sqlite'|'mysql'|'pgsql' */
    public static function normalizeDriver(string $raw): string
    {
        $d = strtolower(trim($raw));
        if ($d === '' || $d === 'sqlite') {
            return 'sqlite';
        }
        if ($d === 'mysql' || $d === 'mariadb') {
            return 'mysql';
        }
        if ($d === 'pgsql' || $d === 'postgres' || $d === 'postgresql') {
            return 'pgsql';
        }
        throw new \InvalidArgumentException(
            'Unsupported DB_DRIVER "' . $raw . '". Use sqlite, mysql, or pgsql.'
        );
    }

    public static function fromEnv(string $root): PDO
    {
        $driver = self::normalizeDriver($_ENV['DB_DRIVER'] ?? 'sqlite');

        return match ($driver) {
            'sqlite' => self::pdoSqlite(self::sqlitePath($root)),
            'mysql' => self::pdoMysql(
                $_ENV['DB_HOST'] ?? '127.0.0.1',
                (int) ($_ENV['DB_PORT'] ?? 3306),
                $_ENV['DB_DATABASE'] ?? 'testtrove',
                $_ENV['DB_USERNAME'] ?? 'root',
                $_ENV['DB_PASSWORD'] ?? '',
                $_ENV['DB_SOCKET'] ?? null,
            ),
            'pgsql' => self::pdoPgsql(
                $_ENV['DB_HOST'] ?? '127.0.0.1',
                (int) ($_ENV['DB_PORT'] ?? 5432),
                $_ENV['DB_DATABASE'] ?? 'testtrove',
                $_ENV['DB_USERNAME'] ?? 'postgres',
                $_ENV['DB_PASSWORD'] ?? '',
            ),
        };
    }

    /** Absolute path to SQLite file; relative DB_PATH is resolved from project root. */
    private static function sqlitePath(string $root): string
    {
        $raw = isset($_ENV['DB_PATH']) ? trim((string) $_ENV['DB_PATH']) : '';
        if ($raw === '') {
            return $root . '/storage/app.sqlite';
        }
        if ($raw[0] === '/') {
            return $raw;
        }
        return $root . '/' . ltrim($raw, '/');
    }

    /** Absolute SQLite database file path (for messages and permission checks). */
    public static function sqlitePathFromEnv(string $root): string
    {
        return self::sqlitePath($root);
    }

    /**
     * Fail fast when SQLite cannot persist writes (avoids obscure errors on INSERT).
     *
     * @throws \RuntimeException
     */
    public static function assertSqliteIsWritable(string $dbFilePath): void
    {
        $dir = dirname($dbFilePath);
        if (!is_dir($dir)) {
            throw new \RuntimeException('SQLite database directory does not exist: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(
                'SQLite directory is not writable: ' . $dir . '. '
                . 'The web server user must be able to create and update the database file and sidecar files (-wal, -shm, -journal). '
                . 'Typical fix: chown/chmod that directory (and the .sqlite file if it already exists), or set DB_PATH in .env to a writable path.'
            );
        }
        if (is_file($dbFilePath) && !is_writable($dbFilePath)) {
            throw new \RuntimeException(
                'SQLite database file is not writable: ' . $dbFilePath . '. '
                . 'Adjust ownership/permissions so the PHP process can write to this file.'
            );
        }
    }

    public static function schemaPath(string $root, string $driver): string
    {
        $file = match ($driver) {
            'sqlite' => 'schema.sqlite.sql',
            'mysql' => 'schema.mysql.sql',
            'pgsql' => 'schema.pgsql.sql',
        };
        return $root . '/database/' . $file;
    }

    public static function migrate(PDO $pdo, string $driver, string $root): void
    {
        $path = self::schemaPath($root, $driver);
        if (!is_readable($path)) {
            throw new \RuntimeException('Schema file not readable: ' . $path);
        }
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('Schema file empty: ' . $path);
        }

        if ($driver === 'sqlite') {
            $pdo->exec($sql);
            self::sqliteAdditiveMigrations($pdo);
            self::sectionAdditiveMigrations($pdo, $driver);
            self::testCaseSortOrderMigrations($pdo, $driver);
            self::runItemSeverityMigrations($pdo, $driver);
            self::runItemScreenshotsMigrations($pdo, $driver);
            self::testCaseStepsRelationalMigrations($pdo, $driver);
            self::oauthIdentityMigrations($pdo, $driver);
            self::userPreferencesMigrations($pdo, $driver);
            self::usersMustChangePasswordMigrations($pdo, $driver);
            self::projectMembersMigrations($pdo, $driver);
            self::testRunsCreatedByMigrations($pdo, $driver);
            self::testRunsAssignedToMigrations($pdo, $driver);
            self::testRunsCompleteStateMigrations($pdo, $driver);
            \App\Services\InstanceSettingsService::runMigration($pdo, $driver);

            return;
        }

        self::execScriptStatements($pdo, $sql);
        self::sectionAdditiveMigrations($pdo, $driver);
        self::testCaseSortOrderMigrations($pdo, $driver);
        self::testRunsSectionAndRunKindMigrations($pdo, $driver);
        self::runItemSeverityMigrations($pdo, $driver);
        self::runItemScreenshotsMigrations($pdo, $driver);
        self::testCaseStepsRelationalMigrations($pdo, $driver);
        self::oauthIdentityMigrations($pdo, $driver);
        self::userPreferencesMigrations($pdo, $driver);
        self::usersMustChangePasswordMigrations($pdo, $driver);
        self::projectMembersMigrations($pdo, $driver);
        self::testRunsCreatedByMigrations($pdo, $driver);
        self::testRunsAssignedToMigrations($pdo, $driver);
        self::testRunsCompleteStateMigrations($pdo, $driver);
        \App\Services\InstanceSettingsService::runMigration($pdo, $driver);
    }

    /** Nullable owner for tester-scoped run visibility. */
    private static function testRunsCreatedByMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'test_runs') || !self::tableExists($pdo, $driver, 'users')) {
            return;
        }
        if (self::columnExists($pdo, $driver, 'test_runs', 'created_by_user_id')) {
            return;
        }

        $ignore = ['already exists', 'duplicate', 'duplicate column'];
        if ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD COLUMN created_by_user_id BIGINT UNSIGNED NULL AFTER section_id',
                $ignore
            );
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD CONSTRAINT fk_test_runs_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL',
                $ignore
            );
            self::safeExec($pdo, 'CREATE INDEX idx_runs_created_by ON test_runs (created_by_user_id)', $ignore);

            return;
        }
        if ($driver === 'pgsql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD COLUMN IF NOT EXISTS created_by_user_id BIGINT REFERENCES users (id) ON DELETE SET NULL',
                $ignore
            );
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_runs_created_by ON test_runs (created_by_user_id)', $ignore);

            return;
        }

        self::safeExec(
            $pdo,
            'ALTER TABLE test_runs ADD COLUMN created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL',
            $ignore
        );
        self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_runs_created_by ON test_runs(created_by_user_id)', $ignore);
    }

    /** Nullable assignee so members can delegate runs to testers. */
    private static function testRunsAssignedToMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'test_runs') || !self::tableExists($pdo, $driver, 'users')) {
            return;
        }
        if (self::columnExists($pdo, $driver, 'test_runs', 'assigned_to_user_id')) {
            return;
        }

        $ignore = ['already exists', 'duplicate', 'duplicate column'];
        if ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD COLUMN assigned_to_user_id BIGINT UNSIGNED NULL AFTER created_by_user_id',
                $ignore
            );
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD CONSTRAINT fk_test_runs_assigned_to FOREIGN KEY (assigned_to_user_id) REFERENCES users (id) ON DELETE SET NULL',
                $ignore
            );
            self::safeExec($pdo, 'CREATE INDEX idx_runs_assigned_to ON test_runs (assigned_to_user_id)', $ignore);

            return;
        }
        if ($driver === 'pgsql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD COLUMN IF NOT EXISTS assigned_to_user_id BIGINT REFERENCES users (id) ON DELETE SET NULL',
                $ignore
            );
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_runs_assigned_to ON test_runs (assigned_to_user_id)', $ignore);

            return;
        }

        self::safeExec(
            $pdo,
            'ALTER TABLE test_runs ADD COLUMN assigned_to_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL',
            $ignore
        );
        self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_runs_assigned_to ON test_runs(assigned_to_user_id)', $ignore);
    }

    /** Allow run state `complete` (auto-set when every item is pass or fail). */
    private static function testRunsCompleteStateMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'test_runs')) {
            return;
        }
        if ($driver === 'sqlite') {
            self::sqliteRebuildTestRunsIfStateCompleteDisallowed($pdo);
        } elseif ($driver === 'mysql') {
            self::mysqlUpgradeTestRunsStateCheck($pdo);
        } elseif ($driver === 'pgsql') {
            self::pgsqlUpgradeTestRunsStateCheck($pdo);
        }
        self::backfillAutoCompletedRuns($pdo);
    }

    private static function backfillAutoCompletedRuns(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE test_runs SET state = 'complete'
             WHERE state = 'open'
               AND EXISTS (SELECT 1 FROM test_run_items i WHERE i.run_id = test_runs.id)
               AND NOT EXISTS (
                 SELECT 1 FROM test_run_items i
                 WHERE i.run_id = test_runs.id AND i.result NOT IN ('pass', 'fail')
               )"
        );
    }

    private static function sqliteTestRunsAcceptsStateComplete(PDO $pdo): bool
    {
        $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'test_runs'")->fetchColumn();
        if (!is_string($sql)) {
            return true;
        }

        return str_contains($sql, "'complete'") && str_contains($sql, 'state');
    }

    private static function sqliteRebuildTestRunsIfStateCompleteDisallowed(PDO $pdo): void
    {
        if (self::sqliteTestRunsAcceptsStateComplete($pdo)) {
            return;
        }

        $hasCreatedBy = self::columnExists($pdo, 'sqlite', 'test_runs', 'created_by_user_id');
        $hasAssignedTo = self::columnExists($pdo, 'sqlite', 'test_runs', 'assigned_to_user_id');

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $createdByCol = $hasCreatedBy ? "created_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,\n  " : '';
            $assignedCol = $hasAssignedTo ? "assigned_to_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,\n  " : '';
            $pdo->exec(
                "CREATE TABLE test_runs_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  suite_id INTEGER REFERENCES test_suites(id) ON DELETE SET NULL,
  section_id INTEGER REFERENCES test_sections(id) ON DELETE SET NULL,
  {$createdByCol}{$assignedCol}name TEXT NOT NULL,
  run_kind TEXT NOT NULL DEFAULT 'full_suite' CHECK (run_kind IN ('full_suite', 'section', 'run_book')),
  state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open', 'complete', 'locked', 'archived')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
)"
            );

            $selectCols = 'id, project_id, suite_id, section_id';
            if ($hasCreatedBy) {
                $selectCols .= ', created_by_user_id';
            }
            if ($hasAssignedTo) {
                $selectCols .= ', assigned_to_user_id';
            }
            $selectCols .= ', name, run_kind, state, created_at';

            $pdo->exec(
                "INSERT INTO test_runs_new ({$selectCols}) SELECT {$selectCols} FROM test_runs"
            );
            $pdo->exec('DROP TABLE test_runs');
            $pdo->exec('ALTER TABLE test_runs_new RENAME TO test_runs');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_project ON test_runs(project_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_suite ON test_runs(suite_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_section ON test_runs(section_id)');
            if ($hasCreatedBy) {
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_created_by ON test_runs(created_by_user_id)');
            }
            if ($hasAssignedTo) {
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_assigned_to ON test_runs(assigned_to_user_id)');
            }
            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM test_runs')->fetchColumn();
            if ($maxId > 0) {
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'test_runs'");
                $pdo->exec('INSERT INTO sqlite_sequence (name, seq) VALUES (\'test_runs\', ' . $maxId . ')');
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    private static function mysqlUpgradeTestRunsStateCheck(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT cc.constraint_name
             FROM information_schema.check_constraints cc
             INNER JOIN information_schema.table_constraints tc
               ON tc.constraint_schema = cc.constraint_schema
              AND tc.constraint_name = cc.constraint_name
             WHERE tc.table_schema = DATABASE()
               AND tc.table_name = 'test_runs'
               AND tc.constraint_type = 'CHECK'
               AND cc.check_clause LIKE '%state%'
               AND cc.check_clause NOT LIKE '%complete%'"
        );
        if ($stmt === false) {
            return;
        }
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $ident = '`' . str_replace('`', '``', $name) . '`';
            self::safeExec(
                $pdo,
                "ALTER TABLE test_runs DROP CHECK {$ident}",
                ['check', "doesn't exist", 'unknown', 'failed', 'exist'],
            );
        }
        self::safeExec(
            $pdo,
            "ALTER TABLE test_runs ADD CONSTRAINT test_runs_state_chk CHECK (state IN ('open', 'complete', 'locked', 'archived'))",
            ['duplicate', 'already exists'],
        );
    }

    private static function pgsqlUpgradeTestRunsStateCheck(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT c.conname, pg_get_constraintdef(c.oid) AS def
             FROM pg_constraint c
             INNER JOIN pg_class rel ON rel.oid = c.conrelid
             INNER JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             WHERE rel.relname = 'test_runs'
               AND nsp.nspname = CURRENT_SCHEMA()
               AND c.contype = 'c'"
        );
        if ($stmt === false) {
            return;
        }
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $def = strtolower((string) ($row['def'] ?? ''));
            if (!str_contains($def, 'state')) {
                continue;
            }
            if (str_contains($def, 'complete')) {
                continue;
            }
            $name = (string) ($row['conname'] ?? '');
            if ($name === '') {
                continue;
            }
            $ident = '"' . str_replace('"', '""', $name) . '"';
            self::safeExec($pdo, "ALTER TABLE test_runs DROP CONSTRAINT {$ident}", ['does not exist', 'undefined']);
        }
        self::safeExec(
            $pdo,
            "ALTER TABLE test_runs ADD CONSTRAINT test_runs_state_chk CHECK (state IN ('open', 'complete', 'locked', 'archived'))",
            ['already exists', 'duplicate'],
        );
    }

    /**
     * project_members table, users.role admin|user, backfill memberships from legacy global roles.
     */
    private static function projectMembersMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'projects') || !self::tableExists($pdo, $driver, 'users')) {
            return;
        }

        foreach (self::projectMembersCreateStatements($driver) as $stmt) {
            self::safeExec($pdo, $stmt, ['already exists', 'duplicate']);
        }

        if (!self::tableExists($pdo, $driver, 'project_members')) {
            return;
        }

        self::backfillProjectMembersFromLegacyUserRoles($pdo);
        self::userGlobalRoleMigrations($pdo, $driver);
    }

    /**
     * @return list<string>
     */
    private static function projectMembersCreateStatements(string $driver): array
    {
        return match ($driver) {
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS project_members (
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(32) NOT NULL,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id),
  KEY idx_project_members_user (user_id),
  CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT project_members_role_chk CHECK (role IN ('member', 'tester', 'viewer'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            'pgsql' => [
                "CREATE TABLE IF NOT EXISTS project_members (
  project_id BIGINT NOT NULL REFERENCES projects (id) ON DELETE CASCADE,
  user_id BIGINT NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK (role IN ('member', 'tester', 'viewer')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id)
)",
                'CREATE INDEX IF NOT EXISTS idx_project_members_user ON project_members (user_id)',
            ],
            default => [
                "CREATE TABLE IF NOT EXISTS project_members (
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL CHECK (role IN ('member', 'tester', 'viewer')),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (project_id, user_id)
)",
                'CREATE INDEX IF NOT EXISTS idx_project_members_user ON project_members(user_id)',
            ],
        };
    }

    private static function backfillProjectMembersFromLegacyUserRoles(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM project_members')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $pdo->exec(
            "INSERT INTO project_members (project_id, user_id, role)
             SELECT p.id, u.id,
               CASE WHEN u.role = 'viewer' THEN 'viewer' ELSE 'member' END
             FROM projects p
             CROSS JOIN users u
             WHERE u.role IS NOT NULL AND u.role <> 'admin'"
        );
    }

    private static function userGlobalRoleMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'users')) {
            return;
        }

        self::safeExec(
            $pdo,
            "UPDATE users SET role = 'user' WHERE role IN ('member', 'viewer', 'tester')",
            [],
        );

        if ($driver === 'sqlite') {
            self::sqliteRebuildUsersIfUserRoleDisallowed($pdo);

            return;
        }
        if ($driver === 'mysql') {
            self::mysqlUpgradeUsersRoleCheck($pdo);

            return;
        }
        if ($driver === 'pgsql') {
            self::pgsqlUpgradeUsersRoleCheck($pdo);
        }
    }

    private static function sqliteRebuildUsersIfUserRoleDisallowed(PDO $pdo): void
    {
        if (self::sqliteUsersAcceptsRoleUser($pdo)) {
            return;
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(
                "CREATE TABLE users_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT,
  display_name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
  preferences TEXT NOT NULL DEFAULT '{}',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  oauth_provider TEXT,
  oauth_subject TEXT,
  picture_url TEXT
)"
            );
            $cols = self::sqliteUsersColumnList($pdo);
            $select = 'id, email, password_hash, display_name, '
                . "CASE WHEN role = 'admin' THEN 'admin' ELSE 'user' END AS role, "
                . "COALESCE(preferences, '{}') AS preferences, created_at";
            if (in_array('oauth_provider', $cols, true)) {
                $select .= ', oauth_provider, oauth_subject, picture_url';
            } else {
                $select .= ', NULL, NULL, NULL';
            }
            $pdo->exec('INSERT INTO users_new (' . self::sqliteUsersInsertColumns($cols) . ") SELECT {$select} FROM users");
            $pdo->exec('DROP TABLE users');
            $pdo->exec('ALTER TABLE users_new RENAME TO users');
            $pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_oauth ON users(oauth_provider, oauth_subject)
                 WHERE oauth_provider IS NOT NULL AND oauth_subject IS NOT NULL'
            );
            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM users')->fetchColumn();
            if ($maxId > 0) {
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'users'");
                $pdo->exec("INSERT INTO sqlite_sequence (name, seq) VALUES ('users', {$maxId})");
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /** @return list<string> */
    private static function sqliteUsersColumnList(PDO $pdo): array
    {
        $st = $pdo->query('PRAGMA table_info(users)');
        if ($st === false) {
            return [];
        }
        $cols = [];
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $cols[] = (string) ($row['name'] ?? '');
        }

        return $cols;
    }

    /** @param list<string> $cols */
    private static function sqliteUsersInsertColumns(array $cols): string
    {
        $base = 'id, email, password_hash, display_name, role, preferences, created_at';
        if (in_array('oauth_provider', $cols, true)) {
            return $base . ', oauth_provider, oauth_subject, picture_url';
        }

        return $base;
    }

    private static function sqliteUsersAcceptsRoleUser(PDO $pdo): bool
    {
        try {
            $pdo->exec('SAVEPOINT sp_users_role_user');
            $stmt = $pdo->prepare(
                "INSERT INTO users (email, password_hash, display_name, role)
                 VALUES ('__tt_role_probe__@invalid.local', 'x', 'probe', 'user')"
            );
            $stmt->execute();
            $id = (int) $pdo->lastInsertId();
            $pdo->exec('ROLLBACK TO SAVEPOINT sp_users_role_user');
            $pdo->exec('RELEASE SAVEPOINT sp_users_role_user');
            if ($id > 0) {
                $pdo->exec('DELETE FROM users WHERE id = ' . $id);
            }

            return true;
        } catch (PDOException $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_users_role_user');
            } catch (PDOException) {
            }
            try {
                $pdo->exec('RELEASE SAVEPOINT sp_users_role_user');
            } catch (PDOException) {
            }
            $msg = strtolower($e->getMessage());

            return !str_contains($msg, 'check') && !str_contains($msg, 'constraint');
        }
    }

    private static function mysqlUpgradeUsersRoleCheck(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT cc.constraint_name
             FROM information_schema.check_constraints cc
             INNER JOIN information_schema.table_constraints tc
               ON tc.constraint_schema = cc.constraint_schema
              AND tc.constraint_name = cc.constraint_name
             WHERE tc.table_schema = DATABASE()
               AND tc.table_name = 'users'
               AND tc.constraint_type = 'CHECK'
               AND cc.check_clause LIKE '%role%'
               AND cc.check_clause NOT LIKE '%user%'"
        );
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $ident = '`' . str_replace('`', '``', $name) . '`';
                self::safeExec($pdo, "ALTER TABLE users DROP CHECK {$ident}", ['check', "doesn't exist", 'unknown']);
            }
        }
        self::safeExec(
            $pdo,
            "ALTER TABLE users ADD CONSTRAINT users_role_chk CHECK (role IN ('admin', 'user'))",
            ['duplicate', 'already exists'],
        );
    }

    private static function pgsqlUpgradeUsersRoleCheck(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT c.conname, pg_get_constraintdef(c.oid) AS def
             FROM pg_constraint c
             INNER JOIN pg_class rel ON rel.oid = c.conrelid
             INNER JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             WHERE rel.relname = 'users'
               AND nsp.nspname = CURRENT_SCHEMA()
               AND c.contype = 'c'"
        );
        if ($stmt !== false) {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $def = strtolower((string) ($row['def'] ?? ''));
                if (!str_contains($def, 'role')) {
                    continue;
                }
                if (str_contains($def, "'user'")) {
                    continue;
                }
                $name = (string) ($row['conname'] ?? '');
                if ($name === '') {
                    continue;
                }
                $ident = '"' . str_replace('"', '""', $name) . '"';
                self::safeExec($pdo, "ALTER TABLE users DROP CONSTRAINT {$ident}", ['does not exist']);
            }
        }
        self::safeExec(
            $pdo,
            "ALTER TABLE users ADD CONSTRAINT users_role_chk CHECK (role IN ('admin', 'user'))",
            ['already exists', 'duplicate'],
        );
    }

    /** JSON UI preferences on users (default project, run overview layout, etc.). */
    private static function userPreferencesMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'users')) {
            return;
        }
        if (self::columnExists($pdo, $driver, 'users', 'preferences')) {
            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec("ALTER TABLE users ADD COLUMN preferences TEXT NOT NULL DEFAULT '{}'");
        } elseif ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                "ALTER TABLE users ADD COLUMN preferences TEXT NOT NULL",
                ['duplicate column'],
            );
            self::safeExec(
                $pdo,
                "UPDATE users SET preferences = '{}' WHERE preferences IS NULL OR preferences = ''",
                [],
            );
        } elseif ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS preferences TEXT NOT NULL DEFAULT '{}'");
        }
    }

    /** Force password change on next local sign-in (invited / admin-reset accounts). */
    private static function usersMustChangePasswordMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'users')) {
            return;
        }
        if (self::columnExists($pdo, $driver, 'users', 'must_change_password')) {
            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec('ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0');
        } elseif ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0',
                ['duplicate column'],
            );
        } elseif ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE');
        }
    }

    /**
     * OAuth columns on users + optional nullable password_hash (MySQL / PostgreSQL).
     */
    private static function oauthIdentityMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'users')) {
            return;
        }

        if ($driver === 'sqlite') {
            try {
                $pdo->exec('ALTER TABLE users ADD COLUMN oauth_provider TEXT');
            } catch (PDOException $e) {
                $m = strtolower($e->getMessage());
                if (!str_contains($m, 'duplicate column')) {
                    throw $e;
                }
            }
            try {
                $pdo->exec('ALTER TABLE users ADD COLUMN oauth_subject TEXT');
            } catch (PDOException $e) {
                $m = strtolower($e->getMessage());
                if (!str_contains($m, 'duplicate column')) {
                    throw $e;
                }
            }
            try {
                $pdo->exec('ALTER TABLE users ADD COLUMN picture_url TEXT');
            } catch (PDOException $e) {
                $m = strtolower($e->getMessage());
                if (!str_contains($m, 'duplicate column')) {
                    throw $e;
                }
            }
            $pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_oauth ON users(oauth_provider, oauth_subject)
                 WHERE oauth_provider IS NOT NULL AND oauth_subject IS NOT NULL'
            );

            return;
        }

        if ($driver === 'mysql') {
            self::safeExec($pdo, 'ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(32) NULL', ['duplicate column']);
            self::safeExec($pdo, 'ALTER TABLE users ADD COLUMN oauth_subject VARCHAR(255) NULL', ['duplicate column']);
            self::safeExec($pdo, 'ALTER TABLE users ADD COLUMN picture_url TEXT NULL', ['duplicate column']);
            self::safeExec(
                $pdo,
                'ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL',
                ['duplicate', 'unknown column', 'check that column exists'],
            );
            self::safeExec(
                $pdo,
                'CREATE UNIQUE INDEX uniq_users_oauth ON users (oauth_provider, oauth_subject)',
                ['duplicate', 'already exists'],
            );

            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS oauth_provider VARCHAR(32)');
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS oauth_subject VARCHAR(255)');
            $pdo->exec('ALTER TABLE users ADD COLUMN IF NOT EXISTS picture_url TEXT');
            $pdo->exec('ALTER TABLE users ALTER COLUMN password_hash DROP NOT NULL');
            self::safeExec(
                $pdo,
                'CREATE UNIQUE INDEX uniq_users_oauth ON users (oauth_provider, oauth_subject)',
                ['already exists', 'duplicate'],
            );
        }
    }

    /**
     * Creates test_case_steps (+ variants) and per-version snapshot tables, migrates legacy steps_json, then drops steps_json.
     * Variants are normalized in test_case_step_variants / test_case_version_step_variants (see commit message).
     */
    private static function testCaseStepsRelationalMigrations(PDO $pdo, string $driver): void
    {
        foreach (self::testCaseStepTablesCreateStatements($driver) as $stmt) {
            self::safeExec($pdo, $stmt, ['already exists', 'duplicate']);
        }

        if (!self::tableExists($pdo, $driver, 'test_cases')) {
            return;
        }

        if (self::columnExists($pdo, $driver, 'test_cases', 'steps_json')) {
            self::migrateStepsJsonFromCases($pdo);
        }
        if (self::tableExists($pdo, $driver, 'test_case_versions')
            && self::columnExists($pdo, $driver, 'test_case_versions', 'steps_json')) {
            self::migrateStepsJsonFromCaseVersions($pdo);
        }

        if (self::columnExists($pdo, $driver, 'test_cases', 'steps_json')) {
            self::dropColumnSafe($pdo, $driver, 'test_cases', 'steps_json');
        }
        if (self::tableExists($pdo, $driver, 'test_case_versions')
            && self::columnExists($pdo, $driver, 'test_case_versions', 'steps_json')) {
            self::dropColumnSafe($pdo, $driver, 'test_case_versions', 'steps_json');
        }
    }

    /**
     * @return list<string>
     */
    private static function testCaseStepTablesCreateStatements(string $driver): array
    {
        return match ($driver) {
            'mysql' => [
                'CREATE TABLE IF NOT EXISTS test_case_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  test_case_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_steps_case (test_case_id),
  CONSTRAINT fk_test_case_steps_case FOREIGN KEY (test_case_id) REFERENCES test_cases (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'CREATE TABLE IF NOT EXISTS test_case_step_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  step_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  label VARCHAR(500) DEFAULT NULL,
  criteria TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_step_variants_step (step_id),
  CONSTRAINT fk_test_case_step_variants_step FOREIGN KEY (step_id) REFERENCES test_case_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'CREATE TABLE IF NOT EXISTS test_case_version_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_version_steps_version (version_id),
  CONSTRAINT fk_test_case_version_steps_version FOREIGN KEY (version_id) REFERENCES test_case_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                'CREATE TABLE IF NOT EXISTS test_case_version_step_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version_step_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL,
  label VARCHAR(500) DEFAULT NULL,
  criteria TEXT NOT NULL,
  PRIMARY KEY (id),
  KEY idx_test_case_version_step_variants_step (version_step_id),
  CONSTRAINT fk_test_case_version_step_variants_step FOREIGN KEY (version_step_id) REFERENCES test_case_version_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ],
            'pgsql' => [
                "CREATE TABLE IF NOT EXISTS test_case_steps (
  id BIGSERIAL PRIMARY KEY,
  test_case_id BIGINT NOT NULL REFERENCES test_cases (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
)",
                "CREATE TABLE IF NOT EXISTS test_case_step_variants (
  id BIGSERIAL PRIMARY KEY,
  step_id BIGINT NOT NULL REFERENCES test_case_steps (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
)",
                "CREATE TABLE IF NOT EXISTS test_case_version_steps (
  id BIGSERIAL PRIMARY KEY,
  version_id BIGINT NOT NULL REFERENCES test_case_versions (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
)",
                "CREATE TABLE IF NOT EXISTS test_case_version_step_variants (
  id BIGSERIAL PRIMARY KEY,
  version_step_id BIGINT NOT NULL REFERENCES test_case_version_steps (id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
)",
                'CREATE INDEX IF NOT EXISTS idx_test_case_steps_case ON test_case_steps (test_case_id)',
                'CREATE INDEX IF NOT EXISTS idx_test_case_step_variants_step ON test_case_step_variants (step_id)',
                'CREATE INDEX IF NOT EXISTS idx_test_case_version_steps_version ON test_case_version_steps (version_id)',
                'CREATE INDEX IF NOT EXISTS idx_test_case_version_step_variants_step ON test_case_version_step_variants (version_step_id)',
            ],
            default => [
                "CREATE TABLE IF NOT EXISTS test_case_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  test_case_id INTEGER NOT NULL REFERENCES test_cases(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
)",
                "CREATE TABLE IF NOT EXISTS test_case_step_variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  step_id INTEGER NOT NULL REFERENCES test_case_steps(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
)",
                'CREATE INDEX IF NOT EXISTS idx_test_case_steps_case ON test_case_steps(test_case_id)',
                'CREATE INDEX IF NOT EXISTS idx_test_case_step_variants_step ON test_case_step_variants(step_id)',
                "CREATE TABLE IF NOT EXISTS test_case_version_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version_id INTEGER NOT NULL REFERENCES test_case_versions(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  action TEXT NOT NULL,
  expected TEXT NOT NULL DEFAULT ''
)",
                "CREATE TABLE IF NOT EXISTS test_case_version_step_variants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version_step_id INTEGER NOT NULL REFERENCES test_case_version_steps(id) ON DELETE CASCADE,
  sort_order INTEGER NOT NULL,
  label TEXT,
  criteria TEXT NOT NULL
)",
                'CREATE INDEX IF NOT EXISTS idx_test_case_version_steps_version ON test_case_version_steps(version_id)',
                'CREATE INDEX IF NOT EXISTS idx_test_case_version_step_variants_step ON test_case_version_step_variants(version_step_id)',
            ],
        };
    }

    private static function tableExists(PDO $pdo, string $driver, string $table): bool
    {
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1");
            $st->execute(['t' => $table]);

            return (bool) $st->fetchColumn();
        }
        if ($driver === 'mysql') {
            $st = $pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1'
            );
            $st->execute(['t' => $table]);

            return (bool) $st->fetchColumn();
        }
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :t LIMIT 1'
        );
        $st->execute(['t' => $table]);

        return (bool) $st->fetchColumn();
    }

    private static function columnExists(PDO $pdo, string $driver, string $table, string $column): bool
    {
        if ($driver === 'sqlite') {
            $st = $pdo->query('PRAGMA table_info(' . str_replace(['"', "'", ';'], '', $table) . ')');
            if ($st === false) {
                return false;
            }
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (strtolower((string) ($row['name'] ?? '')) === strtolower($column)) {
                    return true;
                }
            }

            return false;
        }
        if ($driver === 'mysql') {
            $st = $pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1'
            );
            $st->execute(['t' => $table, 'c' => $column]);

            return (bool) $st->fetchColumn();
        }
        $st = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = :t AND column_name = :c LIMIT 1'
        );
        $st->execute(['t' => $table, 'c' => $column]);

        return (bool) $st->fetchColumn();
    }

    private static function migrateStepsJsonFromCases(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT id, steps_json FROM test_cases')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            return;
        }
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM test_case_steps WHERE test_case_id = :id');
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $cntStmt->execute(['id' => $id]);
            if ((int) $cntStmt->fetchColumn() > 0) {
                continue;
            }
            $decoded = json_decode((string) ($row['steps_json'] ?? ''), true);
            try {
                $steps = \App\Services\CaseExchangeService::normalizeStepsList($decoded ?? [], 'migrating case ' . $id);
            } catch (\InvalidArgumentException) {
                $steps = [];
            }
            \App\Services\TestCaseStepsService::replaceCaseSteps($pdo, $id, $steps);
        }
    }

    private static function migrateStepsJsonFromCaseVersions(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT id, steps_json FROM test_case_versions')->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === false) {
            return;
        }
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM test_case_version_steps WHERE version_id = :id');
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $cntStmt->execute(['id' => $id]);
            if ((int) $cntStmt->fetchColumn() > 0) {
                continue;
            }
            $decoded = json_decode((string) ($row['steps_json'] ?? ''), true);
            try {
                $steps = \App\Services\CaseExchangeService::normalizeStepsList($decoded ?? [], 'migrating case version ' . $id);
            } catch (\InvalidArgumentException) {
                $steps = [];
            }
            \App\Services\TestCaseStepsService::replaceVersionSteps($pdo, $id, $steps);
        }
    }

    private static function dropColumnSafe(PDO $pdo, string $driver, string $table, string $column): void
    {
        if ($driver === 'sqlite') {
            try {
                $pdo->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $column);
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'no such column') && !str_contains($msg, 'duplicate') && !str_contains($msg, 'cannot drop')) {
                    throw $e;
                }
            }

            return;
        }
        if ($driver === 'mysql') {
            self::safeExec($pdo, 'ALTER TABLE `' . str_replace('`', '``', $table) . '` DROP COLUMN `' . str_replace('`', '``', $column) . '`', [
                "doesn't exist",
                'check that column/key exists',
                'unknown column',
            ]);

            return;
        }
        $t = '"' . str_replace('"', '""', $table) . '"';
        $c = '"' . str_replace('"', '""', $column) . '"';
        self::safeExec($pdo, "ALTER TABLE {$t} DROP COLUMN IF EXISTS {$c}", []);
    }

    /**
     * Adds test_run_items.severity for existing databases (new installs get it from schema files).
     * Allowed values are enforced in RunController; extend schema CHECK constraints when adding values.
     */
    private static function runItemSeverityMigrations(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            try {
                $pdo->exec("ALTER TABLE test_run_items ADD COLUMN severity TEXT NOT NULL DEFAULT 'unclear'");
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'duplicate column name') && !str_contains($msg, 'duplicate column')) {
                    throw $e;
                }
            }

            return;
        }
        if ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                "ALTER TABLE test_run_items ADD COLUMN severity VARCHAR(32) NOT NULL DEFAULT 'unclear'",
                ['duplicate column name', 'duplicate column'],
            );

            return;
        }
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE test_run_items ADD COLUMN IF NOT EXISTS severity TEXT NOT NULL DEFAULT 'unclear'");
        }
    }

    /**
     * Adds test_run_items.screenshots_json and test_run_items.video_url for existing databases.
     */
    private static function runItemScreenshotsMigrations(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            try {
                $pdo->exec("ALTER TABLE test_run_items ADD COLUMN screenshots_json TEXT NOT NULL DEFAULT '[]'");
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'duplicate column name') && !str_contains($msg, 'duplicate column')) {
                    throw $e;
                }
            }
            try {
                $pdo->exec('ALTER TABLE test_run_items ADD COLUMN video_url TEXT');
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'duplicate column name') && !str_contains($msg, 'duplicate column')) {
                    throw $e;
                }
            }

            return;
        }
        if ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                "ALTER TABLE test_run_items ADD COLUMN screenshots_json TEXT NOT NULL DEFAULT '[]'",
                ['duplicate column name', 'duplicate column'],
            );
            self::safeExec(
                $pdo,
                'ALTER TABLE test_run_items ADD COLUMN video_url TEXT',
                ['duplicate column name', 'duplicate column'],
            );

            return;
        }
        if ($driver === 'pgsql') {
            $pdo->exec("ALTER TABLE test_run_items ADD COLUMN IF NOT EXISTS screenshots_json TEXT NOT NULL DEFAULT '[]'");
            $pdo->exec('ALTER TABLE test_run_items ADD COLUMN IF NOT EXISTS video_url TEXT');
        }
    }

    /**
     * SQLite ships CREATE TABLE IF NOT EXISTS only for new DBs; existing files need column adds.
     */
    private static function sqliteAdditiveMigrations(PDO $pdo): void
    {
        /*
         * SQLite CHECK enforcement: rows are validated against the table definition stored in sqlite_master.
         * Adding `section` to run_kind requires rebuilding `test_runs` when the live table still has the old CHECK;
         * see {@see sqliteRebuildTestRunsIfSectionRunKindDisallowed}.
         *
         * test_runs.section_id ON DELETE SET NULL: deleting a section clears the pointer on historical runs while
         * leaving the run row; run_items for cases in that section are removed via CASCADE on test_cases.
         */
        $alters = [
            'ALTER TABLE test_runs ADD COLUMN suite_id INTEGER REFERENCES test_suites(id) ON DELETE SET NULL',
            "ALTER TABLE test_runs ADD COLUMN run_kind TEXT NOT NULL DEFAULT 'full_suite'",
            'ALTER TABLE test_runs ADD COLUMN section_id INTEGER REFERENCES test_sections(id) ON DELETE SET NULL',
        ];
        foreach ($alters as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                if (!str_contains($msg, 'duplicate column name') && !str_contains($msg, 'duplicate column')) {
                    throw $e;
                }
            }
        }
        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_suite ON test_runs(suite_id)');
        } catch (PDOException $e) {
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'no such column') && !str_contains($msg, 'duplicate')) {
                throw $e;
            }
        }
        try {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_section ON test_runs(section_id)');
        } catch (PDOException $e) {
            $msg = strtolower($e->getMessage());
            if (!str_contains($msg, 'no such column') && !str_contains($msg, 'duplicate')) {
                throw $e;
            }
        }

        self::sqliteRebuildTestRunsIfSectionRunKindDisallowed($pdo);
    }

    /**
     * Idempotent: if {@see sqliteTestRunsAcceptsRunKindSection} fails (old CHECK rejects run_kind = section),
     * copy test_runs into a new table whose CHECK includes section, then swap.
     */
    private static function sqliteRebuildTestRunsIfSectionRunKindDisallowed(PDO $pdo): void
    {
        if (self::sqliteTestRunsAcceptsRunKindSection($pdo)) {
            return;
        }

        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $pdo->exec(
                "CREATE TABLE test_runs_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  suite_id INTEGER REFERENCES test_suites(id) ON DELETE SET NULL,
  section_id INTEGER REFERENCES test_sections(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  run_kind TEXT NOT NULL DEFAULT 'full_suite' CHECK (run_kind IN ('full_suite', 'section', 'run_book')),
  state TEXT NOT NULL DEFAULT 'open' CHECK (state IN ('open', 'locked', 'archived')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
)"
            );
            $pdo->exec(
                'INSERT INTO test_runs_new (id, project_id, suite_id, section_id, name, run_kind, state, created_at)
                 SELECT id, project_id, suite_id, section_id, name, run_kind, state, created_at FROM test_runs'
            );
            $pdo->exec('DROP TABLE test_runs');
            $pdo->exec('ALTER TABLE test_runs_new RENAME TO test_runs');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_project ON test_runs(project_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_suite ON test_runs(suite_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_runs_section ON test_runs(section_id)');
            $maxId = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM test_runs')->fetchColumn();
            if ($maxId > 0) {
                $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'test_runs'");
                $pdo->exec('INSERT INTO sqlite_sequence (name, seq) VALUES (\'test_runs\', ' . $maxId . ')');
            }
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        }
    }

    /**
     * Probe whether INSERT with run_kind = section satisfies the stored CHECK (savepoint + rollback).
     * Falls back to sqlite_master SQL inspection when there are no projects (cannot insert a valid row).
     */
    private static function sqliteTestRunsAcceptsRunKindSection(PDO $pdo): bool
    {
        $pidRaw = $pdo->query('SELECT id FROM projects LIMIT 1')->fetchColumn();
        if ($pidRaw === false) {
            $sql = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'test_runs'")->fetchColumn();
            if (!is_string($sql)) {
                return true;
            }
            // Literal 'section' as a run_kind value (not the section_id column name).
            return str_contains($sql, "'section'") && str_contains($sql, 'run_kind');
        }

        $pid = (int) $pidRaw;
        try {
            $pdo->exec('SAVEPOINT sp_run_kind_section');
            $stmt = $pdo->prepare(
                "INSERT INTO test_runs (project_id, suite_id, section_id, name, run_kind, state)
                 VALUES (:pid, NULL, NULL, '__tt_migration_probe__', 'section', 'open')"
            );
            $stmt->execute(['pid' => $pid]);
            $pdo->exec('ROLLBACK TO SAVEPOINT sp_run_kind_section');
            $pdo->exec('RELEASE SAVEPOINT sp_run_kind_section');

            return true;
        } catch (PDOException $e) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT sp_run_kind_section');
            } catch (PDOException) {
                /* ignore */
            }
            try {
                $pdo->exec('RELEASE SAVEPOINT sp_run_kind_section');
            } catch (PDOException) {
                /* ignore */
            }
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'check') || str_contains($msg, 'constraint')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * MySQL / PostgreSQL: add section_id + idx when missing, widen run_kind CHECK to include section (idempotent).
     */
    private static function testRunsSectionAndRunKindMigrations(PDO $pdo, string $driver): void
    {
        if ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD COLUMN section_id BIGINT UNSIGNED NULL',
                ['duplicate column name', 'duplicate column'],
            );
            self::safeExec(
                $pdo,
                'ALTER TABLE test_runs ADD CONSTRAINT fk_test_runs_section FOREIGN KEY (section_id) REFERENCES test_sections (id) ON DELETE SET NULL',
                ['duplicate', 'already exists'],
            );
            self::safeExec($pdo, 'CREATE INDEX idx_runs_section ON test_runs (section_id)', ['duplicate']);
            self::mysqlUpgradeTestRunsRunKindCheck($pdo);

            return;
        }
        if ($driver === 'pgsql') {
            $pdo->exec(
                'ALTER TABLE test_runs ADD COLUMN IF NOT EXISTS section_id BIGINT REFERENCES test_sections (id) ON DELETE SET NULL'
            );
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_runs_section ON test_runs (section_id)', ['duplicate']);
            self::pgsqlUpgradeTestRunsRunKindCheck($pdo);
        }
    }

    private static function mysqlUpgradeTestRunsRunKindCheck(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT cc.constraint_name
             FROM information_schema.check_constraints cc
             INNER JOIN information_schema.table_constraints tc
               ON tc.constraint_schema = cc.constraint_schema
              AND tc.constraint_name = cc.constraint_name
             WHERE tc.table_schema = DATABASE()
               AND tc.table_name = 'test_runs'
               AND tc.constraint_type = 'CHECK'
               AND cc.check_clause LIKE '%run_kind%'
               AND cc.check_clause NOT LIKE '%section%'"
        );
        if ($stmt === false) {
            return;
        }
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            $ident = '`' . str_replace('`', '``', $name) . '`';
            self::safeExec(
                $pdo,
                "ALTER TABLE test_runs DROP CHECK {$ident}",
                ['check', "doesn't exist", 'unknown', 'failed', 'exist'],
            );
        }
        self::safeExec(
            $pdo,
            "ALTER TABLE test_runs ADD CONSTRAINT test_runs_run_kind_chk CHECK (run_kind IN ('full_suite', 'section', 'run_book'))",
            ['duplicate', 'already exists'],
        );
    }

    private static function pgsqlUpgradeTestRunsRunKindCheck(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT c.conname, pg_get_constraintdef(c.oid) AS def
             FROM pg_constraint c
             INNER JOIN pg_class rel ON rel.oid = c.conrelid
             INNER JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
             WHERE rel.relname = 'test_runs'
               AND nsp.nspname = CURRENT_SCHEMA()
               AND c.contype = 'c'"
        );
        if ($stmt === false) {
            return;
        }
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $def = strtolower((string) ($row['def'] ?? ''));
            if (!str_contains($def, 'run_kind')) {
                continue;
            }
            if (str_contains($def, 'section')) {
                continue;
            }
            $name = (string) ($row['conname'] ?? '');
            if ($name === '') {
                continue;
            }
            $ident = '"' . str_replace('"', '""', $name) . '"';
            self::safeExec($pdo, "ALTER TABLE test_runs DROP CONSTRAINT {$ident}", ['does not exist', 'undefined']);
        }
        self::safeExec(
            $pdo,
            "ALTER TABLE test_runs ADD CONSTRAINT test_runs_run_kind_chk CHECK (run_kind IN ('full_suite', 'section', 'run_book'))",
            ['already exists', 'duplicate'],
        );
    }

    /**
     * Adds the suite → section → case layer to existing databases without deleting data.
     */
    private static function sectionAdditiveMigrations(PDO $pdo, string $driver): void
    {
        $pdo->exec(self::testSectionsCreateSql($driver));
        self::safeExec($pdo, self::sectionIdAddColumnSql($driver), ['duplicate column name', 'duplicate column']);

        self::createDefaultSections($pdo, $driver);
        self::backfillCaseSections($pdo);

        if ($driver === 'mysql') {
            self::safeExec($pdo, 'CREATE INDEX idx_sections_suite ON test_sections(suite_id)', ['duplicate']);
            self::safeExec($pdo, 'CREATE INDEX idx_cases_section ON test_cases(section_id)', ['duplicate']);
            self::safeExec(
                $pdo,
                'ALTER TABLE test_cases ADD CONSTRAINT fk_test_cases_section FOREIGN KEY (section_id) REFERENCES test_sections (id) ON DELETE CASCADE',
                ['duplicate', 'already exists']
            );
            self::safeExec($pdo, 'ALTER TABLE test_cases MODIFY section_id BIGINT UNSIGNED NOT NULL', []);
        } elseif ($driver === 'pgsql') {
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_sections_suite ON test_sections(suite_id)', ['duplicate']);
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_cases_section ON test_cases(section_id)', ['duplicate']);
            self::safeExec(
                $pdo,
                'ALTER TABLE test_cases ADD CONSTRAINT fk_test_cases_section FOREIGN KEY (section_id) REFERENCES test_sections (id) ON DELETE CASCADE',
                ['already exists', 'duplicate']
            );
            self::safeExec($pdo, 'ALTER TABLE test_cases ALTER COLUMN section_id SET NOT NULL', []);
        } else {
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_sections_suite ON test_sections(suite_id)', ['duplicate']);
            self::safeExec($pdo, 'CREATE INDEX IF NOT EXISTS idx_cases_section ON test_cases(section_id)', ['duplicate']);
        }
    }

    /** Order cases within each section (was implicit via id on legacy DBs). */
    private static function testCaseSortOrderMigrations(PDO $pdo, string $driver): void
    {
        if (!self::tableExists($pdo, $driver, 'test_cases')) {
            return;
        }
        if (self::columnExists($pdo, $driver, 'test_cases', 'sort_order')) {
            return;
        }

        $ignore = ['duplicate column name', 'duplicate column', 'already exists'];
        if ($driver === 'mysql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_cases ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER status',
                $ignore
            );
        } elseif ($driver === 'pgsql') {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_cases ADD COLUMN IF NOT EXISTS sort_order INTEGER NOT NULL DEFAULT 0',
                $ignore
            );
        } else {
            self::safeExec(
                $pdo,
                'ALTER TABLE test_cases ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0',
                $ignore
            );
        }

        $pdo->exec(
            'UPDATE test_cases SET sort_order = (
               SELECT COUNT(*) - 1 FROM test_cases c2
               WHERE c2.section_id = test_cases.section_id AND c2.id < test_cases.id
             )'
        );
    }

    private static function testSectionsCreateSql(string $driver): string
    {
        return match ($driver) {
            'mysql' => "CREATE TABLE IF NOT EXISTS test_sections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  suite_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(500) NOT NULL,
  precondition TEXT,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sections_suite (suite_id),
  CONSTRAINT fk_test_sections_suite FOREIGN KEY (suite_id) REFERENCES test_suites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'pgsql' => 'CREATE TABLE IF NOT EXISTS test_sections (
  id BIGSERIAL PRIMARY KEY,
  suite_id BIGINT NOT NULL REFERENCES test_suites (id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  precondition TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
)',
            default => "CREATE TABLE IF NOT EXISTS test_sections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  suite_id INTEGER NOT NULL REFERENCES test_suites(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  precondition TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
)",
        };
    }

    private static function sectionIdAddColumnSql(string $driver): string
    {
        return match ($driver) {
            'mysql' => 'ALTER TABLE test_cases ADD COLUMN section_id BIGINT UNSIGNED NULL AFTER suite_id',
            'pgsql' => 'ALTER TABLE test_cases ADD COLUMN IF NOT EXISTS section_id BIGINT',
            default => 'ALTER TABLE test_cases ADD COLUMN section_id INTEGER REFERENCES test_sections(id) ON DELETE CASCADE',
        };
    }

    private static function createDefaultSections(PDO $pdo, string $driver): void
    {
        if ($driver === 'mysql') {
            $pdo->exec(
                "INSERT INTO test_sections (suite_id, name, precondition, sort_order)
                 SELECT s.id, 'Default', NULL, 0
                 FROM test_suites s
                 LEFT JOIN test_sections ts ON ts.suite_id = s.id
                 WHERE ts.id IS NULL"
            );
            return;
        }

        $pdo->exec(
            "INSERT INTO test_sections (suite_id, name, precondition, sort_order)
             SELECT s.id, 'Default', NULL, 0
             FROM test_suites s
             WHERE NOT EXISTS (SELECT 1 FROM test_sections ts WHERE ts.suite_id = s.id)"
        );
    }

    private static function backfillCaseSections(PDO $pdo): void
    {
        $pdo->exec(
            "UPDATE test_cases
             SET section_id = (
               SELECT ts.id FROM test_sections ts
               WHERE ts.suite_id = test_cases.suite_id
               ORDER BY CASE WHEN ts.name = 'Default' THEN 0 ELSE 1 END, ts.sort_order, ts.id
               LIMIT 1
             )
             WHERE section_id IS NULL"
        );
    }

    /**
     * @param list<string> $ignoreNeedles
     */
    private static function safeExec(PDO $pdo, string $sql, array $ignoreNeedles): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $msg = strtolower($e->getMessage());
            foreach ($ignoreNeedles as $needle) {
                if ($needle !== '' && str_contains($msg, strtolower($needle))) {
                    return;
                }
            }
            throw $e;
        }
    }

    /**
     * Run a SQL file as separate statements (required for MySQL PDO without MULTI_STATEMENTS).
     */
    private static function execScriptStatements(PDO $pdo, string $sql): void
    {
        $lines = explode("\n", $sql);
        $buf = [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '--')) {
                continue;
            }
            $buf[] = $line;
        }
        $clean = implode("\n", $buf);
        $parts = preg_split('/;\s*\n/', $clean) ?: [];
        foreach ($parts as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (!str_ends_with($chunk, ';')) {
                $chunk .= ';';
            }
            $pdo->exec($chunk);
        }
    }

    private static function pdoSqlite(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dsn = 'sqlite:' . $path;
        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Cannot open SQLite database: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
        $pdo->exec('PRAGMA foreign_keys = ON;');
        return $pdo;
    }

    private static function pdoMysql(
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        ?string $socket,
    ): PDO {
        if ($socket !== null && $socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $database);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        }
        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Cannot connect to MySQL: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    private static function pdoPgsql(string $host, int $port, string $database, string $username, string $password): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Cannot connect to PostgreSQL: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
