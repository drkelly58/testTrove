<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Per-section ordering for rows in test_cases (sort_order). */
final class TestCaseOrderService
{
    public static function nextSortOrder(PDO $pdo, int $sectionId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM test_cases WHERE section_id = :sec'
        );
        $stmt->execute(['sec' => $sectionId]);

        return (int) $stmt->fetchColumn();
    }

    public static function bumpSortOrdersFrom(PDO $pdo, int $sectionId, int $fromOrder): void
    {
        $stmt = $pdo->prepare(
            'UPDATE test_cases SET sort_order = sort_order + 1 WHERE section_id = :sec AND sort_order >= :pos'
        );
        $stmt->execute(['sec' => $sectionId, 'pos' => $fromOrder]);
    }

    /**
     * @param list<int> $orderedCaseIds case ids in desired order (0..n-1 sort_order)
     */
    public static function applyOrder(PDO $pdo, int $sectionId, array $orderedCaseIds): void
    {
        $upd = $pdo->prepare(
            'UPDATE test_cases SET sort_order = :ord WHERE id = :id AND section_id = :sec'
        );
        foreach ($orderedCaseIds as $index => $caseId) {
            $upd->execute(['ord' => $index, 'id' => $caseId, 'sec' => $sectionId]);
        }
    }

    /**
     * @return list<int>
     */
    public static function listCaseIdsInSection(PDO $pdo, int $suiteId, int $sectionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM test_cases WHERE suite_id = :sid AND section_id = :sec ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['sid' => $suiteId, 'sec' => $sectionId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @return array{section_id: int, sort_order: int}|null
     */
    public static function anchorRow(PDO $pdo, int $caseId, int $suiteId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT section_id, sort_order FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1'
        );
        $stmt->execute(['id' => $caseId, 'sid' => $suiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : [
            'section_id' => (int) $row['section_id'],
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
