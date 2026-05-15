<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthSettings;
use App\Auth\ProjectRole;
use App\Auth\UserRole;
use PDO;

/**
 * Project-scoped RBAC. When auth is disabled, all checks pass.
 *
 * Global admin: all projects, workspace import/export, user management.
 * Project member: read/write catalog and runs.
 * Project tester: read catalog, create/execute runs.
 * Project viewer: read catalog and runs (reports when added).
 */
final class AuthorizationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthSettings $settings,
    ) {
    }

    public function isAuthEnforced(): bool
    {
        return $this->settings->isAuthRequired();
    }

    public function currentUserId(): ?int
    {
        if (empty($_SESSION['user_id']) || !is_numeric((string) $_SESSION['user_id'])) {
            return null;
        }

        return (int) $_SESSION['user_id'];
    }

    public function requireUserId(): int
    {
        $id = $this->currentUserId();
        if ($id === null || $id <= 0) {
            throw new \RuntimeException('Authenticated user required');
        }

        return $id;
    }

    public function isGlobalAdmin(int $userId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }

        $st = $this->pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $role = $st->fetchColumn();

        return $role !== false && (string) $role === UserRole::ADMIN;
    }

  /**
   * @return array<int, string> project_id => project role
   */
    public function projectRolesForUser(int $userId): array
    {
        if (!$this->isAuthEnforced()) {
            return [];
        }
        if ($this->isGlobalAdmin($userId)) {
            return [];
        }

        $st = $this->pdo->prepare(
            'SELECT project_id, role FROM project_members WHERE user_id = :uid ORDER BY project_id'
        );
        $st->execute(['uid' => $userId]);
        $map = [];
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $pid = (int) $row['project_id'];
            $role = (string) $row['role'];
            if (ProjectRole::isValid($role)) {
                $map[$pid] = $role;
            }
        }

        return $map;
    }

    public function projectRole(int $userId, int $projectId): ?string
    {
        if (!$this->isAuthEnforced()) {
            return ProjectRole::MEMBER;
        }
        if ($this->isGlobalAdmin($userId)) {
            return ProjectRole::MEMBER;
        }

        $st = $this->pdo->prepare(
            'SELECT role FROM project_members WHERE project_id = :pid AND user_id = :uid LIMIT 1'
        );
        $st->execute(['pid' => $projectId, 'uid' => $userId]);
        $role = $st->fetchColumn();
        if ($role === false) {
            return null;
        }
        $role = (string) $role;

        return ProjectRole::isValid($role) ? $role : null;
    }

    public function canAccessProject(int $userId, int $projectId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }

        return $this->projectRole($userId, $projectId) !== null;
    }

    public function canReadProject(int $userId, int $projectId): bool
    {
        return $this->canAccessProject($userId, $projectId);
    }

    public function canWriteProject(int $userId, int $projectId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);

        return $role === ProjectRole::MEMBER;
    }

    public function canExecuteRuns(int $userId, int $projectId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);

        return $role === ProjectRole::MEMBER || $role === ProjectRole::TESTER;
    }

    /** Delete runs and other run lifecycle admin (not step execution). */
    public function canManageRuns(int $userId, int $projectId): bool
    {
        return $this->canWriteProject($userId, $projectId);
    }

    public function canReadRuns(int $userId, int $projectId): bool
    {
        return $this->canAccessProject($userId, $projectId);
    }

    public function canManageProjectMembers(int $userId, int $projectId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->projectRole($userId, $projectId) === ProjectRole::MEMBER;
    }

    public function canCreateProjects(int $userId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }

        return $userId > 0;
    }

    public function canDeleteProject(int $userId, int $projectId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->projectRole($userId, $projectId) === ProjectRole::MEMBER;
    }

    public function canManageWorkspace(int $userId): bool
    {
        if (!$this->isAuthEnforced()) {
            return true;
        }

        return $this->isGlobalAdmin($userId);
    }

    public function assignProjectMember(int $projectId, int $userId, string $role): void
    {
        if (!ProjectRole::isValid($role)) {
            throw new \InvalidArgumentException('Invalid project role: ' . $role);
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $st = $this->pdo->prepare(
                'INSERT INTO project_members (project_id, user_id, role)
                 VALUES (:pid, :uid, :role)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)'
            );
            $st->execute(['pid' => $projectId, 'uid' => $userId, 'role' => $role]);

            return;
        }
        if ($driver === 'pgsql') {
            $st = $this->pdo->prepare(
                'INSERT INTO project_members (project_id, user_id, role)
                 VALUES (:pid, :uid, :role)
                 ON CONFLICT (project_id, user_id) DO UPDATE SET role = EXCLUDED.role'
            );
            $st->execute(['pid' => $projectId, 'uid' => $userId, 'role' => $role]);

            return;
        }

        $st = $this->pdo->prepare(
            'INSERT INTO project_members (project_id, user_id, role)
             VALUES (:pid, :uid, :role)
             ON CONFLICT (project_id, user_id) DO UPDATE SET role = excluded.role'
        );
        $st->execute(['pid' => $projectId, 'uid' => $userId, 'role' => $role]);
    }

    public function removeProjectMember(int $projectId, int $userId): void
    {
        $st = $this->pdo->prepare(
            'DELETE FROM project_members WHERE project_id = :pid AND user_id = :uid'
        );
        $st->execute(['pid' => $projectId, 'uid' => $userId]);
    }
}
