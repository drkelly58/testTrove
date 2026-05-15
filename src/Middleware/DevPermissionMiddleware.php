<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\AuthSettings;
use App\Auth\DevPermissionSimulator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Activates {@see DevPermissionSimulator} from query/headers when auth is disabled. */
final class DevPermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthSettings $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        DevPermissionSimulator::reset();
        if (!$this->settings->isAuthRequired()) {
            DevPermissionSimulator::begin($request);
        }

        return $handler->handle($request);
    }
}
