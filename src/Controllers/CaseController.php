<?php

declare(strict_types=1);

namespace App\Controllers;

use App\IO\XlsxImportExportStub;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Services\AuthorizationService;
use App\Services\CaseExchangeService;
use App\Services\ProjectScopeResolver;
use App\Services\TestCaseStepsService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class CaseController
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
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($denied = $this->authorizeSuiteRead($suiteId)) {
            return $denied;
        }
        $rows = $this->fetchCasesRows($suiteId);
        return JsonResponse::encode($response, ['data' => $rows]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return JsonResponse::error('title is required', 422);
        }
        $precondition = isset($data['precondition']) ? (string) $data['precondition'] : null;
        $stepsRaw = $data['steps'] ?? [];
        if (!is_array($stepsRaw)) {
            return JsonResponse::error('steps must be an array', 422);
        }
        try {
            $steps = CaseExchangeService::normalizeStepsList($stepsRaw, 'create');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }
        $priority = (string) ($data['priority'] ?? 'medium');
        try {
            $status = CaseExchangeService::validateCaseStatus((string) ($data['status'] ?? 'draft'));
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }
        try {
            $sectionId = $this->resolveSectionForCreate($suiteId, $data);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status)
             VALUES (:suite_id, :section_id, :title, :precondition, :priority, :status)'
        );
        $stmt->execute([
            'suite_id' => $suiteId,
            'section_id' => $sectionId,
            'title' => $title,
            'precondition' => $precondition,
            'priority' => $priority,
            'status' => $status,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        TestCaseStepsService::replaceCaseSteps($this->pdo, $id, $steps);

        return JsonResponse::encode($response, ['data' => ['id' => $id, 'suite_id' => $suiteId, 'section_id' => $sectionId, 'title' => $title]], 201);
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($denied = $this->authorizeSuiteRead($suiteId)) {
            return $denied;
        }
        if (!$this->suiteExists($suiteId)) {
            return JsonResponse::error('suite not found', 404);
        }

        $format = strtolower(trim($request->getQueryParams()['format'] ?? 'json'));
        $scope = strtolower(trim((string) ($request->getQueryParams()['scope'] ?? 'suite')));

        if ($scope === 'project') {
            $projectId = $this->fetchProjectIdForSuite($suiteId);
            if ($projectId === null) {
                return JsonResponse::error('suite not found', 404);
            }
            $workspace = new WorkspaceExchangeController($this->pdo);
            $q = $request->getQueryParams();
            $q['project_id'] = (string) $projectId;
            $q['suite_ids'] = '';
            $q['format'] = $format;

            return $workspace->export($request->withQueryParams($q), $response);
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

        $rows = $this->fetchCasesRows($suiteId);

        if ($format === 'csv') {
            $csv = CaseExchangeService::exportCsvString($rows);
            $filename = 'suite-' . $suiteId . '-cases.csv';
            $response = $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->getBody()->write($csv);
            return $response;
        }

        if ($format === 'json') {
            $json = CaseExchangeService::exportJsonString($suiteId, $rows);
            $filename = 'suite-' . $suiteId . '-cases.json';
            $response = $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->getBody()->write($json);
            return $response;
        }

        return JsonResponse::error('Unsupported format. Use format=json, format=csv, or format=xlsx (stub).', 400);
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        if (!$this->suiteExists($suiteId)) {
            return JsonResponse::error('suite not found', 404);
        }

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

        $importOpts = $this->parseImportOptions($request);
        $onDuplicate = $importOpts['on_duplicate'];

        try {
            if ($format === 'json') {
                $trim = trim($body);
                if ($trim !== '') {
                    $decoded = json_decode($trim, true, 512, JSON_THROW_ON_ERROR);
                } else {
                    $pb = $request->getParsedBody();
                    if (!is_array($pb) || $pb === []) {
                        return JsonResponse::error('Empty JSON body', 422);
                    }
                    $decoded = $pb;
                }
                $classified = CaseExchangeService::classifyJsonImport($decoded);
                if ($classified['kind'] === 'workspace_v2') {
                    return JsonResponse::error(
                        'Workspace JSON (version 2) must be imported via POST /api/workspace/import (Data & settings in the app).',
                        400
                    );
                }
                $cases = $classified['cases'];
            } elseif ($format === 'csv') {
                $parsed = CaseExchangeService::readCsvRaw($body);
                $idx = CaseExchangeService::resolveCsvColumnIndices($parsed['headers'], $importOpts['column_map']);
                $cases = CaseExchangeService::parseFlatCsvWithIndices($parsed['headers'], $parsed['rows'], $idx);
            } else {
                return JsonResponse::error(
                    'Set format=json or format=csv (query), Content-Type text/csv or application/json, or upload file field "file" with .csv/.json name.',
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

        if ($cases === []) {
            return JsonResponse::error('No cases to import', 422);
        }

        $this->pdo->beginTransaction();
        try {
            $counts = CaseExchangeService::insertImportedCases($this->pdo, $suiteId, $cases, $onDuplicate);
            $this->pdo->commit();
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

        return JsonResponse::encode($response, [
            'data' => [
                'imported' => $counts['imported'],
                'skipped_duplicates' => $counts['skipped'],
                'suite_id' => $suiteId,
            ],
        ], 201);
    }

    /**
     * @return array{on_duplicate: string, column_map: array<string, string>}
     */
    private function parseImportOptions(ServerRequestInterface $request): array
    {
        $q = strtolower(trim((string) ($request->getQueryParams()['on_duplicate'] ?? '')));
        $parsed = $request->getParsedBody();
        $fromForm = '';
        $columnMapRaw = [];
        if (is_array($parsed) && isset($parsed['options'])) {
            $raw = $parsed['options'];
            if (is_string($raw)) {
                $dec = json_decode($raw, true);
                if (is_array($dec)) {
                    if (isset($dec['on_duplicate'])) {
                        $fromForm = strtolower(trim((string) $dec['on_duplicate']));
                    }
                    if (isset($dec['column_map']) && is_array($dec['column_map'])) {
                        $columnMapRaw = $dec['column_map'];
                    }
                }
            } elseif (is_array($raw)) {
                if (isset($raw['on_duplicate'])) {
                    $fromForm = strtolower(trim((string) $raw['on_duplicate']));
                }
                if (isset($raw['column_map']) && is_array($raw['column_map'])) {
                    $columnMapRaw = $raw['column_map'];
                }
            }
        }
        $on = $q !== '' ? $q : $fromForm;
        if ($on === '') {
            $on = 'allow';
        }
        if (!in_array($on, ['skip', 'error', 'allow'], true)) {
            $on = 'allow';
        }

        return [
            'on_duplicate' => $on,
            'column_map' => CaseExchangeService::sanitizeColumnMapInput($columnMapRaw),
        ];
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $caseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        if (!$this->caseInSuite($caseId, $suiteId)) {
            return JsonResponse::error('case not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return JsonResponse::error('title is required', 422);
        }
        $precondition = isset($data['precondition']) ? (string) $data['precondition'] : null;
        if ($precondition === '') {
            $precondition = null;
        }
        $stepsRaw = $data['steps'] ?? [];
        if (!is_array($stepsRaw)) {
            return JsonResponse::error('steps must be an array', 422);
        }
        try {
            $steps = CaseExchangeService::normalizeStepsList($stepsRaw, 'update');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }
        $priority = (string) ($data['priority'] ?? 'medium');
        try {
            $status = CaseExchangeService::validateCaseStatus((string) ($data['status'] ?? 'draft'));
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }
        $updatedAt = gmdate('c');
        $sectionId = null;
        if (array_key_exists('section_id', $data)) {
            $sectionId = (int) $data['section_id'];
            if (!$this->sectionInSuite($sectionId, $suiteId)) {
                return JsonResponse::error('section_id must belong to this suite', 422);
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE test_cases SET title = :title, precondition = :precondition,
             priority = :priority, status = :status, section_id = COALESCE(:section_id, section_id), updated_at = :updated_at
             WHERE id = :id AND suite_id = :suite_id'
        );

        try {
            $this->pdo->beginTransaction();
            $this->insertCaseVersionSnapshot($caseId, $suiteId);
            $stmt->execute([
                'title' => $title,
                'precondition' => $precondition,
                'priority' => $priority,
                'status' => $status,
                'section_id' => $sectionId,
                'updated_at' => $updatedAt,
                'id' => $caseId,
                'suite_id' => $suiteId,
            ]);
            TestCaseStepsService::replaceCaseSteps($this->pdo, $caseId, $steps);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return JsonResponse::error('Update failed: ' . $e->getMessage(), 500);
        }

        return JsonResponse::encode($response, ['data' => ['id' => $caseId, 'suite_id' => $suiteId, 'title' => $title]]);
    }

    /**
     * PATCH /api/suites/{suiteId}/cases/bulk-status
     *
     * Body (exactly one scope): { "status": "draft"|"ready"|"deprecated", "case_ids": number[] }
     * or { "status": "...", "section_id": number }
     *
     * For each matching case in the suite, stores a version snapshot then updates status only.
     */
    public function bulkSetStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        if (!$this->suiteExists($suiteId)) {
            return JsonResponse::error('suite not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        try {
            $status = CaseExchangeService::validateCaseStatus((string) ($data['status'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        $hasSection = array_key_exists('section_id', $data);
        $hasCaseIds = array_key_exists('case_ids', $data);
        if ($hasSection && $hasCaseIds) {
            return JsonResponse::error('Provide only one of case_ids or section_id', 422);
        }
        if (!$hasSection && !$hasCaseIds) {
            return JsonResponse::error('Provide case_ids or section_id', 422);
        }

        $targetCaseIds = [];
        if ($hasSection) {
            $sectionId = (int) $data['section_id'];
            if ($sectionId <= 0) {
                return JsonResponse::error('section_id must be a positive integer', 422);
            }
            if (!$this->sectionInSuite($sectionId, $suiteId)) {
                return JsonResponse::error('section_id must belong to this suite', 422);
            }
            $stmt = $this->pdo->prepare('SELECT id FROM test_cases WHERE suite_id = :sid AND section_id = :sec ORDER BY id ASC');
            $stmt->execute(['sid' => $suiteId, 'sec' => $sectionId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $targetCaseIds[] = (int) $r['id'];
            }
        } else {
            $rawIds = $data['case_ids'];
            if (!is_array($rawIds)) {
                return JsonResponse::error('case_ids must be an array', 422);
            }
            $seen = [];
            foreach ($rawIds as $id) {
                $cid = (int) $id;
                if ($cid <= 0) {
                    continue;
                }
                if (isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                $targetCaseIds[] = $cid;
            }
            if ($targetCaseIds === []) {
                return JsonResponse::error('case_ids must contain at least one valid case id', 422);
            }
        }

        $unknownCaseIds = [];
        $skippedUnchanged = 0;
        $updated = 0;
        $updatedAt = gmdate('c');
        $upd = $this->pdo->prepare(
            'UPDATE test_cases SET status = :status, updated_at = :updated_at
             WHERE id = :id AND suite_id = :suite_id'
        );

        try {
            $this->pdo->beginTransaction();
            foreach ($targetCaseIds as $caseId) {
                if (!$this->caseInSuite($caseId, $suiteId)) {
                    $unknownCaseIds[] = $caseId;
                    continue;
                }
                $cur = $this->pdo->prepare('SELECT status FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1');
                $cur->execute(['id' => $caseId, 'sid' => $suiteId]);
                $row = $cur->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    $unknownCaseIds[] = $caseId;
                    continue;
                }
                if ((string) $row['status'] === $status) {
                    ++$skippedUnchanged;
                    continue;
                }
                $this->insertCaseVersionSnapshot($caseId, $suiteId);
                $upd->execute([
                    'status' => $status,
                    'updated_at' => $updatedAt,
                    'id' => $caseId,
                    'suite_id' => $suiteId,
                ]);
                ++$updated;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return JsonResponse::error('Bulk status update failed: ' . $e->getMessage(), 500);
        }

        if (!$hasSection && $targetCaseIds !== [] && $updated === 0 && $skippedUnchanged === 0 && count($unknownCaseIds) === count($targetCaseIds)) {
            return JsonResponse::error('None of the given case_ids belong to this suite', 422);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'updated' => $updated,
                'skipped_unchanged' => $skippedUnchanged,
                'unknown_case_ids' => $unknownCaseIds,
                'suite_id' => $suiteId,
                'status' => $status,
            ],
        ]);
    }

    /**
     * Move a test case to another suite in the same project (peer suites).
     * Body: { "target_suite_id": number }
     */
    public function moveCase(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $fromSuiteId = (int) ($args['suiteId'] ?? 0);
        $caseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($fromSuiteId)) {
            return $denied;
        }
        if (!$this->caseInSuite($caseId, $fromSuiteId)) {
            return JsonResponse::error('case not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }
        $targetSuiteId = (int) ($data['target_suite_id'] ?? 0);
        if ($targetSuiteId <= 0) {
            return JsonResponse::error('target_suite_id is required', 422);
        }
        if ($targetSuiteId === $fromSuiteId) {
            return JsonResponse::error('case is already in that suite', 422);
        }

        $pFrom = $this->projectIdForSuite($fromSuiteId);
        $pTo = $this->projectIdForSuite($targetSuiteId);
        if ($pFrom === null || $pTo === null || $pFrom !== $pTo) {
            return JsonResponse::error('target suite must belong to the same project as the current suite', 422);
        }

        $syncVersions = $this->pdo->prepare(
            'UPDATE test_case_versions SET suite_id = :new_suite WHERE case_id = :cid'
        );

        try {
            $this->pdo->beginTransaction();
            $this->insertCaseVersionSnapshot($caseId, $fromSuiteId);
            $targetSectionId = $this->ensureDefaultSection($targetSuiteId);
            $upd = $this->pdo->prepare(
                'UPDATE test_cases SET suite_id = :new_suite, section_id = :new_section, updated_at = :updated_at
                 WHERE id = :id AND suite_id = :old_suite'
            );
            $upd->execute([
                'new_suite' => $targetSuiteId,
                'new_section' => $targetSectionId,
                'updated_at' => gmdate('c'),
                'id' => $caseId,
                'old_suite' => $fromSuiteId,
            ]);
            if ($upd->rowCount() === 0) {
                $this->pdo->rollBack();
                return JsonResponse::error('case not found', 404);
            }
            $syncVersions->execute(['new_suite' => $targetSuiteId, 'cid' => $caseId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return JsonResponse::error('Move failed: ' . $e->getMessage(), 500);
        }

        return JsonResponse::encode($response, ['data' => ['id' => $caseId, 'suite_id' => $targetSuiteId, 'section_id' => $targetSectionId]], 200);
    }

    /**
     * Move one step from a case to another case in the same suite (peer cases).
     * Body: { "step_index": number, "target_case_id": number, "insert_at"?: number|null }
     * insert_at: 0-based index in target steps before insert; omit or null to append.
     */
    public function moveStep(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $fromCaseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        if (!$this->caseInSuite($fromCaseId, $suiteId)) {
            return JsonResponse::error('case not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }
        $stepIndex = (int) ($data['step_index'] ?? -1);
        $targetCaseId = (int) ($data['target_case_id'] ?? 0);
        if ($targetCaseId <= 0) {
            return JsonResponse::error('target_case_id is required', 422);
        }
        if (!$this->caseInSuite($targetCaseId, $suiteId)) {
            return JsonResponse::error('target case not found in this suite', 404);
        }

        $fromRow = $this->fetchCaseRow($fromCaseId, $suiteId);
        $toRow = $this->fetchCaseRow($targetCaseId, $suiteId);
        if ($fromRow === null || $toRow === null) {
            return JsonResponse::error('case not found', 404);
        }

        try {
            $fromSteps = CaseExchangeService::normalizeStepsList(
                TestCaseStepsService::loadStepsForCase($this->pdo, $fromCaseId),
                'move from'
            );
            $toSteps = CaseExchangeService::normalizeStepsList(
                TestCaseStepsService::loadStepsForCase($this->pdo, $targetCaseId),
                'move to'
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        }

        if ($stepIndex < 0 || $stepIndex >= count($fromSteps)) {
            return JsonResponse::error('step_index out of range', 422);
        }

        $moving = $fromSteps[$stepIndex];
        array_splice($fromSteps, $stepIndex, 1);

        $insertAt = $data['insert_at'] ?? null;
        if ($insertAt === null || $insertAt === '') {
            $pos = count($toSteps);
        } else {
            $pos = max(0, min((int) $insertAt, count($toSteps)));
        }

        if ($fromCaseId === $targetCaseId) {
            // Reorder within the same case
            array_splice($fromSteps, $pos, 0, [$moving]);
            $newSteps = $fromSteps;
            $stmt = $this->pdo->prepare(
                'UPDATE test_cases SET updated_at = :u WHERE id = :id AND suite_id = :sid'
            );
            $stmt->execute(['u' => gmdate('c'), 'id' => $fromCaseId, 'sid' => $suiteId]);
            TestCaseStepsService::replaceCaseSteps($this->pdo, $fromCaseId, $newSteps);
            return JsonResponse::encode($response, ['data' => ['from_case_id' => $fromCaseId, 'target_case_id' => $targetCaseId, 'reordered' => true]]);
        }

        array_splice($toSteps, $pos, 0, [$moving]);
        $u = gmdate('c');

        try {
            $this->pdo->beginTransaction();
            $uf = $this->pdo->prepare(
                'UPDATE test_cases SET updated_at = :u WHERE id = :id AND suite_id = :sid'
            );
            $uf->execute(['u' => $u, 'id' => $fromCaseId, 'sid' => $suiteId]);
            TestCaseStepsService::replaceCaseSteps($this->pdo, $fromCaseId, $fromSteps);
            $ut = $this->pdo->prepare(
                'UPDATE test_cases SET updated_at = :u WHERE id = :id AND suite_id = :sid'
            );
            $ut->execute(['u' => $u, 'id' => $targetCaseId, 'sid' => $suiteId]);
            TestCaseStepsService::replaceCaseSteps($this->pdo, $targetCaseId, $toSteps);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return JsonResponse::error('Move step failed: ' . $e->getMessage(), 500);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'from_case_id' => $fromCaseId,
                'target_case_id' => $targetCaseId,
                'inserted_at' => $pos,
            ],
        ]);
    }

    public function listVersions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $caseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteRead($suiteId)) {
            return $denied;
        }
        if (!$this->caseInSuite($caseId, $suiteId)) {
            return JsonResponse::error('case not found', 404);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, case_id, suite_id, title, precondition, priority, status, created_at
             FROM test_case_versions WHERE case_id = :cid ORDER BY id DESC'
        );
        $stmt->execute(['cid' => $caseId]);
        $rows = $stmt->fetchAll();
        $vids = [];
        foreach ($rows as $r) {
            $vids[] = (int) $r['id'];
        }
        $stepsByVersion = TestCaseStepsService::loadStepsBatchForVersions($this->pdo, $vids);
        foreach ($rows as &$row) {
            $vid = (int) $row['id'];
            try {
                $row['steps'] = CaseExchangeService::normalizeStepsList($stepsByVersion[$vid] ?? [], 'case version list');
            } catch (\InvalidArgumentException) {
                $row['steps'] = [];
            }
        }
        unset($row);

        return JsonResponse::encode($response, ['data' => $rows]);
    }

    public function restoreVersion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $caseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        $versionId = (int) ($args['versionId'] ?? 0);
        if (!$this->caseInSuite($caseId, $suiteId)) {
            return JsonResponse::error('case not found', 404);
        }

        $vStmt = $this->pdo->prepare(
            'SELECT title, precondition, priority, status FROM test_case_versions
             WHERE id = :vid AND case_id = :cid AND suite_id = :sid LIMIT 1'
        );
        $vStmt->execute(['vid' => $versionId, 'cid' => $caseId, 'sid' => $suiteId]);
        $ver = $vStmt->fetch(PDO::FETCH_ASSOC);
        if ($ver === false) {
            return JsonResponse::error('version not found', 404);
        }

        $updatedAt = gmdate('c');
        $upd = $this->pdo->prepare(
            'UPDATE test_cases SET title = :title, precondition = :precondition,
             priority = :priority, status = :status, updated_at = :updated_at
             WHERE id = :id AND suite_id = :suite_id'
        );

        try {
            $this->pdo->beginTransaction();
            $this->insertCaseVersionSnapshot($caseId, $suiteId);
            $upd->execute([
                'title' => $ver['title'],
                'precondition' => $ver['precondition'],
                'priority' => $ver['priority'],
                'status' => $ver['status'],
                'updated_at' => $updatedAt,
                'id' => $caseId,
                'suite_id' => $suiteId,
            ]);
            $versionSteps = TestCaseStepsService::loadStepsForVersion($this->pdo, $versionId);
            TestCaseStepsService::replaceCaseSteps($this->pdo, $caseId, $versionSteps);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return JsonResponse::error('Restore failed: ' . $e->getMessage(), 500);
        }

        return JsonResponse::encode($response, ['data' => ['id' => $caseId, 'suite_id' => $suiteId, 'restored_from_version' => $versionId]]);
    }

    /**
     * Stores the current case row as a version snapshot (call inside a transaction before UPDATE).
     */
    private function insertCaseVersionSnapshot(int $caseId, int $suiteId): void
    {
        $sel = $this->pdo->prepare(
            'SELECT title, precondition, priority, status FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1'
        );
        $sel->execute(['id' => $caseId, 'sid' => $suiteId]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO test_case_versions (case_id, suite_id, title, precondition, priority, status, created_at)
             VALUES (:case_id, :suite_id, :title, :precondition, :priority, :status, :created_at)'
        );
        $ins->execute([
            'case_id' => $caseId,
            'suite_id' => $suiteId,
            'title' => $row['title'],
            'precondition' => $row['precondition'],
            'priority' => $row['priority'],
            'status' => $row['status'],
            'created_at' => gmdate('c'),
        ]);
        $versionId = (int) $this->pdo->lastInsertId();
        TestCaseStepsService::snapshotCaseStepsToVersion($this->pdo, $caseId, $versionId);
    }

    /**
     * DELETE /api/suites/{suiteId}/cases/{caseId}
     *
     * Cascades to all stored case versions and to every run item that referenced this case.
     * Pass `?dry_run=1` to fetch cascade counts without deleting.
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $caseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        if ($suiteId <= 0 || $caseId <= 0) {
            return JsonResponse::error('Invalid suite or case id', 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, suite_id, title FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1'
        );
        $stmt->execute(['id' => $caseId, 'sid' => $suiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return JsonResponse::error('case not found', 404);
        }

        $counts = $this->countCaseCascade($caseId);
        $dryRun = self::isDryRun($request);

        if ($dryRun) {
            return JsonResponse::encode($response, [
                'data' => [
                    'dry_run' => true,
                    'case' => [
                        'id' => (int) $row['id'],
                        'suite_id' => (int) $row['suite_id'],
                        'title' => (string) $row['title'],
                    ],
                    'cascade' => $counts,
                ],
            ]);
        }

        try {
            $del = $this->pdo->prepare('DELETE FROM test_cases WHERE id = :id AND suite_id = :sid');
            $del->execute(['id' => $caseId, 'sid' => $suiteId]);
        } catch (\PDOException $e) {
            error_log('DELETE /api/suites/' . $suiteId . '/cases/' . $caseId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while deleting the case.', 500);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'case' => [
                    'id' => (int) $row['id'],
                    'suite_id' => (int) $row['suite_id'],
                    'title' => (string) $row['title'],
                ],
                'cascade' => $counts,
            ],
        ]);
    }

    /**
     * @return array{versions: int, run_items: int}
     */
    private function countCaseCascade(int $caseId): array
    {
        $versions = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_case_versions WHERE case_id = :cid',
            ['cid' => $caseId]
        );
        $runItems = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items WHERE case_id = :cid',
            ['cid' => $caseId]
        );

        return ['versions' => $versions, 'run_items' => $runItems];
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
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $caseId = (int) ($args['caseId'] ?? 0);
        if ($denied = $this->authorizeSuiteWrite($suiteId)) {
            return $denied;
        }
        if (!$this->caseInSuite($caseId, $suiteId)) {
            return JsonResponse::error('case not found', 404);
        }

        $stmt = $this->pdo->prepare(
            'SELECT section_id, title, precondition, priority, status FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1'
        );
        $stmt->execute(['id' => $caseId, 'sid' => $suiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return JsonResponse::error('case not found', 404);
        }

        $title = 'Copy of ' . (string) $row['title'];
        $insert = $this->pdo->prepare(
            'INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status)
             VALUES (:suite_id, :section_id, :title, :precondition, :priority, :status)'
        );
        $insert->execute([
            'suite_id' => $suiteId,
            'section_id' => (int) $row['section_id'],
            'title' => $title,
            'precondition' => $row['precondition'],
            'priority' => $row['priority'],
            'status' => $row['status'],
        ]);
        $newId = (int) $this->pdo->lastInsertId();
        $steps = TestCaseStepsService::loadStepsForCase($this->pdo, $caseId);
        TestCaseStepsService::replaceCaseSteps($this->pdo, $newId, $steps);

        return JsonResponse::encode($response, ['data' => ['id' => $newId, 'suite_id' => $suiteId, 'title' => $title]], 201);
    }

    private function caseInSuite(int $caseId, int $suiteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1');
        $stmt->execute(['id' => $caseId, 'sid' => $suiteId]);
        return (bool) $stmt->fetchColumn();
    }

    private function projectIdForSuite(int $suiteId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT project_id FROM test_suites WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $suiteId]);
        $v = $stmt->fetchColumn();
        if ($v === false) {
            return null;
        }
        return (int) $v;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCaseRow(int $caseId, int $suiteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, suite_id, section_id, title, precondition, priority, status FROM test_cases WHERE id = :id AND suite_id = :sid LIMIT 1'
        );
        $stmt->execute(['id' => $caseId, 'sid' => $suiteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function fetchProjectIdForSuite(int $suiteId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT project_id FROM test_suites WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $suiteId]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    private function suiteExists(int $suiteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM test_suites WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $suiteId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSectionForCreate(int $suiteId, array $data): int
    {
        if (isset($data['section_id']) && (int) $data['section_id'] > 0) {
            $sectionId = (int) $data['section_id'];
            if (!$this->sectionInSuite($sectionId, $suiteId)) {
                throw new \InvalidArgumentException('section_id must belong to this suite');
            }

            return $sectionId;
        }

        $sectionName = trim((string) ($data['section_name'] ?? ''));
        if ($sectionName !== '') {
            $precondition = isset($data['section_precondition']) && trim((string) $data['section_precondition']) !== ''
                ? (string) $data['section_precondition']
                : null;

            return $this->findOrCreateSectionByName($suiteId, $sectionName, $precondition);
        }

        return $this->ensureDefaultSection($suiteId);
    }

    private function sectionInSuite(int $sectionId, int $suiteId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM test_sections WHERE id = :id AND suite_id = :sid LIMIT 1');
        $stmt->execute(['id' => $sectionId, 'sid' => $suiteId]);

        return (bool) $stmt->fetchColumn();
    }

    private function ensureDefaultSection(int $suiteId): int
    {
        return $this->findOrCreateSectionByName($suiteId, 'Default', null);
    }

    private function findOrCreateSectionByName(int $suiteId, string $name, ?string $precondition): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM test_sections WHERE suite_id = :sid AND name = :name ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute(['sid' => $suiteId, 'name' => $name]);
        $v = $stmt->fetchColumn();
        if ($v !== false) {
            return (int) $v;
        }

        $orderStmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM test_sections WHERE suite_id = :sid'
        );
        $orderStmt->execute(['sid' => $suiteId]);
        $sortOrder = $name === 'Default' ? 0 : (int) $orderStmt->fetchColumn();
        $ins = $this->pdo->prepare(
            'INSERT INTO test_sections (suite_id, name, precondition, sort_order)
             VALUES (:suite_id, :name, :precondition, :sort_order)'
        );
        $ins->execute([
            'suite_id' => $suiteId,
            'name' => $name,
            'precondition' => $precondition,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
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
                $row['steps'] = CaseExchangeService::normalizeStepsList($stepsByCase[$cid] ?? [], 'case list');
            } catch (\InvalidArgumentException) {
                $row['steps'] = [];
            }
        }
        unset($row);

        return $rows;
    }
}
