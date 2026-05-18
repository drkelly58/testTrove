<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ProjectRole;
use App\Database;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Services\AuthorizationService;
use App\Services\CaseExchangeService;
use App\Services\ProjectScopeResolver;
use App\Services\RunEmailNotifier;
use App\Services\TestCaseStepsService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test execution: full-suite and section-scoped snapshots; {@see run_kind} run_book reserved for custom “run books”.
 */
final class RunController
{
    use AuthorizesApiAccess;
    private const RESULTS = ['untested', 'pass', 'fail', 'blocked', 'skipped'];

    /** Impact of a failure (or unclear); extend here and in DB CHECK / schema when adding values. */
    private const SEVERITIES = ['breaking', 'ui_only', 'unclear'];

    private const RUN_STATES = ['open', 'complete', 'locked', 'archived'];

    private const MAX_SCREENSHOT_URLS = 20;

    private const MAX_RUN_ITEM_URL_CHARS = 2048;

    public function __construct(
        private readonly PDO $pdo,
        AuthorizationService $authorization,
        ProjectScopeResolver $projectScope,
        private readonly RunEmailNotifier $runEmailNotifier,
    ) {
        $this->initAuthorization($authorization, $projectScope);
    }

    /** GET /api/projects/{projectId}/runs */
    public function listByProject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($denied = $this->authorizeRunList($projectId)) {
            return $denied;
        }
        if (!$this->projectExists($projectId)) {
            return JsonResponse::error('project not found', 404);
        }

