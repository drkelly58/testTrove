<?php

declare(strict_types=1);

namespace App\Services;

use App\UserPreferences;
use PDO;
use RuntimeException;

final class UserPreferencesService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT preferences FROM users WHERE id = :id');
        $st->execute(['id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('User not found.');
        }

        return UserPreferences::decode(isset($row['preferences']) ? (string) $row['preferences'] : null);
    }

    /**
     * @param array<string, mixed> $patch
     *
     * @return array<string, mixed>
     */
    public function patch(int $userId, array $patch): array
    {
        $merged = UserPreferences::merge($this->get($userId), $patch);
        $st = $this->pdo->prepare('UPDATE users SET preferences = :prefs WHERE id = :id');
        $st->execute([
            'prefs' => UserPreferences::encode($merged),
            'id' => $userId,
        ]);

        return $merged;
    }
}
