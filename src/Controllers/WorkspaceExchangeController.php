<?php

declare(strict_types=1);

namespace App\Controllers;

use App\IO\XlsxImportExportStub;
use App\JsonResponse;
use App\Services\CaseExchangeService;
use App\Services\TestCaseStepsService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Multi-suite export and import with optional project/suite creation and duplicate handling.
 */
final class WorkspaceExchangeController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $q = $request->getQueryParams();
        $projectId = (int) ($q['project_id'] ?? 0);
        $suiteIdsParam = trim((string) ($q['suite_ids'] ?? ''));
        $format = strtolower(trim((string) ($q['format'] ?? 'json')));

        if ($projectId <= 0) {
            return JsonResponse::error('project_id is required', 422);
        }

        if (!$this->projectExists($projectId)) {
            return JsonResponse::error('project not found', 404);
        }

        $proj = $this->fetchProjectRow($projectId);
        if ($proj === null) {
            return JsonResponse::error('project not found', 404);
        }

        $allSuites = $this->listSuitesForProject($projectId);
        if ($allSuites === []) {
            return JsonResponse::error('project has no suites', 422);
        }

        if ($suiteIdsParam === '') {
            $suiteIds = array_map(static fn (array $s): int => (int) $s['id'], $allSuites);
        } else {
            $suiteIds = array_values(array_filter(array_map('intval', explode(',', $suiteIdsParam))));
            $allowed = array_flip(array_map(static fn (array $s): int => (int) $s['id'], $allSuites));
            foreach ($suiteIds as $sid) {
                if (!isset($allowed[$sid])) {
                    return JsonResponse::error('suite ' . $sid . ' is not in this project', 422);
                }
            }
            if ($suiteIds === []) {
                return JsonResponse::error('suite_ids must list valid suite ids', 422);
            }
        }

        if ($format === 'xlsx') {
            return JsonResponse::encode(
                new Response(501),
                [
                    'error' => XlsxImportExportStub::MESSAGE,
                    'hint' => XlsxImportExportStub::HINT,
                ],
                501
            );
        }

        if ($format === 'csv') {
            $blocks = [];
            foreach ($suiteIds as $sid) {
                $suiteRow = $this->fetchSuiteRow($sid);
                if ($suiteRow === null || (int) $suiteRow['project_id'] !== $projectId) {
                    return JsonResponse::error('suite not found in project', 404);
                }
                $blocks[] = [
                    'suite_name' => (string) $suiteRow['name'],
                    'sections' => $this->fetchSectionsWithCases($sid),
                ];
            }
            $csv = CaseExchangeService::exportProjectWorkspaceCsvString(
                (string) $proj['name'],
                $proj['description'] !== null && $proj['description'] !== ''
                    ? (string) $proj['description']
                    : null,
                $blocks
            );
            $filename = 'workspace-project-' . $projectId . '-export.csv';
            $response = $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->getBody()->write($csv);

            return $response;
        }

        if ($format !== 'json') {
            return JsonResponse::error('Unsupported format. Use format=json, format=csv, or format=xlsx (stub).', 400);
        }

        $blocks = [];
        foreach ($suiteIds as $sid) {
            $suiteRow = $this->fetchSuiteRow($sid);
            if ($suiteRow === null || (int) $suiteRow['project_id'] !== $projectId) {
                return JsonResponse::error('suite not found in project', 404);
            }
            $blocks[] = [
                'project_name' => (string) $proj['name'],
                'project_description' => $proj['description'] !== null && $proj['description'] !== ''
                    ? (string) $proj['description']
                    : null,
                'suite_name' => (string) $suiteRow['name'],
                'sections' => $this->fetchSectionsWithCases($sid),
            ];
        }

        $json = json_encode(
            [
                'version' => 2,
                'exported_at' => gmdate('c'),
                'suites' => $blocks,
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ) . "\n";

        $filename = 'workspace-project-' . $projectId . '-export.json';
        $response = $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->getBody()->write($json);

        return $response;
    }

    /**
     * Same payload as GET /api/workspace/export with project_id and all suites (omit suite_ids).
     * Path: GET /api/projects/{projectId}/export?format=json|csv
     */
    public function exportByProjectId(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($projectId <= 0) {
            return JsonResponse::error('Invalid project id', 422);
        }
        $q = $request->getQueryParams();
        $q['project_id'] = (string) $projectId;
        if (!array_key_exists('suite_ids', $q)) {
            $q['suite_ids'] = '';
        }

        return $this->export($request->withQueryParams($q), $response);
    }

    public function csvPreview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = '';
        $files = $request->getUploadedFiles();
        if (isset($files['file']) && $files['file']->getError() === UPLOAD_ERR_OK) {
            $body = (string) $files['file']->getStream()->getContents();
        } else {
            $body = (string) $request->getBody();
        }
        if (trim($body) === '') {
            return JsonResponse::error('Upload a CSV file in field "file" or send a raw CSV body.', 422);
        }
        try {
            $parsed = CaseExchangeService::readCsvRaw($body, 25);
            $headers = $parsed['headers'];
            $suggested = CaseExchangeService::suggestCsvColumnMap($headers);
            $indices = CaseExchangeService::resolveCsvColumnIndices($headers, []);
            $mode = CaseExchangeService::detectCsvImportMode($indices, 'auto', 0);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return JsonResponse::error('CSV preview failed: ' . $e->getMessage(), 422);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'headers' => $headers,
                'suggested_column_map' => $suggested,
                'suggested_mode' => $mode,
                'sample_data_rows' => array_slice($parsed['rows'], 0, 8),
            ],
        ]);
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $opts = $this->parseOptionsFromRequest($request);
        $createMissing = !empty($opts['create_missing_entities']);
        $onDuplicate = strtolower(trim((string) ($opts['on_duplicate'] ?? 'allow')));
        if (!in_array($onDuplicate, ['skip', 'error', 'allow'], true)) {
            $onDuplicate = 'allow';
        }
        $targetSuiteId = (int) ($opts['target_suite_id'] ?? 0);

        $queryFormat = strtolower(trim($request->getQueryParams()['format'] ?? ''));
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        $body = '';
        $files = $request->getUploadedFiles();
        if (isset($files['file']) && $files['file']->getError() === UPLOAD_ERR_OK) {
            $body = (string) $files['file']->getStream()->getContents();
            if ($queryFormat === '') {
                $name = strtolower($files['file']->getClientFilename() ?? '');
                if (str_ends_with($name, '.csv')) {
                    $queryFormat = 'csv';
                } elseif (str_ends_with($name, '.json')) {
                    $queryFormat = 'json';
                } elseif (str_ends_with($name, '.xlsx')) {
                    $queryFormat = 'xlsx';
                }
            }
        } else {
            $body = (string) $request->getBody();
        }

        $format = $queryFormat;
        if ($format === '') {
            if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/json')) {
                $format = 'json';
            } elseif (str_contains($contentType, 'text/csv') || str_contains($contentType, 'application/csv')) {
                $format = 'csv';
            }
        }

        if ($format === 'xlsx') {
            return JsonResponse::encode(
                new Response(501),
                [
                    'error' => XlsxImportExportStub::MESSAGE,
                    'hint' => XlsxImportExportStub::HINT,
                ],
                501
            );
        }

        try {
            if ($format === 'json') {
                $trim = trim($body);
                if ($trim === '') {
                    return JsonResponse::error('Empty JSON body', 422);
                }
                $decoded = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
                $classified = CaseExchangeService::classifyJsonImport($decoded);
            } elseif ($format === 'csv') {
                $targetPid = (int) ($opts['target_project_id'] ?? 0);
                $csvMode = strtolower(trim((string) ($opts['csv_mode'] ?? 'auto')));
                if (!in_array($csvMode, ['auto', 'flat', 'project'], true)) {
                    $csvMode = 'auto';
                }
                $columnMap = [];
                if (isset($opts['column_map']) && is_array($opts['column_map'])) {
                    $columnMap = CaseExchangeService::sanitizeColumnMapInput($opts['column_map']);
                }
                $forcedName = null;
                $forcedDesc = null;
                if ($targetPid > 0) {
                    $pr = $this->fetchProjectRow($targetPid);
                    if ($pr === null) {
                        return JsonResponse::error('target_project_id not found', 422);
                    }
                    $forcedName = (string) $pr['name'];
                    $d = $pr['description'] ?? null;
                    $forcedDesc = $d !== null && trim((string) $d) !== '' ? (string) $d : null;
                }
                $classified = CaseExchangeService::classifyCsvImport(
                    $body,
                    $columnMap,
                    $csvMode,
                    $targetPid,
                    $forcedName,
                    $forcedDesc
                );
            } else {
                return JsonResponse::error(
                    'Set format=json or format=csv (query), Content-Type, or upload file field "file" with .csv/.json name.',
                    400
                );
            }
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return JsonResponse::error('Import failed: ' . $e->getMessage(), 422);
        }

        $this->pdo->beginTransaction();
        try {
            if ($classified['kind'] === 'workspace_v2') {
                $totals = $this->importWorkspaceV2Blocks($classified['suites'], $createMissing, $onDuplicate);
                $this->pdo->commit();

                return JsonResponse::encode($response, ['data' => $totals], 201);
            }

            $cases = $classified['cases'];
            if ($cases === []) {
                $this->pdo->rollBack();

                return JsonResponse::error('No cases to import', 422);
            }
            if ($targetSuiteId <= 0 || !$this->suiteExists($targetSuiteId)) {
                $this->pdo->rollBack();

                return JsonResponse::error('target_suite_id in options is required for flat JSON/CSV imports', 422);
            }

            $counts = CaseExchangeService::insertImportedCases($this->pdo, $targetSuiteId, $cases, $onDuplicate);
            $this->pdo->commit();

            return JsonResponse::encode(
                $response,
                [
                    'data' => [
                        'imported' => $counts['imported'],
                        'skipped_duplicates' => $counts['skipped'],
                        'suite_id' => $targetSuiteId,
                    ],
                ],
                201
            );
        } catch (\InvalidArgumentException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return JsonResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return JsonResponse::error('Database error during import: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array{imported: int, skipped_duplicates: int, suites_touched: list<int>}
     */
    private function importWorkspaceV2Blocks(array $blocks, bool $createMissing, string $onDuplicate): array
    {
        $imported = 0;
        $skipped = 0;
        $touched = [];

        foreach ($blocks as $block) {
            $pName = (string) $block['project_name'];
            $sName = (string) $block['suite_name'];
            $pDesc = $block['project_description'] ?? null;

            $pid = $this->findProjectIdByName($pName);
            if ($pid === null) {
                if (!$createMissing) {
                    throw new \InvalidArgumentException('Project not found: ' . $pName . ' (enable create missing entities or create it first)');
                }
                $insP = $this->pdo->prepare('INSERT INTO projects (name, description) VALUES (:n, :d)');
                $insP->execute(['n' => $pName, 'd' => $pDesc]);
                $pid = (int) $this->pdo->lastInsertId();
            }

            $sid = $this->findSuiteIdByName($pid, $sName);
            if ($sid === null) {
                if (!$createMissing) {
                    throw new \InvalidArgumentException('Suite not found: ' . $sName . ' in project ' . $pName);
                }
                $insS = $this->pdo->prepare(
                    'INSERT INTO test_suites (project_id, name, sort_order) VALUES (:p, :n, 0)'
                );
                $insS->execute(['p' => $pid, 'n' => $sName]);
                $sid = (int) $this->pdo->lastInsertId();
            }

            $cases = $block['cases'];
            if ($cases === []) {
                continue;
            }
            $counts = CaseExchangeService::insertImportedCases($this->pdo, $sid, $cases, $onDuplicate, $createMissing);
            $imported += $counts['imported'];
            $skipped += $counts['skipped'];
            $touched[] = $sid;
        }

        return [
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
            'suites_touched' => array_values(array_unique($touched)),
        ];
    }

    /**
     * @return array{
     *   create_missing_entities: bool,
     *   on_duplicate: string,
     *   target_suite_id: int,
     *   target_project_id: int,
     *   csv_mode: string,
     *   column_map: array<string, string>
     * }
     */
    private function parseOptionsFromRequest(ServerRequestInterface $request): array
    {
        $defaults = [
            'create_missing_entities' => false,
            'on_duplicate' => 'allow',
            'target_suite_id' => 0,
            'target_project_id' => 0,
            'csv_mode' => 'auto',
            'column_map' => [],
        ];

        $q = $request->getQueryParams();
        $fromQuery = [];
        if (array_key_exists('create_missing_entities', $q)) {
            $fromQuery['create_missing_entities'] = filter_var($q['create_missing_entities'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($q['on_duplicate']) && (string) $q['on_duplicate'] !== '') {
            $fromQuery['on_duplicate'] = strtolower(trim((string) $q['on_duplicate']));
        }
        if (isset($q['target_suite_id']) && (string) $q['target_suite_id'] !== '') {
            $fromQuery['target_suite_id'] = (int) $q['target_suite_id'];
        }
        if (isset($q['target_project_id']) && (string) $q['target_project_id'] !== '') {
            $fromQuery['target_project_id'] = (int) $q['target_project_id'];
        }
        if (isset($q['csv_mode']) && (string) $q['csv_mode'] !== '') {
            $fromQuery['csv_mode'] = strtolower(trim((string) $q['csv_mode']));
        }

        $fromBody = [];
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && array_key_exists('options', $parsed)) {
            $raw = $parsed['options'];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $fromBody = $decoded;
                }
            } elseif (is_array($raw)) {
                $fromBody = $raw;
            }
        }

        $merged = array_merge($defaults, $fromQuery, $fromBody);
        $on = strtolower(trim((string) $merged['on_duplicate']));
        if (!in_array($on, ['skip', 'error', 'allow'], true)) {
            $on = 'allow';
        }
        $merged['on_duplicate'] = $on;
        $merged['create_missing_entities'] = (bool) $merged['create_missing_entities'];
        $merged['target_suite_id'] = (int) $merged['target_suite_id'];
        $merged['target_project_id'] = (int) ($merged['target_project_id'] ?? 0);
        $cm = $merged['column_map'] ?? [];
        $merged['column_map'] = is_array($cm)
            ? CaseExchangeService::sanitizeColumnMapInput($cm)
            : [];
        $cmode = strtolower(trim((string) ($merged['csv_mode'] ?? 'auto')));
        $merged['csv_mode'] = in_array($cmode, ['auto', 'flat', 'project'], true) ? $cmode : 'auto';

        return $merged;
    }

    private function projectExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProjectRow(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, description FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listSuitesForProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, name FROM test_suites WHERE project_id = :p ORDER BY id ASC'
        );
        $stmt->execute(['p' => $projectId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSuiteRow(int $suiteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, project_id, name FROM test_suites WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $suiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCasesRows(int $suiteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.suite_id, c.section_id, s.name AS section_name, s.precondition AS section_precondition,
                    c.title, c.precondition, c.priority, c.status, c.created_at, c.updated_at
             FROM test_cases c
             INNER JOIN test_sections s ON s.id = c.section_id
             WHERE c.suite_id = :sid
             ORDER BY s.id ASC, c.id ASC'
        );
        $stmt->execute(['sid' => $suiteId]);
        $rows = $stmt->fetchAll();
        $caseIds = [];
        foreach ($rows as $r) {
            $caseIds[] = (int) $r['id'];
        }
        $stepsByCase = TestCaseStepsService::loadStepsBatchForCases($this->pdo, $caseIds);
        foreach ($rows as &$row) {
            $cid = (int) $row['id'];
            try {
                $row['steps'] = CaseExchangeService::normalizeStepsList($stepsByCase[$cid] ?? [], 'workspace export');
            } catch (\InvalidArgumentException) {
                $row['steps'] = [];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * @return list<array{name: string, precondition: ?string, sort_order: int, cases: list<array<string, mixed>>}>
     */
    private function fetchSectionsWithCases(int $suiteId): array
    {
        $sectionsStmt = $this->pdo->prepare(
            'SELECT id, name, precondition, sort_order
             FROM test_sections WHERE suite_id = :sid ORDER BY sort_order ASC, id ASC'
        );
        $sectionsStmt->execute(['sid' => $suiteId]);
        $sections = [];
        foreach ($sectionsStmt->fetchAll(PDO::FETCH_ASSOC) as $section) {
            $sections[(int) $section['id']] = [
                'name' => (string) $section['name'],
                'precondition' => $section['precondition'] === null || $section['precondition'] === ''
                    ? null
                    : (string) $section['precondition'],
                'sort_order' => (int) $section['sort_order'],
                'cases' => [],
            ];
        }

        foreach ($this->fetchCasesRows($suiteId) as $row) {
            $sectionId = (int) ($row['section_id'] ?? 0);
            if (!isset($sections[$sectionId])) {
                $sections[$sectionId] = [
                    'name' => $row['section_name'] ?? 'Default',
                    'precondition' => $row['section_precondition'] ?? null,
                    'sort_order' => 0,
                    'cases' => [],
                ];
            }
            $sections[$sectionId]['cases'][] = CaseExchangeService::stripCaseRowForExport($row);
        }

        return array_values($sections);
    }

    private function suiteExists(int $suiteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM test_suites WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $suiteId]);

        return (bool) $stmt->fetchColumn();
    }

    private function findProjectIdByName(string $name): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM projects WHERE name = :n LIMIT 1');
        $stmt->execute(['n' => $name]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    private function findSuiteIdByName(int $projectId, string $suiteName): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM test_suites WHERE project_id = :p AND name = :n LIMIT 1'
        );
        $stmt->execute(['p' => $projectId, 'n' => $suiteName]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

}
