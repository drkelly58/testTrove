<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Invite email copy: customizable intro and subject; fixed credential + sign-in footer.
 */
final class InviteEmailContent
{
    private const DEFAULT_SUBJECT = 'Your TestTrove account';

    private const DEFAULT_INTRO = "Hello {display_name},\n\nAn account was created for you on TestTrove.";

    public const MAX_SUBJECT_LENGTH = 255;

    public const MAX_INTRO_LENGTH = 2000;

    /** @param array<string, string|null> $env */
    public function __construct(private readonly array $env = [])
    {
    }

    /** @param array<string, string|null> $env */
    public static function fromEnv(array $env): self
    {
        return new self($env);
    }

    /** Subject template for admin UI (may include {display_name}). */
    public function defaultSubject(): string
    {
        $fromEnv = trim((string) ($this->env['MAIL_INVITE_SUBJECT'] ?? ''));

        return $fromEnv !== '' ? $fromEnv : self::DEFAULT_SUBJECT;
    }

    public function resolveSubject(string $displayName): string
    {
        return $this->substituteDisplayName($this->defaultSubject(), $displayName);
    }

    /** Intro template for admin UI (may include {display_name}). */
    public function defaultIntroTemplate(): string
    {
        $fromEnv = trim((string) ($this->env['MAIL_INVITE_INTRO'] ?? ''));

        return $fromEnv !== '' ? $fromEnv : self::DEFAULT_INTRO;
    }

    /** Admin override, then env/default template (not yet substituted). */
    public function resolveIntro(?string $adminIntro): string
    {
        if ($adminIntro !== null) {
            $trimmed = trim($adminIntro);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $this->defaultIntroTemplate();
    }

    public function substituteDisplayName(string $introTemplate, string $displayName): string
    {
        return str_replace('{display_name}', $displayName, $introTemplate);
    }

    /**
     * @return array{plain: string, html: string}
     */
    public function buildBodies(
        string $intro,
        string $toEmail,
        string $temporaryPassword,
        ?string $loginUrl,
    ): array {
        $plainFooter = $this->plainFooter($toEmail, $temporaryPassword, $loginUrl);
        $plain = rtrim($intro) . "\n\n" . $plainFooter;

        $htmlIntro = '<p>' . nl2br(htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        $html = $htmlIntro . $this->htmlFooter($toEmail, $temporaryPassword, $loginUrl);

        return ['plain' => $plain, 'html' => $html];
    }

    private function plainFooter(string $toEmail, string $temporaryPassword, ?string $loginUrl): string
    {
        $lines = [
            'Email: ' . $toEmail,
            'Temporary password: ' . $temporaryPassword,
        ];
        if ($loginUrl !== null) {
            $lines[] = 'Sign in: ' . $loginUrl;
        }
        $lines[] = '';
        $lines[] = 'You must choose a new password when you sign in for the first time.';
        $lines[] = '';
        $lines[] = '— TestTrove';

        return implode("\n", $lines);
    }

    private function htmlFooter(string $toEmail, string $temporaryPassword, ?string $loginUrl): string
    {
        $safeEmail = htmlspecialchars($toEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safePassword = htmlspecialchars($temporaryPassword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linkHtml = $loginUrl !== null
            ? '<p><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Sign in to TestTrove</a></p>'
            : '';

        return '<p><strong>Email:</strong> ' . $safeEmail . '</p>'
            . '<p><strong>Temporary password:</strong> <code>' . $safePassword . '</code></p>'
            . $linkHtml
            . '<p>You must choose a new password when you sign in for the first time.</p>'
            . '<p>— TestTrove</p>';
    }
}
