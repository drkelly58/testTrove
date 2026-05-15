<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Resolves project_id from suite, section, case, or run identifiers. */
final class ProjectScopeResolver
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function projectIdForSuite(int $suiteId): ?int
    {
        if ($suiteId <= 0) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT project_id FROM test_suites WHERE id = :id LIMIT 1');
        $st->execute(['id' => $suiteId]);
        $v = $st->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    public function projectIdForSection(int $sectionId): ?int
    {
        if ($sectionId <= 0) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT s.project_id FROM test_sections sec
             INNER JOIN test_suites s ON s.id = sec.suite_id
             WHERE sec.id = :id LIMIT 1'
        );
        $st->execute(['id' => $sectionId]);
        $v = $st->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    public function projectIdForCase(int $caseId): ?int
    {
        if ($caseId <= 0) {
            return null;
        }
        $st = $this->pdo->prepare(
            'SELECT s.project_id FROM test_cases c
             INNER JOIN test_suites s ON s.id = c.suite_id
             WHERE c.id = :id LIMIT 1'
        );
        $st->execute(['id' => $caseId]);
        $v = $st->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    public function projectIdForRun(int $runId): ?int
    {
        if ($runId <= 0) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT project_id FROM test_runs WHERE id = :id LIMIT 1');
        $st->execute(['id' => $runId]);
        $v = $st->fetchColumn();

        return $v === false ? null : (int) $v;
    }
}
