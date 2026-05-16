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
use App\Services\AuthorizationService;
use App\Services\LocalUserBootstrap;
use App\Services\ProjectScopeResolver;
use App\Controllers\ProjectMemberController;
use App\Controllers\UserController;
use App\Middleware\CorsMiddleware;
use App\Middleware\DevPermissionMiddleware;
use App\Middleware\RequireAuthMiddleware;
use App\Middleware\SessionMiddleware;
use Dotenv\Dotenv;
use Slim\Error\Renderers\JsonErrorRenderer;
use Slim\Factory\AppFactory;
use Slim\Handlers\ErrorHandler;

/**
 * True when this request should hit Slim / JSON (not SPA static HTML).
 * Matches {@code RewriteCond %{REQUEST_URI} /api(?:/|$)} in {@code public/.htaccess}.
 */
function tt_request_targets_api(string $requestPath): bool
{
    return $requestPath === '/api' || str_starts_with($requestPath, '/api/');
}

/** JSON error for /api when bootstrap dies before Slim (empty 500 on shared hosts). */
function tt_api_emit_json_error(int $status, string $error, ?string $message = null): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code($status);
    $payload = ['error' => $error];
    if ($message !== null && $message !== '') {
        $payload['message'] = $message;
    }
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags) ?: '{"error":"error"}';
}

function tt_api_register_fatal_shutdown(): void
{
    register_shutdown_function(static function (): void {
        if (headers_sent()) {
            return;
        }
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $msg = isset($err['message']) ? (string) $err['message'] : 'Unknown fatal error';
        tt_api_emit_json_error(500, 'php_fatal', $msg);
    });
}

// When the host sends non-API requests to index.php (DirectoryIndex or broken .htaccess), serve the Vue SPA.
$requestPath = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if (!tt_request_targets_api($requestPath)) {
    $spaIndex = __DIR__ . '/app/index.html';
    if (is_file($spaIndex)) {
        if ($requestPath === '/' || $requestPath === '') {
            header('Location: /app/', true, 302);
            exit;
        }
        /** Do not lump /index.php with / unless we know this isn't /api rewritten into index.php. */
        if ($requestPath === '/index.php') {
            header('Location: /app/', true, 302);
            exit;
        }
        if ($requestPath === '/app' || $requestPath === '/app/' || str_starts_with($requestPath, '/app/')) {
            header('Content-Type: text/html; charset=UTF-8');
            readfile($spaIndex);
            exit;
        }
    }
}

$root = dirname(__DIR__);
$isApiRequest = tt_request_targets_api($requestPath);
$autoload = $root . '/vendor/autoload.php';
if ($isApiRequest) {
    tt_api_register_fatal_shutdown();
    if (!is_file($autoload)) {
        tt_api_emit_json_error(
            503,
            'composer_vendor_missing',
            'Run composer install in the project root (parent of the web directory).',
        );
        exit;
    }
}
require $autoload;

if (is_readable($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$appDebug = ($_ENV['APP_DEBUG'] ?? '') === '1' || strtolower((string) ($_ENV['APP_DEBUG'] ?? '')) === 'true';

try {
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
    // Shared hosts may set SCRIPT_NAME to /api/index.php; routes are registered as /api/*.
    $app->setBasePath('');
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $errorMiddleware = $app->addErrorMiddleware($appDebug, true, true);
    $errorHandler = $errorMiddleware->getDefaultErrorHandler();
    if ($errorHandler instanceof ErrorHandler) {
        // fetch() sends Accept: */* by default; without this Slim returns HTML and the SPA cannot show the real error.
        $errorHandler->setDefaultErrorRenderer('application/json', JsonErrorRenderer::class);
    }

    $authSettings = AuthSettings::fromGlobals($_ENV);
    LocalUserBootstrap::ensureFromEnv($pdo, $authSettings);
    $corsOrigin = $_ENV['CORS_ORIGIN'] ?? null;
    $app->add(new RequireAuthMiddleware($authSettings));
    $app->add(new DevPermissionMiddleware($authSettings));
    $app->add(new SessionMiddleware($root));
    $app->add(new CorsMiddleware($corsOrigin));

    $projectScope = new ProjectScopeResolver($pdo);
    $authorization = new AuthorizationService($pdo, $authSettings);

    $auth = new AuthController($pdo, $authSettings, $authorization);
    $app->get('/api/auth/session', [$auth, 'session']);
    $app->post('/api/auth/login/local', [$auth, 'loginLocal']);
    $app->get('/api/auth/login/{provider}', [$auth, 'login']);
    $app->get('/api/auth/callback/{provider}', [$auth, 'callback']);
    $app->patch('/api/auth/preferences', [$auth, 'patchPreferences']);
    $app->post('/api/auth/logout', [$auth, 'logout']);
    
    $projects = new ProjectController($pdo, $authorization, $projectScope);
    $projectMembers = new ProjectMemberController($pdo, $authorization, $projectScope);
    $suites = new SuiteController($pdo, $authorization, $projectScope);
    $sections = new SectionController($pdo, $authorization, $projectScope);
    $cases = new CaseController($pdo, $authorization, $projectScope);
    $workspace = new WorkspaceExchangeController($pdo, $authorization, $projectScope);
    $mailSettings = \App\Mail\MailSettings::fromEnv($_ENV);
    $mailService = new \App\Services\MailService($mailSettings);
    $runEmailNotifier = new \App\Services\RunEmailNotifier($pdo, $mailSettings, $mailService);
    $runs = new RunController($pdo, $authorization, $projectScope, $runEmailNotifier);
    $users = new UserController($pdo, $authorization, $projectScope);
    
    $app->get('/api/health', function ($request, $response) {
        return JsonResponse::encode($response, ['ok' => true]);
    });
    
    $app->get('/api/users', [$users, 'list']);
    $app->post('/api/users', [$users, 'create']);
    $app->patch('/api/users/{userId}', [$users, 'update']);
    $app->delete('/api/users/{userId}', [$users, 'delete']);
    
    $app->get('/api/projects', [$projects, 'list']);
    $app->post('/api/projects', [$projects, 'create']);
    $app->patch('/api/projects/{projectId}', [$projects, 'update']);
    $app->delete('/api/projects/{projectId}', [$projects, 'delete']);
    $app->get('/api/projects/{projectId}/members', [$projectMembers, 'list']);
    $app->put('/api/projects/{projectId}/members', [$projectMembers, 'upsert']);
    $app->delete('/api/projects/{projectId}/members/{userId}', [$projectMembers, 'remove']);
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

    try {
        $app->run();
    } catch (\Throwable $runError) {
        if (!$isApiRequest) {
            throw $runError;
        }
        tt_api_emit_json_error(
            500,
            'request_failed',
            $appDebug ? $runError->getMessage() : 'See server error log or set APP_DEBUG=1 in .env.',
        );
        exit;
    }
} catch (\Throwable $e) {
    if (!$isApiRequest) {
        throw $e;
    }
    /** Before Slim/error middleware handles anything: JSON for API callers (PHP/HTML fatals confuse the SPA fetch layer). */
    $payload = [
        'error' => 'application failed to initialize',
        'message' => $appDebug
            ? $e->getMessage()
            : 'Check storage/database permissions (SQLite needs a writable directory), .env DB_* settings, and server logs.',
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code(503);
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags) ?: '{"error":"application failed to initialize"}';
    exit;
}
