<?php

declare(strict_types=1);

namespace App\Auth;

/** Per-project membership role (stored in project_members.role). */
final class ProjectRole
{
    public const MEMBER = 'member';

    public const TESTER = 'tester';

    public const VIEWER = 'viewer';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::MEMBER, self::TESTER, self::VIEWER];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }
}
