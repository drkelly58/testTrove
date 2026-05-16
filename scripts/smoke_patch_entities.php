<?php

declare(strict_types=1);

/**
 * Smoke test for PATCH entity endpoints and bulk case status. Invoked as:
 *   php -r '$_ENV["DB_DRIVER"]="sqlite"; $_ENV["DB_PATH"]="/tmp/..."; include "scripts/smoke_patch_entities.php";'
 * or: php scripts/smoke_patch_entities.php (with DB_PATH in env).
 */

use App\Auth\AuthSettings;
use App\Controllers\CaseController;
use App\Controllers\ProjectController;
use App\Controllers\RunController;
use App\Controllers\SectionController;
use App\Controllers\SuiteController;
use App\Database;
use App\Mail\MailSettings;
use App\Services\AuthorizationService;
use App\Services\MailService;
use App\Services\ProjectScopeResolver;
use App\Services\RunEmailNotifier;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$dbPath = trim((string) ($_ENV['DB_PATH'] ?? getenv('DB_PATH') ?: ''));
if ($dbPath === '') {
    fwrite(STDERR, "DB_PATH must be set\n");
    exit(1);
}
$_ENV['DB_PATH'] = $dbPath;

$_ENV['DB_DRIVER'] = $_ENV['DB_DRIVER'] ?? 'sqlite';
$pdo = Database::fromEnv($root);
Database::migrate($pdo, Database::normalizeDriver($_ENV['DB_DRIVER']), $root);

$pdo->exec('DELETE FROM test_run_items');
$pdo->exec('DELETE FROM test_runs');
$pdo->exec('DELETE FROM test_case_versions');
$pdo->exec('DELETE FROM test_cases');
$pdo->exec('DELETE FROM test_sections');
$pdo->exec('DELETE FROM test_suites');
$pdo->exec('DELETE FROM projects');

$pdo->exec("INSERT INTO projects (name, description) VALUES ('P1', 'd1')");
$projectId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO test_suites (project_id, name, sort_order) VALUES ($projectId, 'S1', 0)");
$suiteId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO test_sections (suite_id, name, precondition, sort_order) VALUES ($suiteId, 'Sec1', 'pre', 0)");
$sectionId = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO test_runs (project_id, suite_id, name, run_kind, state) VALUES ($projectId, $suiteId, 'R1', 'full_suite', 'open')"
);
$runId = (int) $pdo->lastInsertId();

$pdo->exec(
    "INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status) VALUES ($suiteId, $sectionId, 'C1', NULL, 'medium', 'draft')"
);
$case1 = (int) $pdo->lastInsertId();
$pdo->exec(
    "INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status) VALUES ($suiteId, $sectionId, 'C2', NULL, 'medium', 'ready')"
);
$case2 = (int) $pdo->lastInsertId();

$reqFactory = new ServerRequestFactory();
$streamFactory = new StreamFactory();
$responseFactory = new ResponseFactory();

$pass = 0;
$fail = 0;

$assert = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    if ($ok) {
        ++$pass;
        fwrite(STDOUT, "OK {$label}\n");
    } else {
        ++$fail;
        fwrite(STDERR, "FAIL {$label}\n");
    }
};

$body = static function (string $json) use ($streamFactory) {
    return $streamFactory->createStream($json);
};

$readJson = static function ($response): array {
    $response->getBody()->rewind();

    return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
};

$authSettings = AuthSettings::fromGlobals($_ENV);
$authorization = new AuthorizationService($pdo, $authSettings);
$projectScope = new ProjectScopeResolver($pdo);
$mailSettings = MailSettings::fromEnv($_ENV);
$mailService = new MailService($mailSettings);
$runEmailNotifier = new RunEmailNotifier($pdo, $mailSettings, $mailService);

$projects = new ProjectController($pdo, $authorization, $projectScope);
$suites = new SuiteController($pdo, $authorization, $projectScope);
$sections = new SectionController($pdo, $authorization, $projectScope);
$runs = new RunController($pdo, $authorization, $projectScope, $runEmailNotifier);
$casesCtl = new CaseController($pdo, $authorization, $projectScope);

