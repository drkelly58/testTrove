<?php

declare(strict_types=1);

namespace App;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Read JSON POST/PATCH bodies after Slim's BodyParsingMiddleware (which leaves the stream consumed).
 */
final class JsonRequestBody
{
    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException when body is empty or not a JSON object/array
     */
    public static function decodeAssoc(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if ($parsed instanceof \stdClass) {
            $parsed = json_decode(json_encode($parsed, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        if (is_array($parsed)) {
            return $parsed;
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            try {
                $stream->rewind();
            } catch (\Throwable) {
                // ignore; read below may still return remainder
            }
        }
        $raw = trim((string) $stream->getContents());
        if ($raw === '') {
            throw new \JsonException('Empty request body');
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('JSON root must be an object or array');
        }

        return $decoded;
    }

    /**
     * Same as {@see decodeAssoc} but returns [] when there is no body (optional JSON).
     *
     * @return array<string, mixed>
     *
     * @throws \JsonException when body is non-empty but invalid JSON or not an object/array
     */
    public static function decodeAssocOptional(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if ($parsed instanceof \stdClass) {
            $parsed = json_decode(json_encode($parsed, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        if (is_array($parsed)) {
            return $parsed;
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            try {
                $stream->rewind();
            } catch (\Throwable) {
            }
        }
        $raw = trim((string) $stream->getContents());
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('JSON root must be an object or array');
        }

        return $decoded;
    }
}
