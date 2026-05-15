<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\ProjectRole;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Services\AuthorizationService;
use App\Services\ProjectScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProjectMemberController
{
    use AuthorizesApiAccess;

    public function __construct(
        private readonly PDO $pdo,
        AuthorizationService $authorization,
        ProjectScopeResolver $projectScope,
    ) {
        $this->initAuthorization($authorization, $projectScope);
    }

    /** GET /api/projects/{projectId}/members */
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($projectId <= 0) {
            return JsonResponse::error('Invalid project id', 422);
        }
        if (!$this->projectExists($projectId)) {
            return JsonResponse::error('project not found', 404);
        }
        if ($denied = $this->authorizeManageMembers($projectId)) {
            return $denied;
        }

        $stmt = $this->pdo->prepare(
            'SELECT pm.user_id, pm.role, pm.created_at, u.email, u.display_name
             FROM project_members pm
             INNER JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = :pid
             ORDER BY u.display_name, u.email'
        );
        $stmt->execute(['pid' => $projectId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'user_id' => (int) $row['user_id'],
                'role' => (string) $row['role'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'created_at' => $row['created_at'],
            ];
        }

        return JsonResponse::encode($response, ['data' => $rows]);
    }

    /** PUT /api/projects/{projectId}/members — body: { email, role } */
    public function upsert(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        if ($projectId <= 0) {
            return JsonResponse::error('Invalid project id', 422);
        }
        if (!$this->projectExists($projectId)) {
            return JsonResponse::error('project not found', 404);
        }
        if ($denied = $this->authorizeManageMembers($projectId)) {
            return $denied;
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $role = trim((string) ($data['role'] ?? ''));
        if ($email === '') {
            return JsonResponse::error('email is required', 422);
        }
        if (!ProjectRole::isValid($role)) {
            return JsonResponse::error('role must be member, tester, or viewer', 422);
        }

        $userStmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = :e LIMIT 1');
        $userStmt->execute(['e' => $email]);
        $userIdRaw = $userStmt->fetchColumn();
        if ($userIdRaw === false) {
            return JsonResponse::error('user not found', 404);
        }
        $userId = (int) $userIdRaw;

        $this->authorizationService()->assignProjectMember($projectId, $userId, $role);

        return JsonResponse::encode($response, [
            'data' => ['project_id' => $projectId, 'user_id' => $userId, 'role' => $role],
        ]);
    }

    /** DELETE /api/projects/{projectId}/members/{userId} */
    public function remove(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $projectId = (int) ($args['projectId'] ?? 0);
        $userId = (int) ($args['userId'] ?? 0);
        if ($projectId <= 0 || $userId <= 0) {
            return JsonResponse::error('Invalid project or user id', 422);
        }
        if (!$this->projectExists($projectId)) {
            return JsonResponse::error('project not found', 404);
        }
        if ($denied = $this->authorizeManageMembers($projectId)) {
            return $denied;
        }

        $this->authorizationService()->removeProjectMember($projectId, $userId);

        return JsonResponse::encode($response, ['data' => ['removed' => true]]);
    }

    private function projectExists(int $projectId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM projects WHERE id = :id LIMIT 1');
        $st->execute(['id' => $projectId]);

        return (bool) $st->fetchColumn();
    }
}
