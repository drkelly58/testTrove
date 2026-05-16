<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Outbound mail configuration from the environment. Optional feature: when not enabled, no mail is sent.
 */
final class MailSettings
{
    /** @param array<string, string|null> $env */
    private function __construct(private readonly array $env)
    {
    }

    /** @param array<string, string|null> $env */
    public static function fromEnv(array $env): self
    {
        return new self($env);
    }

    private function str(string $key): string
    {
        return trim((string) ($this->env[$key] ?? ''));
    }

    /**
     * True when mail notifications may be sent (admin has enabled and configured outbound mail).
     */
    public function isEnabled(): bool
    {
        $v = strtolower($this->str('MAIL_ENABLED'));
        if ($v !== '1' && $v !== 'true' && $v !== 'yes') {
            return false;
        }
        if ($this->fromAddress() === '') {
            return false;
        }
        $transport = $this->transport();
        if ($transport === 'smtp') {
            return $this->str('MAIL_SMTP_HOST') !== '';
        }

        return $transport === 'php';
    }

    public function transport(): string
    {
        $t = strtolower($this->str('MAIL_TRANSPORT'));
        if ($t === 'smtp') {
            return 'smtp';
        }

        return 'php';
    }

    public function fromAddress(): string
    {
        $direct = strtolower(trim($this->str('MAIL_FROM_ADDRESS')));
        if ($direct !== '' && filter_var($direct, FILTER_VALIDATE_EMAIL)) {
            return $direct;
        }
        $parsed = $this->parseMailFrom();
        if ($parsed !== null) {
            return $parsed['email'];
        }

        return '';
    }

    public function fromName(): string
    {
        $n = trim($this->str('MAIL_FROM_NAME'));
        if ($n !== '') {
            return $n;
        }
        $parsed = $this->parseMailFrom();

        return $parsed['name'] ?? 'TestTrove';
    }

    /**
     * @return array{email: string, name: string}|null
     */
    private function parseMailFrom(): ?array
    {
        $raw = trim($this->str('MAIL_FROM'));
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\s*(.+?)\s*<\s*([^>]+)\s*>\s*$/', $raw, $m)) {
            $email = strtolower(trim($m[2]));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['email' => $email, 'name' => trim($m[1], " \t\n\r\0\x0B\"")];
            }

            return null;
        }
        $email = strtolower($raw);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $email, 'name' => 'TestTrove'];
        }

        return null;
    }

    public function smtpHost(): string
    {
        return $this->str('MAIL_SMTP_HOST');
    }

    public function smtpPort(): int
    {
        $p = trim($this->str('MAIL_SMTP_PORT'));
        if ($p === '' || !ctype_digit($p)) {
            return $this->smtpEncryption() === 'ssl' ? 465 : 587;
        }

        return max(1, (int) $p);
    }

    public function smtpUser(): string
    {
        return $this->str('MAIL_SMTP_USER');
    }

    public function smtpPassword(): string
    {
        return $this->str('MAIL_SMTP_PASSWORD');
    }

    /** '', 'tls', or 'ssl' */
    public function smtpEncryption(): string
    {
        $e = strtolower(trim($this->str('MAIL_SMTP_ENCRYPTION')));
        if ($e === 'tls' || $e === 'ssl' || $e === 'smtps') {
            return $e === 'smtps' ? 'ssl' : $e;
        }

        return '';
    }

    /** Public app URL for links in email (APP_BASE_URL, no trailing slash). */
    public function appBaseUrl(): string
    {
        $u = $this->str('APP_BASE_URL');

        return $u !== '' ? rtrim($u, '/') : '';
    }
}
