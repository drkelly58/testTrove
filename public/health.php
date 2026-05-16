<?php

declare(strict_types=1);

/**
 * Lightweight GET /api/health handler at the web root (not under api/).
 * Used when .htaccess maps /api/health here; full API remains on index.php.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    echo '{"error":"composer_vendor_missing"}';
    exit;
}

require $autoload;

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

try {
    $driver = App\Database::normalizeDriver($_ENV['DB_DRIVER'] ?? 'sqlite');
    $pdo = App\Database::fromEnv($root);
    if ($driver === 'sqlite') {
        App\Database::assertSqliteIsWritable(App\Database::sqlitePathFromEnv($root));
    }
    App\Database::migrate($pdo, $driver, $root);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'error' => 'health_check_failed',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{"error":"health_check_failed"}';
    exit;
}

echo '{"ok":true}';
