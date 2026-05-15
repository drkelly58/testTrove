<?php

declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

$spaIndex = __DIR__ . '/app/index.html';
if (is_file($spaIndex)) {
    if (in_array($uri, ['/', '', '/index.php'], true)) {
        header('Location: /app/', true, 302);
        return true;
    }
    if ($uri === '/app' || $uri === '/app/') {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($spaIndex);
        return true;
    }
    if (str_starts_with($uri, '/app/')) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($spaIndex);
        return true;
    }
}

require __DIR__ . '/index.php';