// a. Project rename + description clear -> NULL
$req = $reqFactory->createServerRequest('PATCH', "/api/projects/{$projectId}")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['name' => 'P1x', 'description' => ''], JSON_THROW_ON_ERROR)));
$res = $projects->update($req, $responseFactory->createResponse(), ['projectId' => (string) $projectId]);
$j = $readJson($res);
$assert($res->getStatusCode() === 200 && ($j['data']['name'] ?? '') === 'P1x' && ($j['data']['description'] ?? null) === null, 'project PATCH rename + null description');

// b. Suite rename
$req = $reqFactory->createServerRequest('PATCH', "/api/projects/{$projectId}/suites/{$suiteId}")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['name' => 'S1x'], JSON_THROW_ON_ERROR)));
$res = $suites->update($req, $responseFactory->createResponse(), ['projectId' => (string) $projectId, 'suiteId' => (string) $suiteId]);
$j = $readJson($res);
$assert(
    $res->getStatusCode() === 200
    && ($j['data']['name'] ?? '') === 'S1x'
    && ! array_key_exists('sort_order', $j['data'])
    && isset($j['data']['created_at']),
    'suite PATCH rename',
);

// c. Run state transitions
$req = $reqFactory->createServerRequest('PATCH', "/api/runs/{$runId}")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['name' => 'R1x', 'state' => 'locked'], JSON_THROW_ON_ERROR)));
$res = $runs->update($req, $responseFactory->createResponse(), ['runId' => (string) $runId]);
$j = $readJson($res);
$assert($res->getStatusCode() === 200 && ($j['data']['state'] ?? '') === 'locked' && ($j['data']['name'] ?? '') === 'R1x', 'run PATCH open -> locked + rename');

$req = $reqFactory->createServerRequest('PATCH', "/api/runs/{$runId}")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['state' => 'archived'], JSON_THROW_ON_ERROR)));
$res = $runs->update($req, $responseFactory->createResponse(), ['runId' => (string) $runId]);
$j = $readJson($res);
$assert($res->getStatusCode() === 200 && ($j['data']['state'] ?? '') === 'archived', 'run PATCH locked -> archived');

// d. Section update
$req = $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/sections/{$sectionId}")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['name' => 'Sec1x', 'precondition' => ''], JSON_THROW_ON_ERROR)));
$res = $sections->update($req, $responseFactory->createResponse(), ['suiteId' => (string) $suiteId, 'sectionId' => (string) $sectionId]);
$j = $readJson($res);
$assert(
    $res->getStatusCode() === 200
    && ($j['data']['name'] ?? '') === 'Sec1x'
    && ($j['data']['precondition'] ?? null) === null,
    'section PATCH rename + null precondition',
);

// e. Invalid run state -> 422
$req = $reqFactory->createServerRequest('PATCH', "/api/runs/{$runId}")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['state' => 'bogus'], JSON_THROW_ON_ERROR)));
$res = $runs->update($req, $responseFactory->createResponse(), ['runId' => (string) $runId]);
$j = $readJson($res);
$assert($res->getStatusCode() === 422 && str_contains((string) ($j['error'] ?? ''), 'state'), 'run PATCH invalid state 422');

// f. 404 missing entities
$res = $projects->update(
    $reqFactory->createServerRequest('PATCH', '/api/projects/999999')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($body('{"name":"x"}')),
    $responseFactory->createResponse(),
    ['projectId' => '999999'],
);
$assert($res->getStatusCode() === 404, 'project PATCH missing 404');

$res = $suites->update(
    $reqFactory->createServerRequest('PATCH', "/api/projects/{$projectId}/suites/999999")
        ->withHeader('Content-Type', 'application/json')
        ->withBody($body('{"name":"x"}')),
    $responseFactory->createResponse(),
    ['projectId' => (string) $projectId, 'suiteId' => '999999'],
);
$assert($res->getStatusCode() === 404, 'suite PATCH missing 404');

$res = $sections->update(
    $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/sections/999999")
        ->withHeader('Content-Type', 'application/json')
        ->withBody($body('{"name":"x"}')),
    $responseFactory->createResponse(),
    ['suiteId' => (string) $suiteId, 'sectionId' => '999999'],
);
$assert($res->getStatusCode() === 404, 'section PATCH missing 404');

