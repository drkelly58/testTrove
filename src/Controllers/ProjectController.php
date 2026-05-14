<?php

declare(strict_types=1);

namespace App\Controllers;

use App\JsonRequestBody;
use App\JsonResponse;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProjectController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    private function readJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if ($parsed instanceof \stdClass) {
            $parsed = json_decode(json_encode($parsed, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        if (is_array($parsed)) {
            return $parsed;
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            try {
                $stream->rewind();
            } catch (\Throwable) {
            }
        }
        $raw = trim((string) $stream->getContents());
        if ($raw === '') {
            throw new \JsonException('Empty request body');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('JSON root must be an object or array');
        }

        return $decoded;
    }

    /**
     * @throws \PDOException
     */
    private function insertProjectRow(string $name, ?string $description): int
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO projects (name, description) VALUES (:name, :description) RETURNING id'
            );
            $stmt->execute(['name' => $name, 'description' => $description]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || !array_key_exists('id', $row)) {
                throw new \RuntimeException('INSERT did not return id');
            }

            return (int) $row['id'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO projects (name, description) VALUES (:name, :description)');
        $stmt->execute(['name' => $name, 'description' => $description]);
        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0) {
            $id = (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        }

        return $id;
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stmt = $this->pdo->query('SELECT id, name, description, created_at FROM projects ORDER BY id DESC');
        $rows = $stmt->fetchAll();
        return JsonResponse::encode($response, ['data' => $rows]);
    }

    /**
     * DELETE /api/projects/{projectId}
     *
     * Cascades to every suite, case, run, run item, and case version under the project.
     * Pass `?dry_run=1` to fetch cascade counts without deleting (used by the UI warning).
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($projectId <= 0) {
            return JsonResponse::error('Invalid project id', 422);
        }
        $stmt = $this->pdo->prepare('SELECT id, name FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return JsonResponse::error('project not found', 404);
        }

        $counts = $this->countProjectCascade($projectId);
        $dryRun = self::isDryRun($request);

        if ($dryRun) {
            return JsonResponse::encode($response, [
                'data' => [
                    'dry_run' => true,
                    'project' => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                    'cascade' => $counts,
                ],
            ]);
        }

        try {
            $del = $this->pdo->prepare('DELETE FROM projects WHERE id = :id');
            $del->execute(['id' => $projectId]);
        } catch (\PDOException $e) {
            error_log('DELETE /api/projects/' . $projectId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while deleting the project.', 500);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'project' => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                'cascade' => $counts,
            ],
        ]);
    }

    /**
     * @return array{suites: int, sections: int, cases: int, runs: int, run_items: int, versions: int}
     */
    private function countProjectCascade(int $projectId): array
    {
        $suites = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_suites WHERE project_id = :pid',
            ['pid' => $projectId]
        );
        $sections = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_sections ts
             INNER JOIN test_suites s ON s.id = ts.suite_id
             WHERE s.project_id = :pid',
            ['pid' => $projectId]
        );
        $cases = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_cases c
             INNER JOIN test_suites s ON s.id = c.suite_id
             WHERE s.project_id = :pid',
            ['pid' => $projectId]
        );
        $runs = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_runs WHERE project_id = :pid',
            ['pid' => $projectId]
        );
        $runItems = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items i
             INNER JOIN test_runs r ON r.id = i.run_id
             WHERE r.project_id = :pid',
            ['pid' => $projectId]
        );
        $versions = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_case_versions v
             INNER JOIN test_suites s ON s.id = v.suite_id
             WHERE s.project_id = :pid',
            ['pid' => $projectId]
        );

        return [
            'suites' => $suites,
            'sections' => $sections,
            'cases' => $cases,
            'runs' => $runs,
            'run_items' => $runItems,
            'versions' => $versions,
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
        if ($v === false) {
            return 0;
        }

        return $v;
    }

    private static function isDryRun(ServerRequestInterface $request): bool
    {
        $q = $request->getQueryParams();
        if (!array_key_exists('dry_run', $q)) {
            return false;
        }

        return filter_var($q['dry_run'], FILTER_VALIDATE_BOOLEAN);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            try {
                $data = $this->readJsonBody($request);
            } catch (\JsonException $e) {
                return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
            }
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                return JsonResponse::error('name is required', 422);
            }
            $description = isset($data['description']) ? (string) $data['description'] : null;

            try {
                $id = $this->insertProjectRow($name, $description);
            } catch (\PDOException $e) {
                $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';
                $detail = $debug ? $e->getMessage() : 'Database error while saving the project.';
                error_log('POST /api/projects failed: ' . $e->getMessage());

                return JsonResponse::error($detail, 500);
            }

            return JsonResponse::encode($response, ['data' => ['id' => $id, 'name' => $name, 'description' => $description]], 201);
        } catch (\Throwable $e) {
            error_log('POST /api/projects unexpected: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Could not create project', 500);
        }
    }

    /** PATCH /api/projects/{projectId} */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($projectId <= 0) {
            return JsonResponse::error('Invalid project id', 422);
        }
        $stmt = $this->pdo->prepare('SELECT id FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $projectId]);
        if (!$stmt->fetchColumn()) {
            return JsonResponse::error('project not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $sets = [];
        $params = ['id' => $projectId];
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return JsonResponse::error('name cannot be empty', 422);
            }
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $sets[] = 'description = :description';
            $params['description'] = $this->nullableDescription($data['description']);
        }
        if ($sets === []) {
            return JsonResponse::error('No project fields to update', 422);
        }

        try {
            $upd = $this->pdo->prepare('UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $upd->execute($params);
        } catch (\PDOException $e) {
            error_log('PATCH /api/projects/' . $projectId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while updating the project.', 500);
        }

        $row = $this->fetchProjectRow($projectId);
        if ($row === null) {
            return JsonResponse::error('project not found', 404);
        }

        return JsonResponse::encode($response, ['data' => $row]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProjectRow(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, description, created_at FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function nullableDescription(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
