<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\OAuthProfile;
use App\UserPreferences;
use PDO;
use PDOException;

/**
 * Links OAuth identities to rows in {@code users} (email unique; provider + subject unique).
 */
final class OAuthUserProvisioner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null}
     */
    public function upsert(OAuthProfile $p, ?string $bootstrapAdminEmail): array
    {
        $emailNorm = strtolower(trim($p->email));
        if ($emailNorm === '') {
            throw new \InvalidArgumentException('Your identity provider did not return an email address.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role, picture_url, oauth_provider, oauth_subject FROM users WHERE oauth_provider = :op AND oauth_subject = :os LIMIT 1'
        );
        $stmt->execute(['op' => $p->providerKey, 'os' => $p->subject]);
        $byOAuth = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($byOAuth !== false) {
            $this->updateProfile((int) $byOAuth['id'], $p, $emailNorm);

            return $this->rowById((int) $byOAuth['id']);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, oauth_provider, oauth_subject FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1'
        );
        $stmt->execute(['e' => $emailNorm]);
        $byEmail = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($byEmail !== false) {
            $op = isset($byEmail['oauth_provider']) ? trim((string) $byEmail['oauth_provider']) : '';
            $os = isset($byEmail['oauth_subject']) ? trim((string) $byEmail['oauth_subject']) : '';
            if ($op === '' && $os === '') {
                $this->linkLegacyEmailRow((int) $byEmail['id'], $p, $emailNorm);

                return $this->rowById((int) $byEmail['id']);
            }
            if ($op === $p->providerKey && $os === $p->subject) {
                $this->updateProfile((int) $byEmail['id'], $p, $emailNorm);

                return $this->rowById((int) $byEmail['id']);
            }
            throw new \RuntimeException(
                'An account with this email already exists using a different sign-in provider.'
            );
        }

        $role = 'user';
        if ($bootstrapAdminEmail !== null && $bootstrapAdminEmail !== '' && $emailNorm === $bootstrapAdminEmail) {
            $role = 'admin';
        }

        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $ins = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, oauth_provider, oauth_subject, picture_url)
             VALUES (:email, :ph, :dn, :role, :op, :os, :pic)'
        );
        try {
            $ins->execute([
                'email' => $emailNorm,
                'ph' => $hash,
                'dn' => $p->displayName,
                'role' => $role,
                'op' => $p->providerKey,
                'os' => $p->subject,
                'pic' => $p->pictureUrl,
            ]);
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw new \RuntimeException('Could not create user: email or identity already registered.', 0, $e);
            }
            throw $e;
        }

        return $this->rowById((int) $this->pdo->lastInsertId());
    }

    private function updateProfile(int $id, OAuthProfile $p, string $emailNorm): void
    {
        $u = $this->pdo->prepare(
            'UPDATE users SET email = :e, display_name = :dn, picture_url = :pic, oauth_provider = :op, oauth_subject = :os WHERE id = :id'
        );
        $u->execute([
            'e' => $emailNorm,
            'dn' => $p->displayName,
            'pic' => $p->pictureUrl,
            'op' => $p->providerKey,
            'os' => $p->subject,
            'id' => $id,
        ]);
    }

    private function linkLegacyEmailRow(int $id, OAuthProfile $p, string $emailNorm): void
    {
        $this->updateProfile($id, $p, $emailNorm);
    }

    /**
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null, preferences: array<string, mixed>}
     */
    private function rowById(int $id): array
    {
        return $this->publicUserFromId($id);
    }

    /**
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null, preferences: array<string, mixed>}|null
     */
    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, email, display_name, role, picture_url, preferences FROM users WHERE id = :id'
        );
        $st->execute(['id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }

        return $this->publicUserFromRow($r);
    }

    /**
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null, preferences: array<string, mixed>}
     */
    private function publicUserFromId(int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, email, display_name, role, picture_url, preferences FROM users WHERE id = :id'
        );
        $st->execute(['id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            throw new \RuntimeException('User row missing after OAuth upsert.');
        }

        return $this->publicUserFromRow($r);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{id: int, email: string, display_name: string, role: string, picture_url: string|null, preferences: array<string, mixed>}
     */
    private function publicUserFromRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'email' => (string) $r['email'],
            'display_name' => (string) $r['display_name'],
            'role' => (string) $r['role'],
            'picture_url' => $r['picture_url'] !== null && $r['picture_url'] !== '' ? (string) $r['picture_url'] : null,
            'preferences' => UserPreferences::decode(isset($r['preferences']) ? (string) $r['preferences'] : null),
        ];
    }
}
