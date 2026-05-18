<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailSettings;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Sends invite email with a temporary password for new local-password users.
 */
final class UserInviteNotifier
{
    public function __construct(
        private readonly MailSettings $mailSettings,
        private readonly MailService $mail,
        private readonly AppUrlResolver $appUrlResolver,
        private readonly InviteEmailContent $inviteContent,
    ) {
    }

    public function sendInvite(
        string $toEmail,
        string $displayName,
        string $temporaryPassword,
        ?ServerRequestInterface $request = null,
        ?string $inviteIntro = null,
    ): bool {
        if (!$this->mailSettings->isEnabled()) {
            return false;
        }

        $loginUrl = $this->appUrlResolver->loginUrl($request);
        $introTemplate = $this->inviteContent->resolveIntro($inviteIntro);
        $intro = $this->inviteContent->substituteDisplayName($introTemplate, $displayName);
        $bodies = $this->inviteContent->buildBodies($intro, $toEmail, $temporaryPassword, $loginUrl);

        return $this->mail->send(
            $toEmail,
            $this->inviteContent->resolveSubject($displayName),
            $bodies['plain'],
            $bodies['html'],
        );
    }

    public function mailEnabled(): bool
    {
        return $this->mailSettings->isEnabled();
    }
}
