<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * CORS for cross-origin SPA dev (e.g. Vite on :5173 → API on :8080).
 * Same-origin production requests must not get ACAO headers — some browsers refuse
 * Set-Cookie on credentialed responses when unnecessary CORS headers are present.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?string $allowedOrigin)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCors(new Response(204), $request);
        }

        $response = $handler->handle($request);

        return $this->withCors($response, $request);
    }

    private function withCors(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $allowed = $this->allowedOrigin;
        if ($allowed === null || trim($allowed) === '') {
            return $response;
        }

        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return $response;
        }

        if ($this->isSameOrigin($origin, $request)) {
            return $response;
        }

        if (!$this->originIsAllowed($origin, trim($allowed))) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-TestTrove-Session')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
    }

    private function isSameOrigin(string $origin, ServerRequestInterface $request): bool
    {
        $parsed = parse_url($origin);
        if ($parsed === false || !isset($parsed['host'])) {
            return false;
        }

        $originHost = strtolower((string) $parsed['host']);
        $requestHost = strtolower($request->getUri()->getHost());

        return $originHost === $requestHost;
    }

    private function originIsAllowed(string $origin, string $allowed): bool
    {
        $originNorm = $this->normalizeOrigin($origin);
        $allowedNorm = $this->normalizeOrigin($allowed);
        if ($originNorm === $allowedNorm) {
            return true;
        }

        $base = trim((string) ($_ENV['APP_BASE_URL'] ?? ''));
        if ($base !== '' && $originNorm === $this->normalizeOrigin($base)) {
            return true;
        }

        return false;
    }

    private function normalizeOrigin(string $url): string
    {
        $url = rtrim(strtolower(trim($url)), '/');
        if (str_ends_with($url, ':80')) {
            $url = substr($url, 0, -3);
        }
        if (str_ends_with($url, ':443')) {
            $url = substr($url, 0, -4);
        }

        return $url;
    }
}
