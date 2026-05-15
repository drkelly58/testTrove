<?php

declare(strict_types=1);

namespace App\Controllers;

use App\JsonResponse;
use App\Services\AuthorizationService;
use App\Services\ProjectScopeResolver;
use Psr\Http\Message\ResponseInterface;

trait AuthorizesApiAccess
{
    private AuthorizationService $authorization;
    private ProjectScopeResolver $projectScope;

    private function initAuthorization(AuthorizationService $authorization, ProjectScopeResolver $projectScope): void
    {
        $this->authorization = $authorization;
        $this->projectScope = $projectScope;
    }

    protected function forbid(): ResponseInterface
    {
        return JsonResponse::error('Forbidden', 403);
    }

    protected function authorizeProjectRead(int $projectId): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canReadProject($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeProjectWrite(int $projectId): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canWriteProject($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeRunExecute(int $projectId): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canExecuteRuns($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeRunRead(int $projectId): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canReadRuns($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeProjectDelete(int $projectId): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canDeleteProject($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeWorkspaceAdmin(): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canManageWorkspace($userId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeManageMembers(int $projectId): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canManageProjectMembers($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeCreateProject(): ?ResponseInterface
    {
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canCreateProjects($userId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizeSuiteRead(int $suiteId): ?ResponseInterface
    {
        $projectId = $this->projectScope->projectIdForSuite($suiteId);
        if ($projectId === null) {
            return null;
        }

        return $this->authorizeProjectRead($projectId);
    }

    protected function authorizeSuiteWrite(int $suiteId): ?ResponseInterface
    {
        $projectId = $this->projectScope->projectIdForSuite($suiteId);
        if ($projectId === null) {
            return null;
        }

        return $this->authorizeProjectWrite($projectId);
    }

    protected function authorizeSectionRunExecute(int $sectionId): ?ResponseInterface
    {
        $projectId = $this->projectScope->projectIdForSection($sectionId);
        if ($projectId === null) {
            return null;
        }

        return $this->authorizeRunExecute($projectId);
    }

    protected function authorizeRunExecuteById(int $runId): ?ResponseInterface
    {
        $projectId = $this->projectScope->projectIdForRun($runId);
        if ($projectId === null) {
            return null;
        }

        return $this->authorizeRunExecute($projectId);
    }

    protected function authorizeRunReadById(int $runId): ?ResponseInterface
    {
        $projectId = $this->projectScope->projectIdForRun($runId);
        if ($projectId === null) {
            return null;
        }

        return $this->authorizeRunRead($projectId);
    }

    protected function authorizeRunManageById(int $runId): ?ResponseInterface
    {
        $projectId = $this->projectScope->projectIdForRun($runId);
        if ($projectId === null) {
            return null;
        }
        if (!$this->authorization->isAuthEnforced()) {
            return null;
        }
        $userId = $this->authorization->requireUserId();
        if (!$this->authorization->canManageRuns($userId, $projectId)) {
            return $this->forbid();
        }

        return null;
    }

    protected function authorizationService(): AuthorizationService
    {
        return $this->authorization;
    }
}
