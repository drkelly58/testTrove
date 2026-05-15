<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Auth configuration from environment. When OAuth and/or local password login is enabled,
 * {@see self::isAuthRequired()} is true and the API expects a PHP session (see auth middleware).
 */
final class AuthSettings
{
    /** @param array<string, string|null> $env */
    private function __construct(private readonly array $env)
    {
    }

    /** @param array<string, string|null> $env */
    public static function fromGlobals(array $env = []): self
    {
        return new self($env !== [] ? $env : $_ENV);
    }

    private function str(string $key): string
    {
        return trim((string) ($this->env[$key] ?? ''));
    }

    public function isLocalAuthEnabled(): bool
    {
        $v = strtolower($this->str('AUTH_LOCAL_ENABLED'));

        return $v === '1' || $v === 'true' || $v === 'yes';
    }

    public function isAuthRequired(): bool
    {
        if ($this->isLocalAuthEnabled()) {
            return true;
        }

        foreach (['OAUTH_MICROSOFT_CLIENT_ID', 'OAUTH_GOOGLE_CLIENT_ID', 'OAUTH_GITHUB_CLIENT_ID', 'OAUTH_GENERIC_CLIENT_ID'] as $k) {
            if ($this->str($k) !== '') {
                return true;
            }
        }

        return false;
    }

    public function localBootstrapEmail(): string
    {
        return strtolower(trim($this->str('AUTH_LOCAL_BOOTSTRAP_EMAIL')));
    }

    public function localBootstrapPassword(): string
    {
        return $this->str('AUTH_LOCAL_BOOTSTRAP_PASSWORD');
    }

    public function localBootstrapDisplayName(): string
    {
        $n = $this->str('AUTH_LOCAL_BOOTSTRAP_DISPLAY_NAME');

        return $n !== '' ? $n : 'Local admin';
    }

    /** Public site URL (no trailing slash), used for OAuth redirect_uri. */
    public function appBaseUrl(): string
    {
        $u = $this->str('APP_BASE_URL');
        if ($u !== '') {
            return rtrim($u, '/');
        }

        return '';
    }

    public function bootstrapAdminEmail(): string
    {
        return strtolower(trim($this->str('OAUTH_BOOTSTRAP_ADMIN_EMAIL')));
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function listProviders(): array
    {
        $out = [];
        if ($this->str('OAUTH_MICROSOFT_CLIENT_ID') !== '' && $this->str('OAUTH_MICROSOFT_CLIENT_SECRET') !== '') {
            $out[] = ['id' => 'microsoft', 'label' => 'Microsoft (Entra ID / Azure AD)'];
        }
        if ($this->str('OAUTH_GOOGLE_CLIENT_ID') !== '' && $this->str('OAUTH_GOOGLE_CLIENT_SECRET') !== '') {
            $out[] = ['id' => 'google', 'label' => 'Google'];
        }
        if ($this->str('OAUTH_GITHUB_CLIENT_ID') !== '' && $this->str('OAUTH_GITHUB_CLIENT_SECRET') !== '') {
            $out[] = ['id' => 'github', 'label' => 'GitHub'];
        }
        if ($this->genericConfigured()) {
            $label = $this->str('OAUTH_GENERIC_LABEL');
            $out[] = ['id' => 'generic', 'label' => $label !== '' ? $label : 'OpenID Connect (custom)'];
        }

        return $out;
    }

    public function genericConfigured(): bool
    {
        if ($this->str('OAUTH_GENERIC_CLIENT_ID') === '' || $this->str('OAUTH_GENERIC_CLIENT_SECRET') === '') {
            return false;
        }
        if ($this->str('OAUTH_GENERIC_AUTHORIZE_URL') === '' || $this->str('OAUTH_GENERIC_TOKEN_URL') === '') {
            return false;
        }
        if ($this->str('OAUTH_GENERIC_USERINFO_URL') === '') {
            return false;
        }

        return true;
    }

    public function microsoftTenant(): string
    {
        $t = $this->str('OAUTH_MICROSOFT_TENANT');

        return $t !== '' ? $t : 'common';
    }

    /** Space-separated scopes for the generic provider. */
    public function genericScopes(): array
    {
        $raw = $this->str('OAUTH_GENERIC_SCOPES');
        if ($raw === '') {
            return ['openid', 'profile', 'email'];
        }
        return array_values(array_filter(array_map('trim', explode(' ', $raw))));
    }

    public function genericResourceOwnerIdField(): string
    {
        $f = $this->str('OAUTH_GENERIC_RESOURCE_OWNER_ID');
        if ($f !== '') {
            return $f;
        }

        return 'sub';
    }
}
