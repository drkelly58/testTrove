<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Simulates RBAC from URL/query when real auth is disabled (local dev only).
 *
 * Example: ?role=tester&projects=1,2,3  or  ?role=tester;projects=1,2,3
 * Global admin: ?role=admin
 */
final class DevPermissionSimulator
{
    public const DEV_USER_ID = 1;

    private static ?self $active = null;

    private function __construct(
        private readonly bool $globalAdmin,
        /** @var array<int, string> */
        private readonly array $projectRoles,
    ) {
    }

    public static function begin(?ServerRequestInterface $request): void
    {
        self::$active = null;
        if ($request === null) {
            return;
        }

        $parsed = self::parseFromRequest($request);
        if ($parsed !== null) {
            self::$active = $parsed;
        }
    }

    public static function reset(): void
    {
        self::$active = null;
    }

    public static function isActive(): bool
    {
        return self::$active !== null;
    }

    public static function isGlobalAdmin(): bool
    {
        return self::$active !== null && self::$active->globalAdmin;
    }

    /** @return array<int, string> */
    public static function projectRoles(): array
    {
        return self::$active !== null ? self::$active->projectRoles : [];
    }

    /**
     * @return array{role: string, projects: list<int>}|null
     */
    public static function describe(): ?array
    {
        if (self::$active === null) {
            return null;
        }
        if (self::$active->globalAdmin) {
            return ['role' => UserRole::ADMIN, 'projects' => []];
        }
        $ids = array_keys(self::$active->projectRoles);
        sort($ids);
        $role = self::$active->projectRoles[$ids[0] ?? 0] ?? ProjectRole::MEMBER;

        return ['role' => $role, 'projects' => $ids];
    }

    private static function parseFromRequest(ServerRequestInterface $request): ?self
    {
        $roleRaw = self::param($request, 'role');
        if ($roleRaw === '') {
            return null;
        }

        $role = strtolower($roleRaw);
        if (in_array($role, ['off', 'open', 'full', 'none', 'all'], true)) {
            return null;
        }

        if ($role === UserRole::ADMIN) {
            return new self(true, []);
        }

        if (!ProjectRole::isValid($role)) {
            return null;
        }

        $projectIds = self::parseProjectIds(self::param($request, 'projects'));
        if ($projectIds === []) {
            return null;
        }

        $map = [];
        foreach ($projectIds as $pid) {
            $map[$pid] = $role;
        }

        return new self(false, $map);
    }

    private static function param(ServerRequestInterface $request, string $name): string
    {
        $q = $request->getQueryParams();
        if (array_key_exists($name, $q)) {
            return trim((string) $q[$name]);
        }

        $header = match ($name) {
            'role' => 'X-TestTrove-Dev-Role',
            'projects' => 'X-TestTrove-Dev-Projects',
            default => '',
        };
        if ($header !== '') {
            return trim($request->getHeaderLine($header));
        }

        return '';
    }

    /**
     * @return list<int>
     */
    private static function parseProjectIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (is_numeric($part)) {
                $id = (int) $part;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
