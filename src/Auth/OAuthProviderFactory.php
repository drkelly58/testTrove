<?php

declare(strict_types=1);

namespace App\Auth;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\Google;
use TheNetworg\OAuth2\Client\Provider\Azure;

final class OAuthProviderFactory
{
    /** @param array<string, string|null> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function create(string $providerKey, string $redirectUri): AbstractProvider
    {
        return match ($providerKey) {
            'microsoft' => $this->microsoft($redirectUri),
            'google' => $this->google($redirectUri),
            'github' => $this->github($redirectUri),
            'generic' => $this->generic($redirectUri),
            default => throw new \InvalidArgumentException('Unknown provider: ' . $providerKey),
        };
    }

    private function env(string $key): string
    {
        return trim((string) ($this->env[$key] ?? ''));
    }

    private function microsoft(string $redirectUri): Azure
    {
        $id = $this->env('OAUTH_MICROSOFT_CLIENT_ID');
        $secret = $this->env('OAUTH_MICROSOFT_CLIENT_SECRET');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('Microsoft OAuth is not configured.');
        }
        $tenant = trim((string) ($this->env['OAUTH_MICROSOFT_TENANT'] ?? ''));
        if ($tenant === '') {
            $tenant = 'common';
        }
        $provider = new Azure([
            'clientId' => $id,
            'clientSecret' => $secret,
            'redirectUri' => $redirectUri,
            'tenant' => $tenant,
            'defaultEndPointVersion' => Azure::ENDPOINT_VERSION_2_0,
            'scopes' => ['openid', 'profile', 'email'],
        ]);
        $provider->authWithResource = false;

        return $provider;
    }

    private function google(string $redirectUri): Google
    {
        $id = $this->env('OAUTH_GOOGLE_CLIENT_ID');
        $secret = $this->env('OAUTH_GOOGLE_CLIENT_SECRET');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('Google OAuth is not configured.');
        }

        return new Google([
            'clientId' => $id,
            'clientSecret' => $secret,
            'redirectUri' => $redirectUri,
        ]);
    }

    private function github(string $redirectUri): Github
    {
        $id = $this->env('OAUTH_GITHUB_CLIENT_ID');
        $secret = $this->env('OAUTH_GITHUB_CLIENT_SECRET');
        if ($id === '' || $secret === '') {
            throw new \RuntimeException('GitHub OAuth is not configured.');
        }

        return new Github([
            'clientId' => $id,
            'clientSecret' => $secret,
            'redirectUri' => $redirectUri,
        ]);
    }

    private function generic(string $redirectUri): GenericProvider
    {
        $id = $this->env('OAUTH_GENERIC_CLIENT_ID');
        $secret = $this->env('OAUTH_GENERIC_CLIENT_SECRET');
        $auth = $this->env('OAUTH_GENERIC_AUTHORIZE_URL');
        $token = $this->env('OAUTH_GENERIC_TOKEN_URL');
        $userinfo = $this->env('OAUTH_GENERIC_USERINFO_URL');
        if ($id === '' || $secret === '' || $auth === '' || $token === '' || $userinfo === '') {
            throw new \RuntimeException('Generic OAuth is not configured.');
        }
        $settings = AuthSettings::fromGlobals($this->env);
        $ownerId = $settings->genericResourceOwnerIdField();

        return new GenericProvider([
            'clientId' => $id,
            'clientSecret' => $secret,
            'redirectUri' => $redirectUri,
            'urlAuthorize' => $auth,
            'urlAccessToken' => $token,
            'urlResourceOwnerDetails' => $userinfo,
            'scopes' => $settings->genericScopes(),
            'scopeSeparator' => ' ',
            'responseResourceOwnerId' => $ownerId,
        ]);
    }
}
