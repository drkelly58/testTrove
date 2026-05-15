<?php

declare(strict_types=1);

namespace App\Controllers;

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
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
                'created_at' => $row['created_at'],
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

        return JsonResponse::encode($response, [
            'data' => [
                'id' => $id,
                'email' => $email,
                'display_name' => $displayName,
                'role' => $role,
            ],
        ], 201);
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
        if ($sets === []) {
            return JsonResponse::error('No user fields to update', 422);
        }

        try {
            $upd = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $upd->execute($params);
        } catch (\PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return JsonResponse::error('email already registered', 409);
            }
            throw $e;
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
     * @return array{id: int, email: string, display_name: string, role: string, created_at: mixed}|null
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

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'created_at' => $row['created_at'],
        ];
    }
}
