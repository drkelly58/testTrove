<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\AuthSettings;
use App\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * When OAuth and/or local password login is enabled, all /api routes except /api/auth/* and /api/health require a session.
 */
final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthSettings $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->settings->isAuthRequired()) {
            return $handler->handle($request);
        }

        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($path === '/api/health' || str_starts_with($path, '/api/auth')) {
            return $handler->handle($request);
        }

        if (empty($_SESSION['user_id']) || !is_numeric((string) $_SESSION['user_id'])) {
            return JsonResponse::error('Authentication required', 401);
        }

        return $handler->handle($request);
    }
}
