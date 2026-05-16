<?php

declare(strict_types=1);

namespace App;

/**
 * JSON document on {@code users.preferences} (UI defaults synced when authenticated).
 */
final class UserPreferences
{
    public const KEY_DEFAULT_PROJECT_ID = 'default_project_id';
    public const KEY_RUN_OVERVIEW_SINGLE_EXPAND = 'run_overview_single_expand';
    public const KEY_THEME = 'theme';
    /** When instance mail is enabled: email me when I am assigned a test run. */
    public const KEY_EMAIL_NOTIFY_RUN_ASSIGNED = 'email_notify_run_assigned';
    /** When instance mail is enabled: email me when a run I created (assigned to someone else) is completed. */
    public const KEY_EMAIL_NOTIFY_RUN_COMPLETED = 'email_notify_run_completed';

    /**
     * @return array<string, mixed>
     */
    public static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::filter($decoded);
    }

    /**
     * @param array<string, mixed> $prefs
     */
    public static function encode(array $prefs): string
    {
        $body = json_encode(self::filter($prefs), JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return '{}';
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public static function merge(array $existing, array $patch): array
    {
        return self::filter(array_merge($existing, $patch));
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function filter(array $raw): array
    {
        $out = [];
        if (array_key_exists(self::KEY_DEFAULT_PROJECT_ID, $raw)) {
            $v = $raw[self::KEY_DEFAULT_PROJECT_ID];
            if ($v === null) {
                $out[self::KEY_DEFAULT_PROJECT_ID] = null;
            } elseif (is_numeric($v)) {
                $out[self::KEY_DEFAULT_PROJECT_ID] = (int) $v;
            }
        }
        if (array_key_exists(self::KEY_RUN_OVERVIEW_SINGLE_EXPAND, $raw)) {
            $out[self::KEY_RUN_OVERVIEW_SINGLE_EXPAND] = self::toBool($raw[self::KEY_RUN_OVERVIEW_SINGLE_EXPAND]);
        }
        if (array_key_exists(self::KEY_THEME, $raw)) {
            $theme = self::normalizeTheme($raw[self::KEY_THEME]);
            if ($theme !== null) {
                $out[self::KEY_THEME] = $theme;
            }
        }
        if (array_key_exists(self::KEY_EMAIL_NOTIFY_RUN_ASSIGNED, $raw)) {
            $out[self::KEY_EMAIL_NOTIFY_RUN_ASSIGNED] = self::toBool($raw[self::KEY_EMAIL_NOTIFY_RUN_ASSIGNED]);
        }
        if (array_key_exists(self::KEY_EMAIL_NOTIFY_RUN_COMPLETED, $raw)) {
            $out[self::KEY_EMAIL_NOTIFY_RUN_COMPLETED] = self::toBool($raw[self::KEY_EMAIL_NOTIFY_RUN_COMPLETED]);
        }

        return $out;
    }

    private static function normalizeTheme(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $s = strtolower(trim($v));
        if ($s === 'dark' || $s === 'light') {
            return $s;
        }

        return null;
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (int) $v !== 0;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if ($s === '1' || $s === 'true' || $s === 'yes' || $s === 'on') {
                return true;
            }
            if ($s === '0' || $s === 'false' || $s === 'no' || $s === 'off') {
                return false;
            }
        }

        return (bool) $v;
    }
}
