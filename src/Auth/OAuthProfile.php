<?php

declare(strict_types=1);

namespace App\Auth;

/** Normalized identity from an OAuth2 resource owner. */
final class OAuthProfile
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $subject,
        public readonly string $email,
        public readonly string $displayName,
        public readonly ?string $pictureUrl,
    ) {
    }
}
