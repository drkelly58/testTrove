<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthSettings;
use App\Mail\MailSettings;
use PDO;

/**
 * Instance-wide settings stored in DB; overrides non-secret .env values when set.
 */
final class InstanceSettingsService
{
    private const ROW_ID = 1;

    /** Env keys that may be overridden from the admin UI (never secrets). */
    private const OVERRIDE_KEYS = [
        'APP_BASE_URL',
        'MAIL_ENABLED',
        'MAIL_FROM_ADDRESS',
        'MAIL_FROM_NAME',
        'MAIL_TRANSPORT',
        'MAIL_SMTP_HOST',
        'MAIL_SMTP_PORT',
        'MAIL_SMTP_USER',
        'MAIL_SMTP_ENCRYPTION',
        'MAIL_INVITE_SUBJECT',
        'MAIL_INVITE_INTRO',
    ];

    /** @var array<string, string|null>|null */
    private ?array $cachedOverrides = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, string|null> $env
     * @return array<string, string|null>
     */
    public function mergeEnv(array $env): array
    {
        $merged = $env;
        foreach ($this->loadOverrides() as $key => $value) {
            if ($value !== null) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, string|null>
     */
    public function loadOverrides(): array
    {
        if ($this->cachedOverrides !== null) {
            return $this->cachedOverrides;
        }
        if (!$this->tableExists()) {
            $this->cachedOverrides = [];

            return $this->cachedOverrides;
        }
        $stmt = $this->pdo->prepare('SELECT settings_json FROM instance_settings WHERE id = :id');
        $stmt->execute(['id' => self::ROW_ID]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') {
            $this->cachedOverrides = [];

            return $this->cachedOverrides;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $this->cachedOverrides = [];

            return $this->cachedOverrides;
        }
        $out = [];
        foreach (self::OVERRIDE_KEYS as $key) {
            if (array_key_exists($key, $decoded)) {
                $v = $decoded[$key];
                $out[$key] = $v === null ? null : trim((string) $v);
            }
        }
        $this->cachedOverrides = $out;

        return $this->cachedOverrides;
    }

    /**
     * @param array<string, mixed> $patch camelCase or snake from API
     * @return array<string, string|null> saved overrides
     */
    public function applyPatch(array $patch): array
    {
        $this->ensureTable();
        $current = $this->loadOverrides();
        $normalized = $this->normalizePatch($patch);
        foreach ($normalized as $envKey => $value) {
            if ($value === null || $value === '') {
                unset($current[$envKey]);
            } else {
                $current[$envKey] = $value;
            }
        }
        $this->persistOverrides($current);
        $this->cachedOverrides = $current;

        return $current;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, string>
     */
    private function normalizePatch(array $patch): array
    {
        $map = [
            'app_base_url' => 'APP_BASE_URL',
            'mail_enabled' => 'MAIL_ENABLED',
            'mail_from_address' => 'MAIL_FROM_ADDRESS',
            'mail_from_name' => 'MAIL_FROM_NAME',
            'mail_transport' => 'MAIL_TRANSPORT',
            'mail_smtp_host' => 'MAIL_SMTP_HOST',
            'mail_smtp_port' => 'MAIL_SMTP_PORT',
            'mail_smtp_user' => 'MAIL_SMTP_USER',
            'mail_smtp_encryption' => 'MAIL_SMTP_ENCRYPTION',
            'mail_invite_subject' => 'MAIL_INVITE_SUBJECT',
            'mail_invite_intro' => 'MAIL_INVITE_INTRO',
        ];
        $out = [];
        foreach ($patch as $key => $value) {
            $envKey = $map[$key] ?? (in_array($key, self::OVERRIDE_KEYS, true) ? $key : null);
            if ($envKey === null) {
                continue;
            }
            if ($value === null) {
                $out[$envKey] = null;
                continue;
            }
            if ($envKey === 'MAIL_ENABLED') {
                $out[$envKey] = $this->boolToEnvFlag($value);
                continue;
            }
            if ($envKey === 'MAIL_SMTP_PORT') {
                $out[$envKey] = (string) max(1, min(65535, (int) $value));
                continue;
            }
            $out[$envKey] = trim((string) $value);
        }

        return $out;
    }

    private function boolToEnvFlag(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $v = strtolower(trim((string) $value));

        return ($v === '1' || $v === 'true' || $v === 'yes') ? '1' : '0';
    }

    /**
     * @param array<string, string|null> $overrides
     */
    private function persistOverrides(array $overrides): void
    {
        $json = json_encode($overrides, JSON_THROW_ON_ERROR);
        $driver = Database::normalizeDriver($_ENV['DB_DRIVER'] ?? 'sqlite');
        if ($driver === 'sqlite') {
            $this->pdo->prepare(
                'INSERT INTO instance_settings (id, settings_json) VALUES (:id, :json)
                 ON CONFLICT(id) DO UPDATE SET settings_json = excluded.settings_json',
            )->execute(['id' => self::ROW_ID, 'json' => $json]);

            return;
        }
        if ($driver === 'mysql') {
            $this->pdo->prepare(
                'INSERT INTO instance_settings (id, settings_json) VALUES (:id, :json)
                 ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json)',
            )->execute(['id' => self::ROW_ID, 'json' => $json]);

            return;
        }
        $this->pdo->prepare(
            'INSERT INTO instance_settings (id, settings_json) VALUES (:id, :json)
             ON CONFLICT (id) DO UPDATE SET settings_json = EXCLUDED.settings_json',
        )->execute(['id' => self::ROW_ID, 'json' => $json]);
    }

    private function ensureTable(): void
    {
        if ($this->tableExists()) {
            return;
        }
        $driver = Database::normalizeDriver($_ENV['DB_DRIVER'] ?? 'sqlite');
        self::runMigration($this->pdo, $driver);
        $this->cachedOverrides = null;
    }

    private function tableExists(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM instance_settings WHERE id = 1 LIMIT 1');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public static function runMigration(PDO $pdo, string $driver): void
    {
        try {
            $pdo->query('SELECT 1 FROM instance_settings LIMIT 1');

            return;
        } catch (\PDOException) {
            // create below
        }
        if ($driver === 'sqlite') {
            $pdo->exec(
                'CREATE TABLE instance_settings (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    settings_json TEXT NOT NULL DEFAULT \'{}\'
                )',
            );
            $pdo->exec("INSERT OR IGNORE INTO instance_settings (id, settings_json) VALUES (1, '{}')");

            return;
        }
        if ($driver === 'mysql') {
            $pdo->exec(
                'CREATE TABLE instance_settings (
                    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                    settings_json TEXT NOT NULL,
                    CONSTRAINT chk_instance_settings_singleton CHECK (id = 1)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            );
            $pdo->exec("INSERT IGNORE INTO instance_settings (id, settings_json) VALUES (1, '{}')");

            return;
        }
        $pdo->exec(
            'CREATE TABLE instance_settings (
                id SMALLINT PRIMARY KEY CHECK (id = 1),
                settings_json TEXT NOT NULL DEFAULT \'{}\'
            )',
        );
        $pdo->exec("INSERT INTO instance_settings (id, settings_json) VALUES (1, '{}') ON CONFLICT (id) DO NOTHING");
    }

    /**
     * @param array<string, string|null> $env
     * @return array<string, mixed>
     */
    public function buildAdminSnapshot(
        array $env,
        AuthSettings $authSettings,
        string $dbDriver,
    ): array {
        $effectiveEnv = $this->mergeEnv($env);
        $mail = MailSettings::fromEnv($effectiveEnv);
        $invite = InviteEmailContent::fromEnv($effectiveEnv);
        $overrides = $this->loadOverrides();

        return [
            'general' => [
                'app_base_url' => $mail->appBaseUrl(),
                'app_base_url_override' => $overrides['APP_BASE_URL'] ?? null,
                'app_env' => trim((string) ($env['APP_ENV'] ?? 'development')),
                'db_driver' => $dbDriver,
            ],
            'auth' => [
                'auth_required' => $authSettings->isAuthRequired(),
                'local_login_enabled' => $authSettings->isLocalAuthEnabled(),
                'providers' => $authSettings->listProviders(),
            ],
            'mail' => [
                'enabled' => $mail->isEnabled(),
                'transport' => $mail->transport(),
                'from_address' => $mail->fromAddress(),
                'from_name' => $mail->fromName(),
                'smtp_host' => $mail->smtpHost(),
                'smtp_port' => $mail->smtpPort(),
                'smtp_user' => $mail->smtpUser(),
                'smtp_encryption' => $mail->smtpEncryption(),
                'smtp_password_configured' => trim((string) ($env['MAIL_SMTP_PASSWORD'] ?? '')) !== '',
            ],
            'invite_email' => [
                'subject' => $invite->defaultSubject(),
                'intro' => $invite->defaultIntroTemplate(),
            ],
            'form' => $this->formFromEffective($effectiveEnv, $mail, $invite),
            'sources' => $this->sourcesForKeys($env, $overrides),
        ];
    }

    /**
     * @param array<string, string|null> $effectiveEnv
     * @return array<string, mixed>
     */
    private function formFromEffective(array $effectiveEnv, MailSettings $mail, InviteEmailContent $invite): array
    {
        $enabledRaw = strtolower(trim((string) ($effectiveEnv['MAIL_ENABLED'] ?? '')));

        return [
            'app_base_url' => $mail->appBaseUrl(),
            'mail_enabled' => $enabledRaw === '1' || $enabledRaw === 'true' || $enabledRaw === 'yes',
            'mail_from_address' => $mail->fromAddress(),
            'mail_from_name' => $mail->fromName(),
            'mail_transport' => $mail->transport(),
            'mail_smtp_host' => $mail->smtpHost(),
            'mail_smtp_port' => $mail->smtpPort(),
            'mail_smtp_user' => $mail->smtpUser(),
            'mail_smtp_encryption' => $mail->smtpEncryption(),
            'mail_invite_subject' => $invite->defaultSubject(),
            'mail_invite_intro' => $invite->defaultIntroTemplate(),
        ];
    }

    /**
     * @param array<string, string|null> $env
     * @param array<string, string|null> $overrides
     * @return array<string, string>
     */
    private function sourcesForKeys(array $env, array $overrides): array
    {
        $sources = [];
        foreach (self::OVERRIDE_KEYS as $key) {
            $camel = $this->envKeyToFormKey($key);
            if (array_key_exists($key, $overrides)) {
                $sources[$camel] = 'database';
            } elseif (trim((string) ($env[$key] ?? '')) !== '') {
                $sources[$camel] = 'environment';
            } else {
                $sources[$camel] = 'default';
            }
        }

        return $sources;
    }

    private function envKeyToFormKey(string $envKey): string
    {
        return match ($envKey) {
            'APP_BASE_URL' => 'app_base_url',
            'MAIL_ENABLED' => 'mail_enabled',
            'MAIL_FROM_ADDRESS' => 'mail_from_address',
            'MAIL_FROM_NAME' => 'mail_from_name',
            'MAIL_TRANSPORT' => 'mail_transport',
            'MAIL_SMTP_HOST' => 'mail_smtp_host',
            'MAIL_SMTP_PORT' => 'mail_smtp_port',
            'MAIL_SMTP_USER' => 'mail_smtp_user',
            'MAIL_SMTP_ENCRYPTION' => 'mail_smtp_encryption',
            'MAIL_INVITE_SUBJECT' => 'mail_invite_subject',
            'MAIL_INVITE_INTRO' => 'mail_invite_intro',
            default => strtolower($envKey),
        };
    }

    /**
     * @param array<string, mixed> $patch
     * @return string|null validation error message
     */
    public function validatePatch(array $patch): ?string
    {
        $normalized = $this->normalizePatch($patch);
        if (isset($normalized['APP_BASE_URL']) && $normalized['APP_BASE_URL'] !== null) {
            $url = rtrim($normalized['APP_BASE_URL'], '/');
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                return 'Public app URL must be a valid URL.';
            }
        }
        if (isset($normalized['MAIL_FROM_ADDRESS']) && $normalized['MAIL_FROM_ADDRESS'] !== null) {
            $addr = $normalized['MAIL_FROM_ADDRESS'];
            if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                return 'From address must be a valid email.';
            }
        }
        if (isset($normalized['MAIL_TRANSPORT']) && $normalized['MAIL_TRANSPORT'] !== null) {
            $t = strtolower($normalized['MAIL_TRANSPORT']);
            if ($t !== 'php' && $t !== 'smtp') {
                return 'Mail transport must be php or smtp.';
            }
        }
        if (isset($normalized['MAIL_INVITE_SUBJECT']) && $normalized['MAIL_INVITE_SUBJECT'] !== null) {
            if (strlen($normalized['MAIL_INVITE_SUBJECT']) > InviteEmailContent::MAX_SUBJECT_LENGTH) {
                return 'Invite subject is too long.';
            }
        }
        if (isset($normalized['MAIL_INVITE_INTRO']) && $normalized['MAIL_INVITE_INTRO'] !== null) {
            if (strlen($normalized['MAIL_INVITE_INTRO']) > InviteEmailContent::MAX_INTRO_LENGTH) {
                return 'Invite intro is too long.';
            }
        }
        if (isset($normalized['MAIL_SMTP_ENCRYPTION']) && $normalized['MAIL_SMTP_ENCRYPTION'] !== null) {
            $e = strtolower($normalized['MAIL_SMTP_ENCRYPTION']);
            if ($e !== '' && $e !== 'tls' && $e !== 'ssl') {
                return 'SMTP encryption must be tls, ssl, or empty.';
            }
        }

        return null;
    }

    public function invalidateCache(): void
    {
        $this->cachedOverrides = null;
    }
}
