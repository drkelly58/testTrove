<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthSettings;
use App\Auth\DevPermissionSimulator;
use App\Auth\OAuthProfile;
use App\Auth\OAuthProviderFactory;
use App\Auth\OAuthResourceProfileMapper;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Mail\MailSettings;
use App\Services\AppUrlResolver;
use App\Services\AuthorizationService;
use App\Services\LocalPasswordAuthenticator;
use App\Services\OAuthUserProvisioner;
use App\Services\UserPasswordSupport;
use App\Services\UserPreferencesService;
use App\UserPreferences;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

final class AuthController
{
    private OAuthProviderFactory $providerFactory;
    private OAuthResourceProfileMapper $profileMapper;
    private OAuthUserProvisioner $users;

    private readonly AppUrlResolver $appUrlResolver;
    private readonly MailSettings $mailSettings;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthSettings $settings,
        private readonly AuthorizationService $authorization,
        ?AppUrlResolver $appUrlResolver = null,
        ?MailSettings $mailSettings = null,
    ) {
        $this->providerFactory = new OAuthProviderFactory($_ENV);
        $this->profileMapper = new OAuthResourceProfileMapper();
        $this->users = new OAuthUserProvisioner($pdo);
        $this->mailSettings = $mailSettings ?? MailSettings::fromEnv($_ENV);
        $this->appUrlResolver = $appUrlResolver ?? new AppUrlResolver($this->mailSettings);
    }

    public function session(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = null;
        $projectRoles = [];
        $isAdmin = false;
        $devPermissions = null;
        $hasAssignedOpenRuns = false;
        if ($this->authorization->isDevMode()) {
            $userId = $this->authorization->requireUserId();
            $isAdmin = $this->authorization->isGlobalAdmin($userId);
            $projectRoles = $this->authorization->projectRolesForUser($userId);
            $devPermissions = DevPermissionSimulator::describe();
            $hasAssignedOpenRuns = $this->authorization->hasOpenRunsAssignedToUser($userId);
        } elseif (!empty($_SESSION['user_id']) && is_numeric((string) $_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
            $user = $this->users->findById($userId);
            if ($user !== null && $this->settings->isAuthRequired()) {
                $isAdmin = $this->authorization->isGlobalAdmin($userId);
                $projectRoles = $this->authorization->projectRolesForUser($userId);
                $hasAssignedOpenRuns = $this->authorization->hasOpenRunsAssignedToUser($userId);
            }
        }

        return JsonResponse::encode($response, [
            'data' => [
                'auth_required' => $this->settings->isAuthRequired(),
                'local_login_enabled' => $this->settings->isLocalAuthEnabled(),
                'providers' => $this->settings->listProviders(),
                'user' => $user,
                'is_admin' => $isAdmin,
                'project_roles' => $projectRoles,
                'has_assigned_open_runs' => $hasAssignedOpenRuns,
                'dev_permissions' => $devPermissions,
                'email_notifications_available' => $this->mailSettings->isEnabled(),
            ],
        ]);
    }

    public function loginLocal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->settings->isLocalAuthEnabled()) {
            return JsonResponse::error('Local sign-in is not enabled', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if ($email === '' || $password === '') {
            return JsonResponse::error('Email and password are required', 422);
        }

        $local = new LocalPasswordAuthenticator($this->pdo);
        $user = $local->authenticate($email, $password);
        if ($user === null) {
            return JsonResponse::error('Invalid email or password', 401);
        }

        // Do not call session_regenerate_id() here: PHP would emit a second Set-Cookie header
        // (one at session_start, one at regenerate) and some browsers keep the empty session id.
        $_SESSION['user_id'] = $user['id'];

        return JsonResponse::encode($response, [
            'data' => [
                'user' => $user,
                'session_key' => session_id(),
            ],
        ]);
    }

    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (empty($_SESSION['user_id']) || !is_numeric((string) $_SESSION['user_id'])) {
            return JsonResponse::error('Authentication required', 401);
        }
        if (!$this->settings->isLocalAuthEnabled()) {
            return JsonResponse::error('Local password sign-in is not enabled', 404);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        $current = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        if ($current === '' || $newPassword === '') {
            return JsonResponse::error('current_password and new_password are required', 422);
        }

        $passwordError = UserPasswordSupport::validateNewPassword($newPassword);
        if ($passwordError !== null) {
            return JsonResponse::error($passwordError, 422);
        }

        $userId = (int) $_SESSION['user_id'];
        $stmt = $this->pdo->prepare(
            'SELECT password_hash, oauth_provider, oauth_subject FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return JsonResponse::error('User not found', 404);
        }

        $op = trim((string) ($row['oauth_provider'] ?? ''));
        $os = trim((string) ($row['oauth_subject'] ?? ''));
        if ($op !== '' || $os !== '') {
            return JsonResponse::error('This account uses external sign-in; password cannot be changed here', 422);
        }

        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || !password_verify($current, $hash)) {
            return JsonResponse::error('Current password is incorrect', 401);
        }
        if (password_verify($newPassword, $hash)) {
            return JsonResponse::error('New password must be different from the current password', 422);
        }

        $upd = $this->pdo->prepare(
            'UPDATE users SET password_hash = :ph, must_change_password = :mcp WHERE id = :id'
        );
        $upd->execute([
            'ph' => password_hash($newPassword, PASSWORD_DEFAULT),
            'mcp' => false,
            'id' => $userId,
        ]);

        $user = $this->users->findById($userId);
        if ($user === null) {
            return JsonResponse::error('User not found', 404);
        }

        return JsonResponse::encode($response, ['data' => ['user' => $user]]);
    }

    public function patchPreferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (empty($_SESSION['user_id']) || !is_numeric((string) $_SESSION['user_id'])) {
            return JsonResponse::error('Authentication required', 401);
        }

        try {
            $data = JsonRequestBody::decodeAssoc($request);
        } catch (\JsonException $e) {
            return JsonResponse::error('Invalid JSON: ' . $e->getMessage(), 422);
        }

        if (!is_array($data)) {
            return JsonResponse::error('Invalid JSON body', 422);
        }

        $patch = UserPreferences::filter($data);
        if ($patch === []) {
            return JsonResponse::error('No supported preference keys in body', 422);
        }

        $prefs = new UserPreferencesService($this->pdo);
        $merged = $prefs->patch((int) $_SESSION['user_id'], $patch);

        return JsonResponse::encode($response, ['data' => ['preferences' => $merged]]);
    }

    public function login(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $providerKey = strtolower(trim((string) ($args['provider'] ?? '')));
        if (!$this->providerIsEnabled($providerKey)) {
            return $this->browserRedirect($request, '/login?err=unknown_provider');
        }

        $q = $request->getQueryParams();
        $returnTo = $this->sanitizeReturnTo(isset($q['return_to']) ? (string) $q['return_to'] : null);
        $_SESSION['oauth2return'] = $returnTo;

        try {
            $redirectUri = $this->publicBase($request) . '/api/auth/callback/' . rawurlencode($providerKey);
            $provider = $this->providerFactory->create($providerKey, $redirectUri);
            $authUrl = $provider->getAuthorizationUrl();
            $_SESSION['oauth2state'] = $provider->getState();
        } catch (\Throwable) {
            return $this->browserRedirect($request, '/login?err=oauth_config');
        }

        return $this->browserRedirect($request, $authUrl);
    }

    public function callback(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $providerKey = strtolower(trim((string) ($args['provider'] ?? '')));
        if (!$this->providerIsEnabled($providerKey)) {
            return $this->browserRedirect($request, '/login?err=unknown_provider');
        }

        $q = $request->getQueryParams();
        $state = (string) ($q['state'] ?? '');
        $expected = (string) ($_SESSION['oauth2state'] ?? '');
        unset($_SESSION['oauth2state']);
        if ($expected === '' || !hash_equals($expected, $state)) {
            return $this->browserRedirect($request, '/login?err=state');
        }

        $code = (string) ($q['code'] ?? '');
        if ($code === '') {
            return $this->browserRedirect($request, '/login?err=code');
        }

        $returnTo = $this->sanitizeReturnTo(isset($_SESSION['oauth2return']) ? (string) $_SESSION['oauth2return'] : null);
        unset($_SESSION['oauth2return']);

        try {
            $redirectUri = $this->publicBase($request) . '/api/auth/callback/' . rawurlencode($providerKey);
            $provider = $this->providerFactory->create($providerKey, $redirectUri);
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $resourceOwner = $provider->getResourceOwner($token);
            /** @var OAuthProfile $profile */
            $profile = $this->profileMapper->map($resourceOwner, $providerKey);
            $bootstrap = $this->settings->bootstrapAdminEmail();
            $row = $this->users->upsert($profile, $bootstrap !== '' ? $bootstrap : null);
            $_SESSION['user_id'] = $row['id'];
            session_regenerate_id(true);
        } catch (IdentityProviderException) {
            return $this->browserRedirect($request, '/login?err=token');
        } catch (\InvalidArgumentException $e) {
            return $this->browserRedirect($request, '/login?err=' . rawurlencode('profile:' . $e->getMessage()));
        } catch (\RuntimeException $e) {
            $hint = str_contains($e->getMessage(), 'different sign-in') ? 'email_conflict' : 'profile';

            return $this->browserRedirect($request, '/login?err=' . rawurlencode($hint . ':' . $e->getMessage()));
        } catch (\Throwable) {
            return $this->browserRedirect($request, '/login?err=oauth_failed');
        }

        return $this->browserRedirect($request, $returnTo);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) ($p['secure'] ?? false), (bool) ($p['httponly'] ?? true));
        }
        session_destroy();

        return JsonResponse::encode($response, ['data' => ['ok' => true]]);
    }

    private function providerIsEnabled(string $key): bool
    {
        foreach ($this->settings->listProviders() as $p) {
            if ($p['id'] === $key) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeReturnTo(?string $raw): string
    {
        if ($raw === null) {
            return '/';
        }
        $raw = trim($raw);
        if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//')) {
            return '/';
        }

        return $raw;
    }

    private function publicBase(ServerRequestInterface $request): string
    {
        return $this->appUrlResolver->publicBase($request);
    }

    private function browserRedirect(ServerRequestInterface $request, string $location): ResponseInterface
    {
        if (!str_starts_with($location, 'http://') && !str_starts_with($location, 'https://')) {
            $location = $this->publicBase($request) . $location;
        }

        return (new Response(302))->withHeader('Location', $location);
    }
}
