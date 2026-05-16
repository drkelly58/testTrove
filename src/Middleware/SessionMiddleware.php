<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Starts a PHP session (cookie-based API authentication). */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            $dir = $this->projectRoot . '/storage/sessions';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                session_save_path($dir);
            }

            $https = $this->requestIsHttps();

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');

            session_name('TTSESSID2');

            $headerSid = trim($request->getHeaderLine('X-TestTrove-Session'));
            if ($headerSid !== '' && preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $headerSid)) {
                session_id($headerSid);
            }

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        return $handler->handle($request);
    }

    /** Match cookie `Secure` to how users reach the app (APP_BASE_URL) and reverse-proxy headers. */
    private function requestIsHttps(): bool
    {
        $base = trim((string) ($_ENV['APP_BASE_URL'] ?? ''));
        if ($base !== '') {
            return str_starts_with(strtolower($base), 'https://');
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));

        return $scheme === 'https';
    }
}
