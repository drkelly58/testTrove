<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthSettings;
use App\Auth\UserRole;
use PDO;

/**
 * Creates the first local-password user from AUTH_LOCAL_BOOTSTRAP_* when none exists for that email.
 */
final class LocalUserBootstrap
{
    public static function ensureFromEnv(PDO $pdo, AuthSettings $settings): void
    {
        if (!$settings->isLocalAuthEnabled()) {
            return;
        }

        $email = $settings->localBootstrapEmail();
        $password = $settings->localBootstrapPassword();
        if ($email === '' || $password === '') {
            return;
        }

        $stmt = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1');
        $stmt->execute(['e' => $email]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) {
            if ((string) ($existing['role'] ?? '') !== UserRole::ADMIN) {
                $upd = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
                $upd->execute(['role' => UserRole::ADMIN, 'id' => (int) $existing['id']]);
            }

            return;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $displayName = $settings->localBootstrapDisplayName();

        $ins = $pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, oauth_provider, oauth_subject, picture_url)
             VALUES (:email, :ph, :dn, :role, NULL, NULL, NULL)'
        );
        $ins->execute([
            'email' => $email,
            'ph' => $hash,
            'dn' => $displayName,
            'role' => UserRole::ADMIN,
        ]);
    }
}
