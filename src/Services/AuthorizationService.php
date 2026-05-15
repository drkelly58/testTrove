<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthSettings;
use App\Auth\DevPermissionSimulator;
use App\Auth\ProjectRole;
use App\Auth\UserRole;
use PDO;

/**
 * Project-scoped RBAC. When auth is disabled, all checks pass unless
 * {@see DevPermissionSimulator} is active (dev URL params).
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

    /** Auth off and no dev role simulation — full access. */
    public function isOpenAccess(): bool
    {
        return !$this->isAuthEnforced() && !DevPermissionSimulator::isActive();
    }

    /** Auth off with ?role=…&projects=… simulation. */
    public function isDevMode(): bool
    {
        return !$this->isAuthEnforced() && DevPermissionSimulator::isActive();
    }

    public function currentUserId(): ?int
    {
        if ($this->isDevMode()) {
            return DevPermissionSimulator::DEV_USER_ID;
        }
        if (empty($_SESSION['user_id']) || !is_numeric((string) $_SESSION['user_id'])) {
            return null;
        }

        return (int) $_SESSION['user_id'];
    }

    public function requireUserId(): int
    {
        if ($this->isDevMode()) {
            return DevPermissionSimulator::DEV_USER_ID;
        }
        $id = $this->currentUserId();
        if ($id === null || $id <= 0) {
            throw new \RuntimeException('Authenticated user required');
        }

        return $id;
    }

    public function isGlobalAdmin(int $userId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isDevMode()) {
            return DevPermissionSimulator::isGlobalAdmin();
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
        if ($this->isOpenAccess()) {
            return [];
        }
        if ($this->isDevMode()) {
            return DevPermissionSimulator::projectRoles();
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
        if ($this->isOpenAccess()) {
            return ProjectRole::MEMBER;
        }
        if ($this->isDevMode()) {
            if (DevPermissionSimulator::isGlobalAdmin()) {
                return ProjectRole::MEMBER;
            }

            return DevPermissionSimulator::projectRoles()[$projectId] ?? null;
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
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->projectRole($userId, $projectId) !== null;
    }

    public function canReadCatalog(int $userId, int $projectId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);

        return $role === ProjectRole::MEMBER || $role === ProjectRole::TESTER;
    }

    /** @deprecated Use {@see canReadCatalog}. */
    public function canReadProject(int $userId, int $projectId): bool
    {
        return $this->canReadCatalog($userId, $projectId);
    }

    public function canWriteProject(int $userId, int $projectId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);

        return $role === ProjectRole::MEMBER;
    }

    public function canListRunsInProject(int $userId, int $projectId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);

        return $role !== null;
    }

    public function canReadRun(int $userId, int $projectId, int $runId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);
        if ($role === null) {
            return false;
        }
        if ($role === ProjectRole::MEMBER || $role === ProjectRole::VIEWER) {
            return $this->runBelongsToProject($runId, $projectId);
        }
        if ($role === ProjectRole::TESTER) {
            return $this->runAccessibleByTester($runId, $projectId, $userId);
        }

        return false;
    }

    /** @deprecated Use {@see canListRunsInProject} or {@see canReadRun}. */
    public function canReadRuns(int $userId, int $projectId): bool
    {
        return $this->canListRunsInProject($userId, $projectId);
    }

    public function canExecuteRuns(int $userId, int $projectId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);

        return $role === ProjectRole::MEMBER || $role === ProjectRole::TESTER;
    }

    public function canExecuteRun(int $userId, int $projectId, int $runId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if (!$this->canExecuteRuns($userId, $projectId)) {
            return false;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }
        $role = $this->projectRole($userId, $projectId);
        if ($role === ProjectRole::MEMBER) {
            return $this->runBelongsToProject($runId, $projectId);
        }
        if ($role === ProjectRole::TESTER) {
            return $this->runAccessibleByTester($runId, $projectId, $userId);
        }

        return false;
    }

    public function canAssignRuns(int $userId, int $projectId): bool
    {
        return $this->canManageRuns($userId, $projectId);
    }

    /** Whether the user has at least one open run delegated to them. */
    public function hasOpenRunsAssignedToUser(int $userId): bool
    {
        if ($this->isOpenAccess()) {
            return false;
        }
        $st = $this->pdo->prepare(
            'SELECT 1 FROM test_runs WHERE assigned_to_user_id = :uid AND state = \'open\' LIMIT 1'
        );
        $st->execute(['uid' => $userId]);

        return (bool) $st->fetchColumn();
    }

    public function isTesterOnAnyProject(int $userId): bool
    {
        if ($this->isOpenAccess()) {
            return false;
        }
        if ($this->isGlobalAdmin($userId)) {
            return false;
        }
        foreach ($this->projectRolesForUser($userId) as $role) {
            if ($role === ProjectRole::TESTER) {
                return true;
            }
        }

        return false;
    }

    public function canManageRuns(int $userId, int $projectId): bool
    {
        return $this->canWriteProject($userId, $projectId);
    }

    public function canManageProjectMembers(int $userId, int $projectId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->projectRole($userId, $projectId) === ProjectRole::MEMBER;
    }

    public function canManageUsers(int $userId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }

        return $this->isGlobalAdmin($userId);
    }

    public function canCreateProjects(int $userId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        $roles = $this->projectRolesForUser($userId);
        if ($roles === []) {
            return true;
        }

        foreach ($roles as $role) {
            if ($role === ProjectRole::MEMBER) {
                return true;
            }
        }

        return false;
    }

    public function canDeleteProject(int $userId, int $projectId): bool
    {
        if ($this->isOpenAccess()) {
            return true;
        }
        if ($this->isGlobalAdmin($userId)) {
            return true;
        }

        return $this->projectRole($userId, $projectId) === ProjectRole::MEMBER;
    }

    public function canManageWorkspace(int $userId): bool
    {
        if ($this->isOpenAccess()) {
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

    private function runBelongsToProject(int $runId, int $projectId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM test_runs WHERE id = :rid AND project_id = :pid LIMIT 1'
        );
        $st->execute(['rid' => $runId, 'pid' => $projectId]);

        return (bool) $st->fetchColumn();
    }

    private function runAccessibleByTester(int $runId, int $projectId, int $userId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT created_by_user_id, assigned_to_user_id
             FROM test_runs WHERE id = :rid AND project_id = :pid LIMIT 1'
        );
        $st->execute(['rid' => $runId, 'pid' => $projectId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $createdBy = $row['created_by_user_id'] ?? null;
        if ($createdBy !== null && $createdBy !== '' && (int) $createdBy === $userId) {
            return true;
        }
        $assignedTo = $row['assigned_to_user_id'] ?? null;
        if ($assignedTo !== null && $assignedTo !== '' && (int) $assignedTo === $userId) {
            return true;
        }

        return false;
    }
}
