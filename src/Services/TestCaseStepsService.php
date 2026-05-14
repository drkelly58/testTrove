<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Persists and loads ordered test steps (and variants) for live cases and version snapshots.
 *
 * @phpstan-type StepRow array{action: string, expected: string, variants?: list<array{label?: string, criteria: string}>}
 */
final class TestCaseStepsService
{
    /**
     * @param list<int> $caseIds
     * @return array<int, list<StepRow>>
     */
    public static function loadStepsBatchForCases(PDO $pdo, array $caseIds): array
    {
        $caseIds = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $caseIds), static fn ($v) => $v > 0)));
        if ($caseIds === []) {
            return [];
        }
        $out = [];
        foreach ($caseIds as $cid) {
            $out[$cid] = [];
        }
        $ph = implode(',', array_fill(0, count($caseIds), '?'));
        $st = $pdo->prepare(
            "SELECT id, test_case_id, sort_order, action, expected
             FROM test_case_steps WHERE test_case_id IN ($ph)
             ORDER BY test_case_id, sort_order, id"
        );
        $st->execute($caseIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return $out;
        }
        $stepIds = [];
        foreach ($rows as $r) {
            $stepIds[] = (int) $r['id'];
        }
        $varsByStep = self::fetchVariantsForCaseStepIds($pdo, $stepIds);
        foreach ($rows as $r) {
            $cid = (int) $r['test_case_id'];
            $sid = (int) $r['id'];
            $item = [
                'action' => (string) $r['action'],
                'expected' => (string) $r['expected'],
            ];
            $vars = $varsByStep[$sid] ?? [];
            if ($vars !== []) {
                $item['variants'] = $vars;
            }
            $out[$cid][] = $item;
        }

        return $out;
    }

    /**
     * @param list<int> $versionIds
     * @return array<int, list<StepRow>>
     */
    public static function loadStepsBatchForVersions(PDO $pdo, array $versionIds): array
    {
        $versionIds = array_values(array_unique(array_filter(array_map(static fn ($v) => (int) $v, $versionIds), static fn ($v) => $v > 0)));
        if ($versionIds === []) {
            return [];
        }
        $out = [];
        foreach ($versionIds as $vid) {
            $out[$vid] = [];
        }
        $ph = implode(',', array_fill(0, count($versionIds), '?'));
        $st = $pdo->prepare(
            "SELECT id, version_id, sort_order, action, expected
             FROM test_case_version_steps WHERE version_id IN ($ph)
             ORDER BY version_id, sort_order, id"
        );
        $st->execute($versionIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return $out;
        }
        $stepIds = [];
        foreach ($rows as $r) {
            $stepIds[] = (int) $r['id'];
        }
        $varsByStep = self::fetchVariantsForVersionStepIds($pdo, $stepIds);
        foreach ($rows as $r) {
            $vid = (int) $r['version_id'];
            $sid = (int) $r['id'];
            $item = [
                'action' => (string) $r['action'],
                'expected' => (string) $r['expected'],
            ];
            $vars = $varsByStep[$sid] ?? [];
            if ($vars !== []) {
                $item['variants'] = $vars;
            }
            $out[$vid][] = $item;
        }

        return $out;
    }

    /**
     * @return list<StepRow>
     */
    public static function loadStepsForCase(PDO $pdo, int $caseId): array
    {
        return self::loadStepsBatchForCases($pdo, [$caseId])[$caseId] ?? [];
    }

    /**
     * @return list<StepRow>
     */
    public static function loadStepsForVersion(PDO $pdo, int $versionId): array
    {
        return self::loadStepsBatchForVersions($pdo, [$versionId])[$versionId] ?? [];
    }

    /**
     * Copies live case steps (and variants) into a newly inserted version row.
     */
    public static function snapshotCaseStepsToVersion(PDO $pdo, int $caseId, int $versionId): void
    {
        $steps = self::loadStepsForCase($pdo, $caseId);
        self::replaceVersionSteps($pdo, $versionId, $steps);
    }

    /**
     * @param list<StepRow> $steps
     */
    public static function replaceCaseSteps(PDO $pdo, int $caseId, array $steps): void
    {
        $del = $pdo->prepare('DELETE FROM test_case_steps WHERE test_case_id = :cid');
        $del->execute(['cid' => $caseId]);
        self::insertCaseSteps($pdo, $caseId, $steps);
    }

    /**
     * @param list<StepRow> $steps
     */
    public static function replaceVersionSteps(PDO $pdo, int $versionId, array $steps): void
    {
        $del = $pdo->prepare('DELETE FROM test_case_version_steps WHERE version_id = :vid');
        $del->execute(['vid' => $versionId]);
        self::insertVersionSteps($pdo, $versionId, $steps);
    }

    /**
     * @param list<StepRow> $steps
     */
    public static function insertCaseSteps(PDO $pdo, int $caseId, array $steps): void
    {
        $insStep = $pdo->prepare(
            'INSERT INTO test_case_steps (test_case_id, sort_order, action, expected) VALUES (:cid, :so, :a, :e)'
        );
        $insVar = $pdo->prepare(
            'INSERT INTO test_case_step_variants (step_id, sort_order, label, criteria) VALUES (:sid, :so, :l, :c)'
        );
        $so = 0;
        foreach ($steps as $step) {
            $insStep->execute([
                'cid' => $caseId,
                'so' => $so,
                'a' => (string) ($step['action'] ?? ''),
                'e' => (string) ($step['expected'] ?? ''),
            ]);
            $stepId = (int) $pdo->lastInsertId();
            $variants = $step['variants'] ?? [];
            if (!is_array($variants)) {
                $variants = [];
            }
            $vso = 0;
            foreach ($variants as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $criteria = trim((string) ($v['criteria'] ?? ''));
                if ($criteria === '') {
                    continue;
                }
                $label = trim((string) ($v['label'] ?? ''));
                $insVar->execute([
                    'sid' => $stepId,
                    'so' => $vso,
                    'l' => $label === '' ? null : $label,
                    'c' => $criteria,
                ]);
                ++$vso;
            }
            ++$so;
        }
    }

    /**
     * @param list<StepRow> $steps
     */
    private static function insertVersionSteps(PDO $pdo, int $versionId, array $steps): void
    {
        $insStep = $pdo->prepare(
            'INSERT INTO test_case_version_steps (version_id, sort_order, action, expected) VALUES (:vid, :so, :a, :e)'
        );
        $insVar = $pdo->prepare(
            'INSERT INTO test_case_version_step_variants (version_step_id, sort_order, label, criteria) VALUES (:sid, :so, :l, :c)'
        );
        $so = 0;
        foreach ($steps as $step) {
            $insStep->execute([
                'vid' => $versionId,
                'so' => $so,
                'a' => (string) ($step['action'] ?? ''),
                'e' => (string) ($step['expected'] ?? ''),
            ]);
            $stepId = (int) $pdo->lastInsertId();
            $variants = $step['variants'] ?? [];
            if (!is_array($variants)) {
                $variants = [];
            }
            $vso = 0;
            foreach ($variants as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $criteria = trim((string) ($v['criteria'] ?? ''));
                if ($criteria === '') {
                    continue;
                }
                $label = trim((string) ($v['label'] ?? ''));
                $insVar->execute([
                    'sid' => $stepId,
                    'so' => $vso,
                    'l' => $label === '' ? null : $label,
                    'c' => $criteria,
                ]);
                ++$vso;
            }
            ++$so;
        }
    }

    /**
     * @param list<int> $stepIds
     * @return array<int, list<array{label?: string, criteria: string}>>
     */
    private static function fetchVariantsForCaseStepIds(PDO $pdo, array $stepIds): array
    {
        if ($stepIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($stepIds), '?'));
        $st = $pdo->prepare(
            "SELECT step_id, sort_order, label, criteria FROM test_case_step_variants
             WHERE step_id IN ($ph) ORDER BY step_id, sort_order, id"
        );
        $st->execute($stepIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int) $r['step_id'];
            $criteria = trim((string) $r['criteria']);
            if ($criteria === '') {
                continue;
            }
            $row = ['criteria' => $criteria];
            $label = $r['label'];
            if ($label !== null && trim((string) $label) !== '') {
                $row['label'] = trim((string) $label);
            }
            $out[$sid][] = $row;
        }

        return $out;
    }

    /**
     * @param list<int> $stepIds
     * @return array<int, list<array{label?: string, criteria: string}>>
     */
    private static function fetchVariantsForVersionStepIds(PDO $pdo, array $stepIds): array
    {
        if ($stepIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($stepIds), '?'));
        $st = $pdo->prepare(
            "SELECT version_step_id, sort_order, label, criteria FROM test_case_version_step_variants
             WHERE version_step_id IN ($ph) ORDER BY version_step_id, sort_order, id"
        );
        $st->execute($stepIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sid = (int) $r['version_step_id'];
            $criteria = trim((string) $r['criteria']);
            if ($criteria === '') {
                continue;
            }
            $row = ['criteria' => $criteria];
            $label = $r['label'];
            if ($label !== null && trim((string) $label) !== '') {
                $row['label'] = trim((string) $label);
            }
            $out[$sid][] = $row;
        }

        return $out;
    }
}
