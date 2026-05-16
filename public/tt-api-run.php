<?php

declare(strict_types=1);

/**
 * Runs index.php as if the browser requested GET /api/health (delete after debugging).
 */
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/health';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require __DIR__ . '/index.php';