        $params = ['pid' => $projectId];
        $ownerFilter = '';
        $auth = $this->authorizationService();
        if ($auth->isAuthEnforced()) {
            $userId = $auth->requireUserId();
            if (!$auth->isGlobalAdmin($userId) && $auth->projectRole($userId, $projectId) === ProjectRole::TESTER) {
                $ownerFilter = ' AND (r.created_by_user_id = :uid_created OR r.assigned_to_user_id = :uid_assigned)';
                $params['uid_created'] = $userId;
                $params['uid_assigned'] = $userId;
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.project_id, r.suite_id, r.section_id, r.name, r.run_kind, r.state, r.created_at,
                    r.created_by_user_id,
                    r.assigned_to_user_id,
                    assignee.display_name AS assigned_to_display_name,
                    assignee.email AS assigned_to_email,
                    s.name AS suite_name,
                    sec.name AS section_name,
                    (SELECT COUNT(*) FROM test_run_items i WHERE i.run_id = r.id) AS item_count,
                    (SELECT COUNT(*) FROM test_run_items i WHERE i.run_id = r.id AND i.result = \'pass\') AS passed,
                    (SELECT COUNT(*) FROM test_run_items i WHERE i.run_id = r.id AND i.result = \'fail\') AS failed,
                    (SELECT COUNT(*) FROM test_run_items i WHERE i.run_id = r.id AND i.result = \'untested\') AS untested
             FROM test_runs r
             LEFT JOIN test_suites s ON s.id = r.suite_id
             LEFT JOIN test_sections sec ON sec.id = r.section_id
             LEFT JOIN users assignee ON assignee.id = r.assigned_to_user_id
             WHERE r.project_id = :pid' . $ownerFilter . '
             ORDER BY r.created_at ASC, r.id ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['item_count'] = (int) ($row['item_count'] ?? 0);
            $row['passed'] = (int) ($row['passed'] ?? 0);
            $row['failed'] = (int) ($row['failed'] ?? 0);
            $row['untested'] = (int) ($row['untested'] ?? 0);
            if (array_key_exists('section_id', $row) && $row['section_id'] !== null && $row['section_id'] !== '') {
                $row['section_id'] = (int) $row['section_id'];
            } else {
                $row['section_id'] = null;
            }
            if (!isset($row['section_name']) || $row['section_name'] === '') {
                $row['section_name'] = null;
            }
            $row['created_by_user_id'] = self::nullableIntColumn($row['created_by_user_id'] ?? null);
            $row['assigned_to_user_id'] = self::nullableIntColumn($row['assigned_to_user_id'] ?? null);
            $row['assigned_to_display_name'] = self::nullableStringColumn($row['assigned_to_display_name'] ?? null);
            $row['assigned_to_email'] = self::nullableStringColumn($row['assigned_to_email'] ?? null);
        }
        unset($row);

        return JsonResponse::encode($response, ['data' => $rows]);
    }

    /** POST /api/suites/{suiteId}/runs — snapshot all cases in the suite into a new run. */
    public function createForSuite(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $suiteId = (int) ($args['suiteId'] ?? 0);
        $suite = $this->fetchSuiteRow($suiteId);
        if ($suite === null) {
            return JsonResponse::error('suite not found', 404);
        }

        $projectId = (int) $suite['project_id'];
        if ($denied = $this->authorizeRunExecute($projectId)) {
            return $denied;
        }
        $suiteName = (string) $suite['name'];

        $customName = null;
        try {
            $data = JsonRequestBody::decodeAssocOptional($request);
        } catch (\JsonException) {
            return JsonResponse::error('Invalid JSON body', 422);
        }
        if (isset($data['name'])) {
            $customName = trim((string) $data['name']);
            if ($customName === '') {
                $customName = null;
            }
        }
        $assignedTo = null;
        if (array_key_exists('assigned_to_user_id', $data)) {
            $parsed = $this->parseAssignedToUserId($data['assigned_to_user_id']);
            if (isset($parsed['error'])) {
                return JsonResponse::error($parsed['error'], 422);
            }
            if ($denied = $this->authorizeRunAssign($projectId)) {
                return $denied;
            }
            if ($err = $this->validateRunAssignee($projectId, $parsed['user_id'])) {
                return $err;
            }
            $assignedTo = $parsed['user_id'];
        }

        $defaultName = 'Run: ' . $suiteName . ' · ' . gmdate('Y-m-d H:i') . ' UTC';
        $name = $customName ?? $defaultName;

        $caseStmt = $this->pdo->prepare(
            'SELECT c.id
             FROM test_cases c
             INNER JOIN test_sections s ON s.id = c.section_id
             WHERE c.suite_id = :sid
             ORDER BY s.sort_order ASC, s.id ASC, c.sort_order ASC, c.id ASC'
        );
        $caseStmt->execute(['sid' => $suiteId]);
        $caseIds = $caseStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($caseIds)) {
            $caseIds = [];
        }
        $caseIds = array_map('intval', $caseIds);

        $createdBy = $this->runCreatorUserId();

        $this->pdo->beginTransaction();
        try {
            $insRun = $this->pdo->prepare(
                'INSERT INTO test_runs (project_id, suite_id, section_id, created_by_user_id, assigned_to_user_id, name, run_kind, state)
                 VALUES (:project_id, :suite_id, NULL, :created_by, :assigned_to, :name, \'full_suite\', \'open\')'
            );
            $insRun->execute([
                'project_id' => $projectId,
                'suite_id' => $suiteId,
                'created_by' => $createdBy,
                'assigned_to' => $assignedTo,
                'name' => $name,
            ]);
            $runId = (int) $this->pdo->lastInsertId();

            $insItem = $this->pdo->prepare(
                'INSERT INTO test_run_items (run_id, case_id, result) VALUES (:run_id, :case_id, \'untested\')'
            );
            foreach ($caseIds as $cid) {
                $insItem->execute(['run_id' => $runId, 'case_id' => $cid]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $msg = 'Could not create run: ' . $e->getMessage();
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && self::looksLikeSqliteReadonlyError($e->getMessage())) {
                $root = dirname(__DIR__, 2);
                $msg .= ' Database file: ' . Database::sqlitePathFromEnv($root)
                    . '. The PHP user needs write access to this file and its directory (SQLite creates -wal / -journal beside the file).';
            }

            return JsonResponse::error($msg, 500);
        }

        if ($assignedTo !== null) {
            $this->runEmailNotifier->notifyRunAssigned($runId, $assignedTo, $this->runCreatorUserId());
        }

        return JsonResponse::encode(
            $response,
            [
                'data' => [
                    'id' => $runId,
                    'project_id' => $projectId,
                    'suite_id' => $suiteId,
                    'section_id' => null,
                    'section_name' => null,
                    'name' => $name,
                    'run_kind' => 'full_suite',
                    'state' => 'open',
                    'case_count' => count($caseIds),
                ],
            ],
            201
        );
    }

    /** POST /api/sections/{sectionId}/runs — snapshot cases in this section into a new run. */
    public function createForSection(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sectionId = (int) ($args['sectionId'] ?? 0);
        $section = $this->fetchSectionWithSuite($sectionId);
        if ($section === null) {
            return JsonResponse::error('section not found', 404);
        }

        $suiteId = (int) $section['suite_id'];
        $projectId = (int) $section['project_id'];
        if ($denied = $this->authorizeRunExecute($projectId)) {
            return $denied;
        }
        $suiteName = (string) $section['suite_name'];
        $sectionName = (string) $section['section_name'];

        $customName = null;
        try {
            $data = JsonRequestBody::decodeAssocOptional($request);
        } catch (\JsonException) {
            return JsonResponse::error('Invalid JSON body', 422);
        }
        if (isset($data['name'])) {
            $customName = trim((string) $data['name']);
            if ($customName === '') {
                $customName = null;
            }
        }
        $assignedTo = null;
        if (array_key_exists('assigned_to_user_id', $data)) {
            $parsed = $this->parseAssignedToUserId($data['assigned_to_user_id']);
            if (isset($parsed['error'])) {
                return JsonResponse::error($parsed['error'], 422);
            }
            if ($denied = $this->authorizeRunAssign($projectId)) {
                return $denied;
            }
            if ($err = $this->validateRunAssignee($projectId, $parsed['user_id'])) {
                return $err;
            }
            $assignedTo = $parsed['user_id'];
        }

        $defaultName = 'Run: ' . $suiteName . ' / ' . $sectionName . ' · ' . gmdate('Y-m-d H:i') . ' UTC';
        $name = $customName ?? $defaultName;

        $caseStmt = $this->pdo->prepare(
            'SELECT c.id
             FROM test_cases c
             WHERE c.section_id = :sec
             ORDER BY c.sort_order ASC, c.id ASC'
        );
        $caseStmt->execute(['sec' => $sectionId]);
        $caseIds = $caseStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($caseIds)) {
            $caseIds = [];
        }
        $caseIds = array_map('intval', $caseIds);

        $createdBy = $this->runCreatorUserId();

        $this->pdo->beginTransaction();
        try {
            $insRun = $this->pdo->prepare(
                'INSERT INTO test_runs (project_id, suite_id, section_id, created_by_user_id, assigned_to_user_id, name, run_kind, state)
                 VALUES (:project_id, :suite_id, :section_id, :created_by, :assigned_to, :name, \'section\', \'open\')'
            );
            $insRun->execute([
                'project_id' => $projectId,
                'suite_id' => $suiteId,
                'section_id' => $sectionId,
                'created_by' => $createdBy,
                'assigned_to' => $assignedTo,
                'name' => $name,
            ]);
            $runId = (int) $this->pdo->lastInsertId();

            $insItem = $this->pdo->prepare(
                'INSERT INTO test_run_items (run_id, case_id, result) VALUES (:run_id, :case_id, \'untested\')'
            );
            foreach ($caseIds as $cid) {
                $insItem->execute(['run_id' => $runId, 'case_id' => $cid]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $msg = 'Could not create run: ' . $e->getMessage();
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && self::looksLikeSqliteReadonlyError($e->getMessage())) {
                $root = dirname(__DIR__, 2);
                $msg .= ' Database file: ' . Database::sqlitePathFromEnv($root)
                    . '. The PHP user needs write access to this file and its directory (SQLite creates -wal / -journal beside the file).';
            }

            return JsonResponse::error($msg, 500);
        }

        if ($assignedTo !== null) {
            $this->runEmailNotifier->notifyRunAssigned($runId, $assignedTo, $this->runCreatorUserId());
        }

        return JsonResponse::encode(
            $response,
            [
                'data' => [
                    'id' => $runId,
                    'project_id' => $projectId,
                    'suite_id' => $suiteId,
                    'section_id' => $sectionId,
                    'section_name' => $sectionName,
                    'name' => $name,
                    'run_kind' => 'section',
                    'state' => 'open',
                    'case_count' => count($caseIds),
                ],
            ],
            201
        );
    }

    /** GET /api/runs/{runId} */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $runId = (int) ($args['runId'] ?? 0);
        if ($denied = $this->authorizeRunReadById($runId)) {
            return $denied;
        }
        $run = $this->fetchRunRow($runId);
        if ($run === null) {
            return JsonResponse::error('run not found', 404);
        }
        $this->syncRunAutoCompleteState($runId);
        $run = $this->fetchRunRow($runId);
        if ($run === null) {
            return JsonResponse::error('run not found', 404);
        }

        $itemsStmt = $this->pdo->prepare(
            'SELECT i.id, i.run_id, i.case_id, i.result, i.severity, i.notes, i.screenshots_json, i.video_url, i.executed_at,
                    c.section_id, s.name AS section_name,
                    c.title, c.precondition, c.priority, c.status
             FROM test_run_items i
             INNER JOIN test_cases c ON c.id = i.case_id
             INNER JOIN test_sections s ON s.id = c.section_id
             WHERE i.run_id = :rid
             ORDER BY s.sort_order ASC, s.id ASC, c.sort_order ASC, c.id ASC'
        );
        $itemsStmt->execute(['rid' => $runId]);
        $items = $itemsStmt->fetchAll();
        $caseIds = [];
        foreach ($items as $it) {
            $caseIds[] = (int) $it['case_id'];
        }
        $stepsByCase = TestCaseStepsService::loadStepsBatchForCases($this->pdo, $caseIds);
        foreach ($items as &$row) {
            $cid = (int) $row['case_id'];
            try {
                $row['steps'] = CaseExchangeService::normalizeStepsList($stepsByCase[$cid] ?? [], 'run item');
            } catch (\InvalidArgumentException) {
                $row['steps'] = [];
            }
            $row['screenshots'] = self::normalizeScreenshotsList($row['screenshots_json'] ?? null);
            unset($row['screenshots_json']);
            $vu = $row['video_url'] ?? null;
            $row['video_url'] = $vu === null || $vu === '' ? null : (string) $vu;
            $sev = strtolower(trim((string) ($row['severity'] ?? 'unclear')));
            if (!in_array($sev, self::SEVERITIES, true)) {
                $sev = 'unclear';
            }
            $row['severity'] = $sev;
        }
        unset($row);

        return JsonResponse::encode($response, [
            'data' => [
                'run' => $run,
                'items' => $items,
            ],
        ]);
    }

    /** PATCH /api/runs/{runId} */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $runId = (int) ($args['runId'] ?? 0);
        if ($denied = $this->authorizeRunExecuteById($runId)) {
            return $denied;
        }
        if ($runId <= 0) {
            return JsonResponse::error('Invalid run id', 422);
        }
        $runRow = $this->fetchRunRow($runId);
        if ($runRow === null) {
            return JsonResponse::error('run not found', 404);
        }
        $projectId = (int) $runRow['project_id'];
        $previousAssigneeId = self::nullableIntColumn($runRow['assigned_to_user_id'] ?? null);

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $sets = [];
        $params = ['id' => $runId];
        $patchedAssigneeId = null;
        $assignmentWasPatched = false;
        if (array_key_exists('assigned_to_user_id', $data)) {
            if ($denied = $this->authorizeRunAssign($projectId)) {
                return $denied;
            }
            $parsed = $this->parseAssignedToUserId($data['assigned_to_user_id']);
            if (isset($parsed['error'])) {
                return JsonResponse::error($parsed['error'], 422);
            }
            if ($err = $this->validateRunAssignee($projectId, $parsed['user_id'])) {
                return $err;
            }
            $assignmentWasPatched = true;
            $patchedAssigneeId = $parsed['user_id'];
            $sets[] = 'assigned_to_user_id = :assigned_to';
            $params['assigned_to'] = $parsed['user_id'];
        }
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return JsonResponse::error('name cannot be empty', 422);
            }
            $sets[] = 'name = :name';
            $params['name'] = $name;
        }
        if (array_key_exists('state', $data)) {
            $state = strtolower(trim((string) $data['state']));
            if (!in_array($state, self::RUN_STATES, true)) {
                return JsonResponse::error('state must be one of: ' . implode(', ', self::RUN_STATES), 422);
            }
            $sets[] = 'state = :state';
            $params['state'] = $state;
        }
        if ($sets === []) {
            return JsonResponse::error('No run fields to update', 422);
        }

        try {
            $upd = $this->pdo->prepare('UPDATE test_runs SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $upd->execute($params);
        } catch (\PDOException $e) {
            error_log('PATCH /api/runs/' . $runId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while updating the run.', 500);
        }

        $row = $this->fetchRunRow($runId);
        if ($row === null) {
            return JsonResponse::error('run not found', 404);
        }

        if ($assignmentWasPatched
            && $patchedAssigneeId !== null
            && $patchedAssigneeId !== $previousAssigneeId) {
            $actor = $this->runCreatorUserId();
            $this->runEmailNotifier->notifyRunAssigned($runId, $patchedAssigneeId, $actor);
        }

        return JsonResponse::encode($response, ['data' => $row]);
    }

    /** PATCH /api/runs/{runId}/items/{itemId} */
    public function updateItem(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $runId = (int) ($args['runId'] ?? 0);
        $itemId = (int) ($args['itemId'] ?? 0);
        if ($denied = $this->authorizeRunExecuteById($runId)) {
            return $denied;
        }

        if ($this->fetchRunRow($runId) === null) {
            return JsonResponse::error('run not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $result = strtolower(trim((string) ($data['result'] ?? '')));
        if (!in_array($result, self::RESULTS, true)) {
            return JsonResponse::error('result must be one of: ' . implode(', ', self::RESULTS), 422);
        }

        $notes = $data['notes'] ?? null;
        $notesStr = $notes === null || $notes === '' ? null : (string) $notes;

        $curStmt = $this->pdo->prepare(
            'SELECT severity, screenshots_json, video_url FROM test_run_items WHERE id = :iid AND run_id = :rid LIMIT 1'
        );
        $curStmt->execute(['iid' => $itemId, 'rid' => $runId]);
        $curRow = $curStmt->fetch(PDO::FETCH_ASSOC);
        if ($curRow === false) {
            return JsonResponse::error('run item not found', 404);
        }

        $screenshotsJson = (string) ($curRow['screenshots_json'] ?? '[]');
        if ($screenshotsJson === '') {
            $screenshotsJson = '[]';
        }
        if (array_key_exists('screenshots', $data)) {
            $parsed = $this->parseAndValidateScreenshots($data['screenshots']);
            if (isset($parsed['error'])) {
                return JsonResponse::error($parsed['error'], 422);
            }
            try {
                $screenshotsJson = json_encode($parsed['urls'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                return JsonResponse::error('screenshots could not be encoded', 500);
            }
        }

        $videoUrl = $curRow['video_url'] ?? null;
        $videoUrl = $videoUrl === '' ? null : $videoUrl;
        if (array_key_exists('video_url', $data)) {
            $v = $this->parseAndValidateVideoUrl($data['video_url']);
            if (isset($v['error'])) {
                return JsonResponse::error($v['error'], 422);
            }
            $videoUrl = $v['url'];
        }

        $currentSev = strtolower(trim((string) ($curRow['severity'] ?? 'unclear')));
        if (!in_array($currentSev, self::SEVERITIES, true)) {
            $currentSev = 'unclear';
        }

        $severity = $currentSev;
        if (array_key_exists('severity', $data)) {
            $rawSev = $data['severity'];
            if ($rawSev === null || $rawSev === '') {
                $severity = $currentSev;
            } else {
                $severity = strtolower(trim((string) $rawSev));
                if (!in_array($severity, self::SEVERITIES, true)) {
                    return JsonResponse::error(
                        'severity must be one of: ' . implode(', ', self::SEVERITIES),
                        422,
                    );
                }
            }
        }

        $executedAt = $result === 'untested' ? null : gmdate('c');

        $upd = $this->pdo->prepare(
            'UPDATE test_run_items SET result = :result, severity = :severity, notes = :notes,
                    screenshots_json = :shots, video_url = :vurl, executed_at = :ex
             WHERE id = :iid AND run_id = :rid'
        );
        $upd->execute([
            'result' => $result,
            'severity' => $severity,
            'notes' => $notesStr,
            'shots' => $screenshotsJson,
            'vurl' => $videoUrl,
            'ex' => $executedAt,
            'iid' => $itemId,
            'rid' => $runId,
        ]);

        $shotsOut = self::normalizeScreenshotsList($screenshotsJson);

        $sync = $this->syncRunAutoCompleteState($runId);
        $runState = $sync['state'];

        $executorUserId = null;
        $auth = $this->authorizationService();
        if ($auth->isAuthEnforced()) {
            $executorUserId = $auth->requireUserId();
        }
        if ($sync['became_complete']) {
            $this->runEmailNotifier->notifyAssignedRunCompleted($runId, $executorUserId);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'id' => $itemId,
                'run_id' => $runId,
                'result' => $result,
                'severity' => $severity,
                'notes' => $notesStr,
                'screenshots' => $shotsOut,
                'video_url' => $videoUrl,
                'executed_at' => $executedAt,
                'run_state' => $runState,
            ],
        ]);
    }

    /**
     * When every item is pass or fail, mark the run complete; reopen if a non-terminal result remains.
     * Does not change locked or archived runs.
     *
     * @return array{state: string, became_complete: bool}
     */
    private function syncRunAutoCompleteState(int $runId): array
    {
        $st = $this->pdo->prepare('SELECT state FROM test_runs WHERE id = :id LIMIT 1');
        $st->execute(['id' => $runId]);
        $curState = $st->fetchColumn();
        if ($curState === false) {
            return ['state' => 'open', 'became_complete' => false];
        }
        $curState = (string) $curState;
        if (!in_array($curState, ['open', 'complete'], true)) {
            return ['state' => $curState, 'became_complete' => false];
        }

        $total = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items WHERE run_id = :rid',
            ['rid' => $runId]
        );
        $pending = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items WHERE run_id = :rid AND result NOT IN (\'pass\', \'fail\')',
            ['rid' => $runId]
        );

        $newState = $curState;
        if ($total > 0 && $pending === 0) {
            $newState = 'complete';
        } elseif ($pending > 0 && $curState === 'complete') {
            $newState = 'open';
        }

        if ($newState !== $curState) {
            $upd = $this->pdo->prepare('UPDATE test_runs SET state = :state WHERE id = :id');
            $upd->execute(['state' => $newState, 'id' => $runId]);
        }

        $becameComplete = $curState === 'open' && $newState === 'complete';

        return ['state' => $newState, 'became_complete' => $becameComplete];
    }

    /**
     * DELETE /api/runs/{runId}
     *
     * Cascades to all run items for the run. Test cases and suites are untouched.
     * Pass `?dry_run=1` to fetch cascade counts without deleting.
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $runId = (int) ($args['runId'] ?? 0);
        if ($denied = $this->authorizeRunManageById($runId)) {
            return $denied;
        }
        if ($runId <= 0) {
            return JsonResponse::error('Invalid run id', 422);
        }
        $run = $this->fetchRunRow($runId);
        if ($run === null) {
            return JsonResponse::error('run not found', 404);
        }

        $itemCount = (int) $this->scalar(
            'SELECT COUNT(*) FROM test_run_items WHERE run_id = :rid',
            ['rid' => $runId]
        );
        $counts = ['run_items' => $itemCount];

        if (self::isDryRun($request)) {
            return JsonResponse::encode($response, [
                'data' => [
                    'dry_run' => true,
                    'run' => [
                        'id' => (int) $run['id'],
                        'project_id' => (int) $run['project_id'],
                        'name' => (string) $run['name'],
                    ],
                    'cascade' => $counts,
                ],
            ]);
        }

        try {
            $del = $this->pdo->prepare('DELETE FROM test_runs WHERE id = :rid');
            $del->execute(['rid' => $runId]);
        } catch (\PDOException $e) {
            error_log('DELETE /api/runs/' . $runId . ' failed: ' . $e->getMessage());
            $debug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

            return JsonResponse::error($debug ? $e->getMessage() : 'Database error while deleting the run.', 500);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'run' => [
                    'id' => (int) $run['id'],
                    'project_id' => (int) $run['project_id'],
                    'name' => (string) $run['name'],
                ],
                'cascade' => $counts,
            ],
        ]);
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

    private function projectExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
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
     * Section row joined with parent suite (project_id, suite name).
     *
     * @return array<string, mixed>|null
     */
    private function fetchSectionWithSuite(int $sectionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sec.id, sec.suite_id, sec.name AS section_name,
                    s.project_id, s.name AS suite_name
             FROM test_sections sec
             INNER JOIN test_suites s ON s.id = sec.suite_id
             WHERE sec.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $sectionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRunRow(int $runId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.project_id, r.suite_id, r.section_id, r.name, r.run_kind, r.state, r.created_at,
                    r.created_by_user_id,
                    r.assigned_to_user_id,
                    assignee.display_name AS assigned_to_display_name,
                    assignee.email AS assigned_to_email,
                    s.name AS suite_name,
                    sec.name AS section_name
             FROM test_runs r
             LEFT JOIN test_suites s ON s.id = r.suite_id
             LEFT JOIN test_sections sec ON sec.id = r.section_id
             LEFT JOIN users assignee ON assignee.id = r.assigned_to_user_id
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!isset($row['run_kind'])) {
            $row['run_kind'] = 'full_suite';
        }
        if (!array_key_exists('section_id', $row)) {
            $row['section_id'] = null;
        }
        if (!array_key_exists('section_name', $row) || $row['section_name'] === null) {
            $row['section_name'] = null;
        }
        if (!array_key_exists('suite_name', $row)) {
            $row['suite_name'] = null;
        }
        $row['created_by_user_id'] = self::nullableIntColumn($row['created_by_user_id'] ?? null);
        $row['assigned_to_user_id'] = self::nullableIntColumn($row['assigned_to_user_id'] ?? null);
        $row['assigned_to_display_name'] = self::nullableStringColumn($row['assigned_to_display_name'] ?? null);
        $row['assigned_to_email'] = self::nullableStringColumn($row['assigned_to_email'] ?? null);

        return $row;
    }

    /**
     * @return array{user_id: int|null}|array{error: string}
     */
    private function parseAssignedToUserId(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['user_id' => null];
        }
        if (is_int($raw)) {
            $id = $raw;
        } elseif (is_string($raw) && ctype_digit(trim($raw))) {
            $id = (int) trim($raw);
        } else {
            return ['error' => 'assigned_to_user_id must be a positive integer or null'];
        }
        if ($id <= 0) {
            return ['error' => 'assigned_to_user_id must be a positive integer or null'];
        }

        return ['user_id' => $id];
    }

    private function validateRunAssignee(int $projectId, ?int $userId): ?ResponseInterface
    {
        if ($userId === null) {
            return null;
        }
        $auth = $this->authorizationService();
        if ($auth->isAuthEnforced()) {
            $actorId = $auth->requireUserId();
            if ($auth->isGlobalAdmin($actorId) && $userId === $actorId) {
                return null;
            }
        }
        $st = $this->pdo->prepare(
            'SELECT role FROM project_members WHERE project_id = :pid AND user_id = :uid LIMIT 1'
        );
        $st->execute(['pid' => $projectId, 'uid' => $userId]);
        $role = $st->fetchColumn();
        if ($role === false) {
            return JsonResponse::error(
                'assignee must be a project member with the member or tester role',
                422,
            );
        }
        if ((string) $role !== ProjectRole::TESTER && (string) $role !== ProjectRole::MEMBER) {
            return JsonResponse::error(
                'assignee must be a project member with the member or tester role',
                422,
            );
        }

        return null;
    }

    private static function nullableIntColumn(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function nullableStringColumn(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function runCreatorUserId(): ?int
    {
        $auth = $this->authorizationService();
        if (!$auth->isAuthEnforced()) {
            return null;
        }

        return $auth->requireUserId();
    }

    private static function looksLikeSqliteReadonlyError(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'readonly') && str_contains($m, 'database');
    }

    /**
     * @return list<string>
     */
    private static function normalizeScreenshotsList(mixed $rawJson): array
    {
        if ($rawJson === null || $rawJson === '') {
            return [];
        }
        $decoded = json_decode((string) $rawJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * @return array{urls: list<string>}|array{error: string}
     */
    private function parseAndValidateScreenshots(mixed $payload): array
    {
        if ($payload === null) {
            return ['urls' => []];
        }
        if (!is_array($payload)) {
            return ['error' => 'screenshots must be a JSON array of URL strings'];
        }
        if (count($payload) > 128) {
            return ['error' => 'screenshots array is too large'];
        }
        $urls = [];
        foreach ($payload as $idx => $v) {
            if (!is_string($v)) {
                return ['error' => 'screenshots[' . $idx . '] must be a string'];
            }
            $t = trim($v);
            if ($t === '') {
                continue;
            }
            if (strlen($t) > self::MAX_RUN_ITEM_URL_CHARS) {
                return ['error' => 'each screenshot URL must be at most ' . self::MAX_RUN_ITEM_URL_CHARS . ' characters'];
            }
            if (!self::isAllowedHttpUrl($t)) {
                return ['error' => 'screenshots[' . $idx . '] must be a valid http(s) URL'];
            }
            $urls[] = $t;
            if (count($urls) > self::MAX_SCREENSHOT_URLS) {
                return ['error' => 'screenshots cannot list more than ' . self::MAX_SCREENSHOT_URLS . ' URLs'];
            }
        }

        return ['urls' => $urls];
    }

    /**
     * @return array{url: string|null}|array{error: string}
     */
    private function parseAndValidateVideoUrl(mixed $payload): array
    {
        if ($payload === null) {
            return ['url' => null];
        }
        if (!is_string($payload)) {
            return ['error' => 'video_url must be a string or null'];
        }
        $t = trim($payload);
        if ($t === '') {
            return ['url' => null];
        }
        if (strlen($t) > self::MAX_RUN_ITEM_URL_CHARS) {
            return ['error' => 'video_url must be at most ' . self::MAX_RUN_ITEM_URL_CHARS . ' characters'];
        }
        if (!self::isAllowedHttpUrl($t)) {
            return ['error' => 'video_url must be a valid http(s) URL or empty'];
        }

        return ['url' => $t];
    }

    private static function isAllowedHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && $host !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
