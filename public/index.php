<?php

declare(strict_types=1);

use App\Auth\AuthSettings;
use App\Controllers\AuthController;
use App\Controllers\CaseController;
use App\Controllers\ProjectController;
use App\Controllers\RunController;
use App\Controllers\SectionController;
use App\Controllers\SuiteController;
use App\Controllers\WorkspaceExchangeController;
use App\Database;
use App\JsonResponse;
use App\Middleware\CorsMiddleware;
use App\Middleware\RequireAuthMiddleware;
use App\Middleware\SessionMiddleware;
use Dotenv\Dotenv;
use Slim\Error\Renderers\JsonErrorRenderer;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$dbDriver = Database::normalizeDriver($_ENV['DB_DRIVER'] ?? 'sqlite');
$pdo = Database::fromEnv($root);
if ($dbDriver === 'sqlite') {
    Database::assertSqliteIsWritable(Database::sqlitePathFromEnv($root));
}
Database::migrate($pdo, $dbDriver, $root);

$seed = ($_ENV['APP_SEED'] ?? '') === '1';
if ($seed) {
    $count = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO projects (name, description) VALUES ('Demo project', 'Sample data')");
        $pid = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO test_suites (project_id, name, sort_order) VALUES ($pid, 'Smoke', 0)");
        $sid = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO test_sections (suite_id, name, sort_order) VALUES ($sid, 'Default', 0)");
        $sectionId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO test_cases (suite_id, section_id, title, precondition, priority, status)
             VALUES ($sid, $sectionId, 'User can sign in', NULL, 'high', 'ready')"
        );
        $caseId = (int) $pdo->lastInsertId();
        \App\Services\TestCaseStepsService::replaceCaseSteps($pdo, $caseId, [
            ['action' => 'Open login page', 'expected' => 'Form visible'],
            ['action' => 'Submit valid credentials', 'expected' => 'Dashboard loads'],
        ]);
    }
}

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();
$appDebug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';
$errorMiddleware = $app->addErrorMiddleware($appDebug, true, true);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
if ($errorHandler instanceof ErrorHandler) {
    // fetch() sends Accept: */* by default; without this Slim returns HTML and the SPA cannot show the real error.
    $errorHandler->setDefaultErrorRenderer('application/json', JsonErrorRenderer::class);
}

$authSettings = AuthSettings::fromGlobals($_ENV);
$corsOrigin = $_ENV['CORS_ORIGIN'] ?? null;
$app->add(new RequireAuthMiddleware($authSettings));
$app->add(new SessionMiddleware($root));
$app->add(new CorsMiddleware($corsOrigin));

$auth = new AuthController($pdo, $authSettings);
$app->get('/api/auth/session', [$auth, 'session']);
$app->get('/api/auth/login/{provider}', [$auth, 'login']);
$app->get('/api/auth/callback/{provider}', [$auth, 'callback']);
$app->post('/api/auth/logout', [$auth, 'logout']);

$projects = new ProjectController($pdo);
$suites = new SuiteController($pdo);
$sections = new SectionController($pdo);
$cases = new CaseController($pdo);
$workspace = new WorkspaceExchangeController($pdo);
$runs = new RunController($pdo);

$app->get('/api/health', function ($request, $response) {
    return JsonResponse::encode($response, ['ok' => true]);
});

$app->get('/api/projects', [$projects, 'list']);
$app->post('/api/projects', [$projects, 'create']);
$app->patch('/api/projects/{projectId}', [$projects, 'update']);
$app->delete('/api/projects/{projectId}', [$projects, 'delete']);
$app->get('/api/projects/{projectId}/export', [$workspace, 'exportByProjectId']);

$app->get('/api/workspace/export', [$workspace, 'export']);
$app->post('/api/workspace/import', [$workspace, 'import']);
$app->post('/api/workspace/csv-preview', [$workspace, 'csvPreview']);

$app->get('/api/projects/{projectId}/suites', [$suites, 'list']);
$app->get('/api/projects/{projectId}/runs', [$runs, 'listByProject']);
$app->post('/api/projects/{projectId}/suites', [$suites, 'create']);
$app->post('/api/projects/{projectId}/suites/{suiteId}/duplicate', [$suites, 'duplicate']);
$app->patch('/api/projects/{projectId}/suites/{suiteId}', [$suites, 'update']);
$app->delete('/api/projects/{projectId}/suites/{suiteId}', [$suites, 'delete']);

$app->get('/api/suites/{suiteId}/sections', [$sections, 'list']);
$app->post('/api/suites/{suiteId}/sections', [$sections, 'create']);
$app->patch('/api/suites/{suiteId}/sections/{sectionId}', [$sections, 'update']);
$app->delete('/api/suites/{suiteId}/sections/{sectionId}', [$sections, 'delete']);

$app->get('/api/suites/{suiteId}/cases/export', [$cases, 'export']);
$app->post('/api/suites/{suiteId}/cases/import', [$cases, 'import']);
$app->patch('/api/suites/{suiteId}/cases/bulk-status', [$cases, 'bulkSetStatus']);
$app->post('/api/suites/{suiteId}/cases/{caseId}/move', [$cases, 'moveCase']);
$app->post('/api/suites/{suiteId}/cases/{caseId}/steps/move', [$cases, 'moveStep']);
$app->get('/api/suites/{suiteId}/cases/{caseId}/versions', [$cases, 'listVersions']);
$app->post('/api/suites/{suiteId}/cases/{caseId}/versions/{versionId}/restore', [$cases, 'restoreVersion']);
$app->patch('/api/suites/{suiteId}/cases/{caseId}', [$cases, 'update']);
$app->delete('/api/suites/{suiteId}/cases/{caseId}', [$cases, 'delete']);
$app->post('/api/suites/{suiteId}/cases/{caseId}/duplicate', [$cases, 'duplicate']);
$app->get('/api/suites/{suiteId}/cases', [$cases, 'list']);
$app->post('/api/suites/{suiteId}/cases', [$cases, 'create']);
$app->post('/api/suites/{suiteId}/runs', [$runs, 'createForSuite']);
$app->post('/api/sections/{sectionId}/runs', [$runs, 'createForSection']);

$app->get('/api/runs/{runId}', [$runs, 'get']);
$app->patch('/api/runs/{runId}', [$runs, 'update']);
$app->delete('/api/runs/{runId}', [$runs, 'delete']);
$app->patch('/api/runs/{runId}/items/{itemId}', [$runs, 'updateItem']);

$app->run();
