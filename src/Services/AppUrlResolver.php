<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailSettings;
use Psr\Http\Message\ServerRequestInterface;

/** Public app base URL for OAuth redirects, email links, etc. */
final class AppUrlResolver
{
    public function __construct(
        private readonly MailSettings $mailSettings,
    ) {
    }

    /**
     * Scheme + host (+ port when non-default), no trailing slash.
     * Prefers APP_BASE_URL; falls back to the incoming request when provided.
     */
    public function publicBase(?ServerRequestInterface $request = null): string
    {
        $fixed = $this->mailSettings->appBaseUrl();
        if ($fixed !== '') {
            return $fixed;
        }
        if ($request === null) {
            return '';
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $x = strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']));
            if ($x === 'https' || $x === 'http') {
                $scheme = $x;
            }
        }
        $host = $uri->getHost();
        if ($host === '') {
            return '';
        }
        $port = $uri->getPort();
        $default = ($scheme === 'https') ? 443 : 80;
        $authority = $host . ($port !== null && $port !== $default ? ':' . $port : '');

        return $scheme . '://' . $authority;
    }

    public function loginUrl(?ServerRequestInterface $request = null): ?string
    {
        $base = $this->publicBase($request);
        if ($base === '') {
            return null;
        }

        return $base . '/login';
    }
}
