<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Shared helpers for local-password accounts (invite, must-change flag).
 */
final class UserPasswordSupport
{
    private const TEMP_PASSWORD_LENGTH = 14;

    /**
     * @param array<string, mixed> $row
     */
    public static function mustChangeFromRow(array $row): bool
    {
        if (!array_key_exists('must_change_password', $row)) {
            return false;
        }
        $v = $row['must_change_password'];

        return $v === true || $v === 1 || $v === '1';
    }

    public static function generateTemporaryPassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $max = strlen($chars) - 1;
        $out = '';
        for ($i = 0; $i < self::TEMP_PASSWORD_LENGTH; ++$i) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }

    public static function validateNewPassword(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters';
        }

        return null;
    }
}
