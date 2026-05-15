<?php

declare(strict_types=1);

namespace App\Controllers;

use App\JsonRequestBody;
use App\JsonResponse;
use App\Services\AuthorizationService;
use App\Services\ProjectScopeResolver;
use App\Services\TestCaseStepsService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SuiteController
{
    use AuthorizesApiAccess;

    public function __construct(
        private readonly PDO $pdo,
        AuthorizationService $authorization,
        ProjectScopeResolver $projectScope,
    ) {
        $this->initAuthorization($authorization, $projectScope);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($denied = $this->authorizeCatalogRead($projectId)) {
            return $denied;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, name, created_at FROM test_suites WHERE project_id = :pid ORDER BY id'
        );
        $stmt->execute(['pid' => $projectId]);
        return JsonResponse::encode($response, ['data' => $stmt->fetchAll()]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($denied = $this->authorizeProjectWrite($projectId)) {
            return $denied;
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
        $stmt = $this->pdo->prepare(
            'INSERT INTO test_suites (project_id, name, sort_order) VALUES (:project_id, :name, 0)'
        );
        $stmt->execute(['project_id' => $projectId, 'name' => $name]);
        $id = (int) $this->pdo->lastInsertId();

        return JsonResponse::encode($response, ['data' => ['id' => $id, 'project_id' => $projectId, 'name' => $name]], 201);
    }

    /** PATCH /api/projects/{projectId}/suites/{suiteId} */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($projectId <= 0 || $suiteId <= 0) {
            return JsonResponse::error('Invalid project or suite id', 422);
        }
        if ($denied = $this->authorizeProjectWrite($projectId)) {
            return $denied;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM test_suites WHERE id = :sid AND project_id = :pid LIMIT 1'
        );
        $stmt->execute(['sid' => $suiteId, 'pid' => $projectId]);
        if (!$stmt->fetchColumn()) {
            return JsonResponse::error('suite not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $sets = [];
        $params = ['id' => $suiteId, 'pid' => $projectId];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return JsonResponse::error('name cannot be empty', 422);
            }
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if ($sets === []) {
            return JsonResponse::error('No suite fields to update', 422);
        }

        try {
            $upd = $this->pdo->prepare(
                'UPDATE test_suites SET ' . implode(', ', $sets) . ' WHERE id = :id AND project_id = :pid'
            );
            $upd->execute($params);
        } catch (\PDOException $e) {
            error_log('PATCH /api/projects/' . $projectId . '/suites/' . $suiteId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while updating the suite.', 500);
        }

        $row = $this->fetchSuiteListRow($suiteId, $projectId);
        if ($row === null) {
            return JsonResponse::error('suite not found', 404);
        }

        return JsonResponse::encode($response, ['data' => $row]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSuiteListRow(int $suiteId, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, name, created_at FROM test_suites WHERE id = :sid AND project_id = :pid LIMIT 1'
        );
        $stmt->execute(['sid' => $suiteId, 'pid' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * DELETE /api/projects/{projectId}/suites/{suiteId}
     *
     * Cascades to every case in the suite (and each case's versions and run items).
     * Existing runs that pointed at this suite have their `suite_id` set to NULL but stay otherwise intact.
     * Pass `?dry_run=1` to fetch cascade counts without deleting.
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($projectId <= 0 || $suiteId <= 0) {
            return JsonResponse::error('Invalid project or suite id', 422);
        }
        if ($denied = $this->authorizeProjectWrite($projectId)) {
            return $denied;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, name FROM test_suites WHERE id = :sid AND project_id = :pid LIMIT 1'
        );
        $stmt->execute(['sid' => $suiteId, 'pid' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return JsonResponse::error('suite not found', 404);
        }

        $counts = $this->countSuiteCascade($suiteId);
        $dryRun = self::isDryRun($request);

        if ($dryRun) {
            return JsonResponse::encode($response, [
                'data' => [
                    'dry_run' => true,
                    'suite' => [
                        'id' => (int) $row['id'],
                        'project_id' => (int) $row['project_id'],
                        'name' => (string) $row['name'],
                    ],
                    'cascade' => $counts,
                ],
            ]);
        }

        try {
            $del = $this->pdo->prepare('DELETE FROM test_suites WHERE id = :sid AND project_id = :pid');
            $del->execute(['sid' => $suiteId, 'pid' => $projectId]);
        } catch (\PDOException $e) {
            error_log('DELETE /api/projects/' . $projectId . '/suites/' . $suiteId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while deleting the suite.', 500);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'suite' => [
                    'id' => (int) $row['id'],
                    'project_id' => (int) $row['project_id'],
                    'name' => (string) $row['name'],
                ],
                'cascade' => $counts,
            ],
        ]);
    }

    /**
     * @return array{sections: int, cases: int, versions: int, run_items: int, detached_runs: int}
     */
    private function countSuiteCascade(int $suiteId): array
    {
        $sections = (int) $this->scalar('SELECT COUNT(*) FROM test_sections WHERE suite_id = :sid', ['sid' => $suiteId]);
        $cases = (int) $this->scalar('SELECT COUNT(*) FROM test_cases WHERE suite_id = :sid', ['sid' => $suiteId]);
        $versions = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_case_versions WHERE suite_id = :sid',
            ['sid' => $suiteId]
        );
        $runItems = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items i
             INNER JOIN test_cases c ON c.id = i.case_id
             WHERE c.suite_id = :sid',
            ['sid' => $suiteId]
        );
        $detachedRuns = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_runs WHERE suite_id = :sid',
            ['sid' => $suiteId]
        );

        return [
            'sections' => $sections,
            'cases' => $cases,
            'versions' => $versions,
            'run_items' => $runItems,
            'detached_runs' => $detachedRuns,
        ];
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

    private static function isDryRun(ServerRequestInterface $request): bool
    {
        $q = $request->getQueryParams();
        if (!array_key_exists('dry_run', $q)) {
            return false;
        }

        return filter_var($q['dry_run'], FILTER_VALIDATE_BOOLEAN);
    }

    public function duplicate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($denied = $this->authorizeProjectWrite($projectId)) {
            return $denied;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM test_suites WHERE id = :sid AND project_id = :pid LIMIT 1'
        );
        $stmt->execute(['sid' => $suiteId, 'pid' => $projectId]);
        $suite = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($suite === false) {
            return JsonResponse::error('suite not found', 404);
        }

        $newName = (string) $suite['name'] . ' (copy)';
        $insSuite = $this->pdo->prepare(
            'INSERT INTO test_suites (project_id, name, sort_order) VALUES (:project_id, :name, 0)'
        );
        $insSuite->execute([
            'project_id' => $projectId,
            'name' => $newName,
        ]);
        $newSuiteId = (int) $this->pdo->lastInsertId();

        $sectionsStmt = $this->pdo->prepare(
            'SELECT id, name, precondition, sort_order FROM test_sections WHERE suite_id = :sid ORDER BY sort_order ASC, id ASC'
        );
        $sectionsStmt->execute(['sid' => $suiteId]);
        $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);

        $casesStmt = $this->pdo->prepare(
            'SELECT id, section_id, title, precondition, priority, status
             FROM test_cases WHERE suite_id = :sid ORDER BY id ASC'
        );
        $casesStmt->execute(['sid' => $suiteId]);
        $cases = $casesStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->pdo->beginTransaction();
        try {
            $sectionMap = [];
            $insSection = $this->pdo->prepare(
                'INSERT INTO test_sections (suite_id, name, precondition, sort_order)
                 VALUES (:suite_id, :name, :precondition, :sort_order)'
            );
            foreach ($sections as $section) {
                $insSection->execute([
                    'suite_id' => $newSuiteId,
                    'name' => $section['name'],
                    'precondition' => $section['precondition'],
                    'sort_order' => $section['sort_order'],
                ]);
                $sectionMap[(int) $section['id']] = (int) $this->pdo->lastInsertId();
            }
            if ($sectionMap === []) {
                $insSection->execute([
                    'suite_id' => $newSuiteId,
                    'name' => 'Default',
                    'precondition' => null,
                    'sort_order' => 0,
                ]);
                $defaultSectionId = (int) $this->pdo->lastInsertId();
            } else {
                $defaultSectionId = reset($sectionMap);
            }

            $insCase = $this->pdo->prepare(
                'INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status)
                 VALUES (:suite_id, :section_id, :title, :precondition, :priority, :status)'
            );
            foreach ($cases as $c) {
                $oldCaseId = (int) $c['id'];
                $insCase->execute([
                    'suite_id' => $newSuiteId,
                    'section_id' => $sectionMap[(int) $c['section_id']] ?? $defaultSectionId,
                    'title' => $c['title'],
                    'precondition' => $c['precondition'],
                    'priority' => $c['priority'],
                    'status' => $c['status'],
                ]);
                $newCaseId = (int) $this->pdo->lastInsertId();
                $steps = TestCaseStepsService::loadStepsForCase($this->pdo, $oldCaseId);
                TestCaseStepsService::replaceCaseSteps($this->pdo, $newCaseId, $steps);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            return JsonResponse::error('Failed to duplicate suite: ' . $e->getMessage(), 500);
        }

        return JsonResponse::encode(
            $response,
            [
                'data' => [
                    'id' => $newSuiteId,
                    'project_id' => $projectId,
                    'name' => $newName,
                    'cases_copied' => count($cases),
                    'sections_copied' => count($sectionMap),
                ],
            ],
            201
        );
    }
}