$res = $runs->update(
    $reqFactory->createServerRequest('PATCH', '/api/runs/999999')
        ->withHeader('Content-Type', 'application/json')
        ->withBody($body('{"name":"x"}')),
    $responseFactory->createResponse(),
    ['runId' => '999999'],
);
$assert($res->getStatusCode() === 404, 'run PATCH missing 404');

// g. Bulk case status by case_ids
$req = $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/cases/bulk-status")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['status' => 'ready', 'case_ids' => [$case1, $case2]], JSON_THROW_ON_ERROR)));
$res = $casesCtl->bulkSetStatus($req, $responseFactory->createResponse(), ['suiteId' => (string) $suiteId]);
$j = $readJson($res);
$assert(
    $res->getStatusCode() === 200
    && ($j['data']['updated'] ?? 0) === 1
    && ($j['data']['skipped_unchanged'] ?? 0) === 1
    && ($j['data']['unknown_case_ids'] ?? null) === [],
    'bulk-status case_ids: one updated, one already ready',
);
$st1 = (string) $pdo->query('SELECT status FROM test_cases WHERE id = ' . $case1)->fetchColumn();
$st2 = (string) $pdo->query('SELECT status FROM test_cases WHERE id = ' . $case2)->fetchColumn();
$assert($st1 === 'ready' && $st2 === 'ready', 'bulk-status case_ids persisted');

// h. Bulk by section_id -> deprecated
$req = $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/cases/bulk-status")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['status' => 'deprecated', 'section_id' => $sectionId], JSON_THROW_ON_ERROR)));
$res = $casesCtl->bulkSetStatus($req, $responseFactory->createResponse(), ['suiteId' => (string) $suiteId]);
$j = $readJson($res);
$assert(
    $res->getStatusCode() === 200
    && ($j['data']['updated'] ?? 0) === 2
    && ($j['data']['skipped_unchanged'] ?? -1) === 0,
    'bulk-status section_id updates all in section',
);
$st1 = (string) $pdo->query('SELECT status FROM test_cases WHERE id = ' . $case1)->fetchColumn();
$st2 = (string) $pdo->query('SELECT status FROM test_cases WHERE id = ' . $case2)->fetchColumn();
$assert($st1 === 'deprecated' && $st2 === 'deprecated', 'bulk-status section_id persisted');

// i. Invalid bulk status -> 422
$req = $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/cases/bulk-status")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['status' => 'bogus', 'case_ids' => [$case1]], JSON_THROW_ON_ERROR)));
$res = $casesCtl->bulkSetStatus($req, $responseFactory->createResponse(), ['suiteId' => (string) $suiteId]);
$j = $readJson($res);
$assert($res->getStatusCode() === 422 && str_contains((string) ($j['error'] ?? ''), 'status'), 'bulk-status invalid status 422');

// j. All case_ids unknown -> 422
$req = $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/cases/bulk-status")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['status' => 'draft', 'case_ids' => [999997, 999998]], JSON_THROW_ON_ERROR)));
$res = $casesCtl->bulkSetStatus($req, $responseFactory->createResponse(), ['suiteId' => (string) $suiteId]);
$assert($res->getStatusCode() === 422, 'bulk-status all unknown case_ids 422');

// k. Partial unknown case_ids -> 200 with counts
$req = $reqFactory->createServerRequest('PATCH', "/api/suites/{$suiteId}/cases/bulk-status")
    ->withHeader('Content-Type', 'application/json')
    ->withBody($body(json_encode(['status' => 'draft', 'case_ids' => [$case1, 888888]], JSON_THROW_ON_ERROR)));
$res = $casesCtl->bulkSetStatus($req, $responseFactory->createResponse(), ['suiteId' => (string) $suiteId]);
$j = $readJson($res);
$assert(
    $res->getStatusCode() === 200
    && ($j['data']['updated'] ?? 0) === 1
    && ($j['data']['unknown_case_ids'] ?? []) === [888888],
    'bulk-status partial unknown case_ids',
);

fwrite(STDOUT, "SUMMARY pass={$pass} fail={$fail}\n");
exit($fail > 0 ? 1 : 0);
