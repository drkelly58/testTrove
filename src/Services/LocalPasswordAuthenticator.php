<?php

declare(strict_types=1);

namespace App\Services;

use App\UserPreferences;
use PDO;

/**
 * Email + password sign-in for users without an OAuth identity (oauth_provider / oauth_subject empty).
 */
final class LocalPasswordAuthenticator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null, preferences: array<string, mixed>, must_change_password: bool}|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        $emailNorm = strtolower(trim($email));
        if ($emailNorm === '' || $password === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role, picture_url, preferences, password_hash, oauth_provider, oauth_subject, must_change_password
             FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1'
        );
        $stmt->execute(['e' => $emailNorm]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !$this->isLocalEligible($row)) {
            return null;
        }

        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        return $this->publicRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isLocalEligible(array $row): bool
    {
        $op = trim((string) ($row['oauth_provider'] ?? ''));
        $os = trim((string) ($row['oauth_subject'] ?? ''));

        return $op === '' && $os === '';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null, preferences: array<string, mixed>, must_change_password: bool}
     */
    private function publicRow(array $row): array
    {
        $pic = $row['picture_url'] ?? null;

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'picture_url' => $pic !== null && $pic !== '' ? (string) $pic : null,
            'preferences' => UserPreferences::decode(isset($row['preferences']) ? (string) $row['preferences'] : null),
            'must_change_password' => UserPasswordSupport::mustChangeFromRow($row),
        ];
    }
}
