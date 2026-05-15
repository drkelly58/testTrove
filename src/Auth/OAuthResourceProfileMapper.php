<?php

declare(strict_types=1);

namespace App\Auth;

use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use TheNetworg\OAuth2\Client\Provider\AzureResourceOwner;

final class OAuthResourceProfileMapper
{
    public function map(ResourceOwnerInterface $owner, string $providerKey): OAuthProfile
    {
        return match ($providerKey) {
            'microsoft' => $this->fromMicrosoft($owner),
            'google' => $this->fromGoogle($owner),
            'github' => $this->fromGithub($owner),
            'generic' => $this->fromGeneric($owner),
            default => throw new \InvalidArgumentException('Unknown OAuth provider: ' . $providerKey),
        };
    }

    private function fromMicrosoft(ResourceOwnerInterface $owner): OAuthProfile
    {
        if (!$owner instanceof AzureResourceOwner) {
            throw new \InvalidArgumentException('Expected Azure resource owner');
        }
        $oid = (string) ($owner->getId() ?? '');
        if ($oid === '') {
            throw new \RuntimeException('Microsoft account did not return an object id (oid).');
        }
        $email = $owner->getEmail() ?? $owner->getUpn() ?? $owner->getPreferredUsername() ?? '';
        $email = trim((string) $email);
        $first = trim((string) ($owner->getFirstName() ?? ''));
        $last = trim((string) ($owner->getLastName() ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name === '') {
            $name = $email !== '' ? $email : 'Microsoft user';
        }

        return new OAuthProfile('microsoft', $oid, $email, $name, null);
    }

    private function fromGoogle(ResourceOwnerInterface $owner): OAuthProfile
    {
        if (!$owner instanceof GoogleUser) {
            throw new \InvalidArgumentException('Expected Google resource owner');
        }
        $sub = (string) $owner->getId();
        if ($sub === '') {
            throw new \RuntimeException('Google did not return subject (sub).');
        }
        $data = $owner->toArray();
        $email = trim((string) ($data['email'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = $email !== '' ? $email : 'Google user';
        }
        $avatar = $owner->getAvatar();

        return new OAuthProfile('google', $sub, $email, $name, $avatar);
    }

    private function fromGithub(ResourceOwnerInterface $owner): OAuthProfile
    {
        if (!$owner instanceof GithubResourceOwner) {
            throw new \InvalidArgumentException('Expected GitHub resource owner');
        }
        $id = $owner->getId();
        $sub = $id !== null ? (string) $id : '';
        if ($sub === '') {
            throw new \RuntimeException('GitHub did not return user id.');
        }
        $email = trim((string) ($owner->getEmail() ?? ''));
        $name = trim((string) ($owner->getName() ?? ''));
        if ($name === '') {
            $name = $owner->getNickname() ?? ('GitHub user ' . $sub);
        }
        $avatar = $owner->toArray()['avatar_url'] ?? null;
        $avatar = is_string($avatar) && $avatar !== '' ? $avatar : null;

        return new OAuthProfile('github', $sub, $email, $name, $avatar);
    }

    private function fromGeneric(ResourceOwnerInterface $owner): OAuthProfile
    {
        $sub = trim((string) $owner->getId());
        if ($sub === '') {
            throw new \RuntimeException('Generic IdP userinfo did not include the configured subject field.');
        }
        $email = '';
        foreach (['email', 'preferred_username', 'upn'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $email = trim($data[$k]);
                break;
            }
        }
        $name = '';
        foreach (['name', 'display_name', 'given_name'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $name = trim($data[$k]);
                break;
            }
        }
        if ($name === '') {
            $name = $email !== '' ? $email : 'User';
        }
        $pic = $data['picture'] ?? $data['avatar_url'] ?? null;
        $pic = is_string($pic) && $pic !== '' ? $pic : null;

        return new OAuthProfile('generic', $sub, $email, $name, $pic);
    }
}
