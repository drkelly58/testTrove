<?php

declare(strict_types=1);

namespace App\Auth;

/** Global account role (stored in users.role). */
final class UserRole
{
    public const ADMIN = 'admin';

    /** Non-admin account; project access comes from project_members. */
    public const USER = 'user';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ADMIN, self::USER];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }
}
