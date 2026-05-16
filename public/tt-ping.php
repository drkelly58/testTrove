<?php

declare(strict_types=1);

/**
 * Staging/hosting probe — upload next to index.php, open /tt-ping.php, then delete.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

use App\Auth\AuthSettings;
use App\Database;
use App\JsonResponse;
use App\Middleware\CorsMiddleware;
use App\Middleware\DevPermissionMiddleware;
use App\Middleware\RequireAuthMiddleware;
use App\Middleware\SessionMiddleware;
use App\Services\LocalUserBootstrap;
use App\Services\MailService;
use App\Mail\MailSettings;
use App\Services\AuthorizationService;
use App\Services\ProjectScopeResolver;
use App\Services\RunEmailNotifier;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

$webRoot = __DIR__;
$indexRoot = dirname($webRoot);

$out = [
    'php' => PHP_VERSION,
    'web_root' => $webRoot,
    'index_project_root' => $indexRoot,
    'vendor_at_index_root' => is_file($indexRoot . '/vendor/autoload.php'),
    'vendor_in_web_root' => is_file($webRoot . '/vendor/autoload.php'),
    'htaccess_in_web_root' => is_file($webRoot . '/.htaccess'),
    'env_at_index_root' => is_readable($indexRoot . '/.env'),
    'storage_writable' => is_dir($indexRoot . '/storage') && is_writable($indexRoot . '/storage'),
    'extensions' => [
        'pdo' => extension_loaded('pdo'),
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
    ],
];

if (!$out['vendor_at_index_root']) {
    $out['layout_ok'] = false;
    $out['layout_hint'] = 'vendor/autoload.php must exist at index_project_root (parent of public_html).';
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$out['layout_ok'] = true;

require $indexRoot . '/vendor/autoload.php';

if (is_readable($indexRoot . '/.env')) {
    Dotenv\Dotenv::createImmutable($indexRoot)->safeLoad();
    $out['db_driver'] = $_ENV['DB_DRIVER'] ?? 'sqlite';
}

try {
    $driver = Database::normalizeDriver($_ENV['DB_DRIVER'] ?? 'sqlite');
    $pdo = Database::fromEnv($indexRoot);
    if ($driver === 'sqlite') {
        Database::assertSqliteIsWritable(Database::sqlitePathFromEnv($indexRoot));
    }
    Database::migrate($pdo, $driver, $indexRoot);
    $out['database_ok'] = true;
} catch (Throwable $e) {
    $out['database_ok'] = false;
    $out['database_error'] = $e->getMessage();
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $authSettings = AuthSettings::fromGlobals($_ENV);
    LocalUserBootstrap::ensureFromEnv($pdo, $authSettings);
    $mailSettings = MailSettings::fromEnv($_ENV);
    new MailService($mailSettings);
    new RunEmailNotifier($pdo, $mailSettings, new MailService($mailSettings));
    $out['services_ok'] = true;
} catch (Throwable $e) {
    $out['services_ok'] = false;
    $out['services_error'] = $e->getMessage();
}

try {
    $authSettings = AuthSettings::fromGlobals($_ENV);
    $app = AppFactory::create();
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->add(new RequireAuthMiddleware($authSettings));
    $app->add(new DevPermissionMiddleware($authSettings));
    $app->add(new SessionMiddleware($indexRoot));
    $app->add(new CorsMiddleware($_ENV['CORS_ORIGIN'] ?? null));
    $app->get('/api/health', static fn ($request, $response) => JsonResponse::encode($response, ['ok' => true]));

    $req = (new ServerRequestFactory())->createServerRequest('GET', 'https://localhost/api/health');
    $response = $app->handle($req);
    $out['slim_health_status'] = $response->getStatusCode();
    $out['slim_health_ok'] = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    $out['slim_health_body'] = (string) $response->getBody();
} catch (Throwable $e) {
    $out['slim_health_ok'] = false;
    $out['slim_health_error'] = $e->getMessage();
}

if ($out['slim_health_ok'] ?? false) {
    $out['next_step'] = 'PHP stack is fine. If /api/health still fails, Apache/nginx is not rewriting /api/* to index.php — confirm .htaccess_in_web_root is true and matches public/.htaccess from the repo.';
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
