<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class JsonResponse
{
    public static function encode(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $body = json_encode($data, $flags);
        if ($body === false) {
            $body = '{"error":"response encoding failed"}';
        }
        $response = $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
        $response->getBody()->write($body);
        return $response;
    }

    public static function error(string $message, int $status = 400): ResponseInterface
    {
        $r = new Response($status);
        return self::encode($r, ['error' => $message], $status);
    }
}
