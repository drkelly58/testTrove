<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthSettings;
use App\Auth\OAuthProfile;
use App\Auth\OAuthProviderFactory;
use App\Auth\OAuthResourceProfileMapper;
use App\JsonRequestBody;
use App\JsonResponse;
use App\Services\LocalPasswordAuthenticator;
use App\Services\OAuthUserProvisioner;
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

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthSettings $settings,
    ) {
        $this->providerFactory = new OAuthProviderFactory($_ENV);
        $this->profileMapper = new OAuthResourceProfileMapper();
        $this->users = new OAuthUserProvisioner($pdo);
    }

    public function session(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = null;
        if (!empty($_SESSION['user_id']) && is_numeric((string) $_SESSION['user_id'])) {
            $user = $this->users->findById((int) $_SESSION['user_id']);
        }

        return JsonResponse::encode($response, [
            'data' => [
                'auth_required' => $this->settings->isAuthRequired(),
                'local_login_enabled' => $this->settings->isLocalAuthEnabled(),
                'providers' => $this->settings->listProviders(),
                'user' => $user,
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

        $_SESSION['user_id'] = $user['id'];
        session_regenerate_id(true);

        return JsonResponse::encode($response, ['data' => ['user' => $user]]);
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
        $fixed = $this->settings->appBaseUrl();
        if ($fixed !== '') {
            return rtrim($fixed, '/');
        }
        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $x = strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO']));
            if ($x === 'https' || $x === 'http') {
                $scheme = $x;
            }
        }
        $host = $uri->getHost();
        $port = $uri->getPort();
        $default = ($scheme === 'https') ? 443 : 80;
        $authority = $host . ($port !== null && $port !== $default ? ':' . $port : '');

        return $scheme . '://' . $authority;
    }

    private function browserRedirect(ServerRequestInterface $request, string $location): ResponseInterface
    {
        if (!str_starts_with($location, 'http://') && !str_starts_with($location, 'https://')) {
            $location = $this->publicBase($request) . $location;
        }

        return (new Response(302))->withHeader('Location', $location);
    }
}
