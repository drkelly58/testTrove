<?php

declare(strict_types=1);

namespace App\Controllers;

use App\JsonRequestBody;
use App\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SectionController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if (!$this->suiteExists($suiteId)) {
            return JsonResponse::error('suite not found', 404);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, suite_id, name, precondition, sort_order, created_at
             FROM test_sections WHERE suite_id = :sid ORDER BY sort_order, id'
        );
        $stmt->execute(['sid' => $suiteId]);

        return JsonResponse::encode($response, ['data' => $stmt->fetchAll()]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if (!$this->suiteExists($suiteId)) {
            return JsonResponse::error('suite not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return JsonResponse::error('name is required', 422);
        }
        $precondition = $this->nullableString($data['precondition'] ?? null);
        $sortOrder = array_key_exists('sort_order', $data)
            ? (int) $data['sort_order']
            : $this->nextSortOrder($suiteId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO test_sections (suite_id, name, precondition, sort_order)
             VALUES (:suite_id, :name, :precondition, :sort_order)'
        );
        $stmt->execute([
            'suite_id' => $suiteId,
            'name' => $name,
            'precondition' => $precondition,
            'sort_order' => $sortOrder,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        return JsonResponse::encode($response, [
            'data' => [
                'id' => $id,
                'suite_id' => $suiteId,
                'name' => $name,
                'precondition' => $precondition,
                'sort_order' => $sortOrder,
            ],
        ], 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $sectionId = (int) ($args['sectionId'] ?? 0);
        if (!$this->sectionInSuite($sectionId, $suiteId)) {
            return JsonResponse::error('section not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $sets = [];
        $params = ['id' => $sectionId, 'sid' => $suiteId];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return JsonResponse::error('name cannot be empty', 422);
            }
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('precondition', $data)) {
            $sets[] = 'precondition = :precondition';
            $params['precondition'] = $this->nullableString($data['precondition']);
        }
        if (array_key_exists('sort_order', $data)) {
            $sets[] = 'sort_order = :sort_order';
            $params['sort_order'] = (int) $data['sort_order'];
        }
        if ($sets === []) {
            return JsonResponse::error('No section fields to update', 422);
        }

        try {
            $stmt = $this->pdo->prepare(
                'UPDATE test_sections SET ' . implode(', ', $sets) . ' WHERE id = :id AND suite_id = :sid'
            );
            $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log('PATCH /api/suites/' . $suiteId . '/sections/' . $sectionId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while updating the section.', 500);
        }

        return JsonResponse::encode($response, ['data' => $this->fetchSection($sectionId, $suiteId)]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $sectionId = (int) ($args['sectionId'] ?? 0);
        $section = $this->fetchSection($sectionId, $suiteId);
        if ($section === null) {
            return JsonResponse::error('section not found', 404);
        }

        $counts = $this->countSectionCascade($sectionId);
        if (self::isDryRun($request)) {
            return JsonResponse::encode($response, [
                'data' => [
                    'dry_run' => true,
                    'section' => $section,
                    'cascade' => $counts,
                ],
            ]);
        }

        try {
            $del = $this->pdo->prepare('DELETE FROM test_sections WHERE id = :id AND suite_id = :sid');
            $del->execute(['id' => $sectionId, 'sid' => $suiteId]);
        } catch (\PDOException $e) {
            error_log('DELETE /api/suites/' . $suiteId . '/sections/' . $sectionId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while deleting the section.', 500);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'section' => $section,
                'cascade' => $counts,
            ],
        ]);
    }

    /**
     * @return array{cases: int, versions: int, run_items: int}
     */
    private function countSectionCascade(int $sectionId): array
    {
        $cases = (int) $this->scalar('SELECT COUNT(*) FROM test_cases WHERE section_id = :sid', ['sid' => $sectionId]);
        $versions = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_case_versions v
             INNER JOIN test_cases c ON c.id = v.case_id
             WHERE c.section_id = :sid',
            ['sid' => $sectionId]
        );
        $runItems = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items i
             INNER JOIN test_cases c ON c.id = i.case_id
             WHERE c.section_id = :sid',
            ['sid' => $sectionId]
        );

        return ['cases' => $cases, 'versions' => $versions, 'run_items' => $runItems];
    }

    /**
     * @param array<string, scalar|null> $params
     */
    private function scalar(string $sql, array $params): int|string|float|null
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();

        return $v === false ? 0 : $v;
    }

    private function nextSortOrder(int $suiteId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM test_sections WHERE suite_id = :sid'
        );
        $stmt->execute(['sid' => $suiteId]);

        return (int) $stmt->fetchColumn();
    }

    private function suiteExists(int $suiteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM test_suites WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $suiteId]);

        return (bool) $stmt->fetchColumn();
    }

    private function sectionInSuite(int $sectionId, int $suiteId): bool
    {
        return $this->fetchSection($sectionId, $suiteId) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSection(int $sectionId, int $suiteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, suite_id, name, precondition, sort_order, created_at
             FROM test_sections WHERE id = :id AND suite_id = :sid LIMIT 1'
        );
        $stmt->execute(['id' => $sectionId, 'sid' => $suiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private static function isDryRun(ServerRequestInterface $request): bool
    {
        $q = $request->getQueryParams();
        if (!array_key_exists('dry_run', $q)) {
            return false;
        }

        return filter_var($q['dry_run'], FILTER_VALIDATE_BOOLEAN);
    }
}
