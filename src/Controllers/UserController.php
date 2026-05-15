<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ProjectRole;
use App\Auth\UserRole;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Services\AuthorizationService;
use App\Services\ProjectScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Global user accounts (admin only). Project membership is managed separately. */
final class UserController
{
    use AuthorizesApiAccess;

    public function __construct(
        private readonly PDO $pdo,
        AuthorizationService $authorization,
        ProjectScopeResolver $projectScope,
    ) {
        $this->initAuthorization($authorization, $projectScope);
    }

    /** GET /api/users */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        $stmt = $this->pdo->query(
            'SELECT id, email, display_name, role, created_at FROM users ORDER BY display_name, email'
        );
        $membershipsByUser = $this->projectMembershipsByUser();
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['id'];
            $rows[] = [
                'id' => $userId,
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
                'created_at' => $row['created_at'],
                'project_memberships' => $membershipsByUser[$userId] ?? [],
            ];
        }

        return JsonResponse::encode($response, ['data' => $rows]);
    }

    /** POST /api/users — body: { email, password, display_name, role? } */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $role = trim((string) ($data['role'] ?? UserRole::USER));
        if ($email === '' || $password === '' || $displayName === '') {
            return JsonResponse::error('email, password, and display_name are required', 422);
        }
        if (!UserRole::isValid($role)) {
            return JsonResponse::error('role must be admin or user', 422);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $ins = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, role) VALUES (:email, :ph, :dn, :role)'
        );
        try {
            $ins->execute(['email' => $email, 'ph' => $hash, 'dn' => $displayName, 'role' => $role]);
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return JsonResponse::error('email already registered', 409);
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0 && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $id = (int) $this->pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        }

        if ($role === UserRole::USER && array_key_exists('project_memberships', $data)) {
            $this->syncProjectMemberships($id, $data['project_memberships']);
        }

        $row = $this->fetchUserRow($id);
        if ($row === null) {
            return JsonResponse::error('user not found', 404);
        }

        return JsonResponse::encode($response, ['data' => $row], 201);
    }

    /** PATCH /api/users/{userId} */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        $userId = (int) ($args['userId'] ?? 0);
        if ($userId <= 0) {
            return JsonResponse::error('Invalid user id', 422);
        }
        if (!$this->userExists($userId)) {
            return JsonResponse::error('user not found', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $sets = [];
        $params = ['id' => $userId];
        if (array_key_exists('email', $data)) {
            $email = strtolower(trim((string) $data['email']));
            if ($email === '') {
                return JsonResponse::error('email cannot be empty', 422);
            }
            $sets[] = 'email = :email';
            $params['email'] = $email;
        }
        if (array_key_exists('display_name', $data)) {
            $dn = trim((string) $data['display_name']);
            if ($dn === '') {
                return JsonResponse::error('display_name cannot be empty', 422);
            }
            $sets[] = 'display_name = :dn';
            $params['dn'] = $dn;
        }
        if (array_key_exists('role', $data)) {
            $role = trim((string) $data['role']);
            if (!UserRole::isValid($role)) {
                return JsonResponse::error('role must be admin or user', 422);
            }
            $sets[] = 'role = :role';
            $params['role'] = $role;
        }
        if (array_key_exists('password', $data)) {
            $password = (string) $data['password'];
            if ($password === '') {
                return JsonResponse::error('password cannot be empty', 422);
            }
            $sets[] = 'password_hash = :ph';
            $params['ph'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $syncMemberships = array_key_exists('project_memberships', $data);
        $effectiveRole = array_key_exists('role', $params)
            ? (string) $params['role']
            : $this->fetchUserGlobalRole($userId);
        if ($sets === [] && !$syncMemberships) {
            return JsonResponse::error('No user fields to update', 422);
        }

        if ($sets !== []) {
            try {
                $upd = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
                $upd->execute($params);
            } catch (\PDOException $e) {
                if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    return JsonResponse::error('email already registered', 409);
                }
                throw $e;
            }
        }

        if ($syncMemberships) {
            if ($effectiveRole === UserRole::ADMIN) {
                $this->clearProjectMemberships($userId);
            } else {
                $this->syncProjectMemberships($userId, $data['project_memberships']);
            }
        } elseif (array_key_exists('role', $data) && $effectiveRole === UserRole::ADMIN) {
            $this->clearProjectMemberships($userId);
        }

        $row = $this->fetchUserRow($userId);
        if ($row === null) {
            return JsonResponse::error('user not found', 404);
        }

        return JsonResponse::encode($response, ['data' => $row]);
    }

    /** DELETE /api/users/{userId} */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($denied = $this->authorizeManageUsers()) {
            return $denied;
        }

        $userId = (int) ($args['userId'] ?? 0);
        if ($userId <= 0) {
            return JsonResponse::error('Invalid user id', 422);
        }
        if (!$this->userExists($userId)) {
            return JsonResponse::error('user not found', 404);
        }

        $auth = $this->authorizationService();
        if ($auth->isAuthEnforced() && $auth->currentUserId() === $userId) {
            return JsonResponse::error('Cannot delete your own account', 422);
        }

        $del = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $del->execute(['id' => $userId]);

        return JsonResponse::encode($response, ['data' => ['id' => $userId, 'deleted' => true]]);
    }

    private function userExists(int $userId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);

        return (bool) $st->fetchColumn();
    }

    /**
     * @return array{id: int, email: string, display_name: string, role: string, created_at: mixed, project_memberships: list<array{project_id: int, project_name: string, role: string}>}|null
     */
    private function fetchUserRow(int $userId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, email, display_name, role, created_at FROM users WHERE id = :id LIMIT 1'
        );
        $st->execute(['id' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $memberships = $this->projectMembershipsByUser()[$userId] ?? [];

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'created_at' => $row['created_at'],
            'project_memberships' => $memberships,
        ];
    }

    private function fetchUserGlobalRole(int $userId): string
    {
        $st = $this->pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $st->execute(['id' => $userId]);
        $role = $st->fetchColumn();

        return $role !== false ? (string) $role : UserRole::USER;
    }

    /**
     * @return array<int, list<array{project_id: int, project_name: string, role: string}>>
     */
    private function projectMembershipsByUser(): array
    {
        if (!$this->tableExists('project_members')) {
            return [];
        }

        $stmt = $this->pdo->query(
            'SELECT pm.user_id, pm.project_id, p.name AS project_name, pm.role
             FROM project_members pm
             INNER JOIN projects p ON p.id = pm.project_id
             ORDER BY p.name, pm.user_id'
        );
        $byUser = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['user_id'];
            $byUser[$userId][] = [
                'project_id' => (int) $row['project_id'],
                'project_name' => (string) $row['project_name'],
                'role' => (string) $row['role'],
            ];
        }

        return $byUser;
    }

    private function tableExists(string $table): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $this->pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :t LIMIT 1"
            );
            $st->execute(['t' => $table]);

            return (bool) $st->fetchColumn();
        }

        return true;
    }

    private function clearProjectMemberships(int $userId): void
    {
        if (!$this->tableExists('project_members')) {
            return;
        }
        $del = $this->pdo->prepare('DELETE FROM project_members WHERE user_id = :uid');
        $del->execute(['uid' => $userId]);
    }

    /** @param mixed $raw */
    private function syncProjectMemberships(int $userId, mixed $raw): void
    {
        if (!$this->tableExists('project_members')) {
            return;
        }
        if (!is_array($raw)) {
            return;
        }

        $this->clearProjectMemberships($userId);
        $auth = $this->authorizationService();
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $projectId = (int) ($item['project_id'] ?? 0);
            $role = trim((string) ($item['role'] ?? ''));
            if ($projectId <= 0 || !ProjectRole::isValid($role)) {
                continue;
            }
            if (!$this->projectExists($projectId)) {
                continue;
            }
            $auth->assignProjectMember($projectId, $userId, $role);
        }
    }

    private function projectExists(int $projectId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :id LIMIT 1');
        $st->execute(['id' => $projectId]);

        return (bool) $st->fetchColumn();
    }
}
