<?php

declare(strict_types=1);

/**
 * Verify MAIL_* configuration and optionally send a test message.
 *
 *   php scripts/test_mail.php --status
 *   php scripts/test_mail.php you@example.com
 */

use App\Mail\MailSettings;
use App\Services\MailService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (is_readable($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

$argv = $GLOBALS['argv'] ?? [];
array_shift($argv);

$statusOnly = false;
$recipient = null;
foreach ($argv as $arg) {
    if ($arg === '--status' || $arg === '-s') {
        $statusOnly = true;
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php scripts/test_mail.php [--status] [recipient@example.com]\n");
        exit(0);
    }
    if (str_starts_with($arg, '-')) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
    if ($recipient !== null) {
        fwrite(STDERR, "Only one recipient address is allowed.\n");
        exit(1);
    }
    $recipient = $arg;
}

$settings = MailSettings::fromEnv($_ENV);
printStatus($settings);

if ($statusOnly) {
    exit($settings->isEnabled() ? 0 : 1);
}

if ($recipient === null) {
    fwrite(STDERR, "Provide a recipient email, or use --status to check configuration only.\n");
    exit(1);
}

if (!$settings->isEnabled()) {
    fwrite(STDERR, "Mail is not enabled; fix MAIL_* in .env before sending.\n");
    exit(1);
}

$mail = new MailService($settings);
$subject = 'TestTrove mail test';
$plain = "This is a test message from TestTrove.\n\n"
    . 'Sent at: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
    . "Transport: {$settings->transport()}\n";
$html = '<p>This is a <strong>test message</strong> from TestTrove.</p>'
    . '<p>Sent at: ' . htmlspecialchars(gmdate('Y-m-d H:i:s') . ' UTC', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

fwrite(STDOUT, "Sending test message to {$recipient}…\n");
if ($mail->send($recipient, $subject, $plain, $html)) {
    fwrite(STDOUT, "Sent.\n");
    exit(0);
}

$err = $mail->getLastError();
fwrite(STDERR, 'Send failed' . ($err !== '' ? ": {$err}" : '.') . "\n");
if ($settings->transport() === 'smtp' && $settings->smtpPort() === 465 && $settings->smtpEncryption() === 'tls') {
    fwrite(STDERR, "Hint: port 465 usually needs MAIL_SMTP_ENCRYPTION=ssl; use port 587 with tls for STARTTLS.\n");
}
exit(1);

function printStatus(MailSettings $settings): void
{
    fwrite(STDOUT, "Mail configuration\n");
    fwrite(STDOUT, '  enabled:     ' . ($settings->isEnabled() ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, '  transport:   ' . $settings->transport() . "\n");
    $from = $settings->fromAddress();
    fwrite(STDOUT, '  from:        ' . ($from !== '' ? "{$settings->fromName()} <{$from}>" : '(not set)') . "\n");
    if ($settings->transport() === 'smtp') {
        fwrite(STDOUT, '  smtp host:   ' . $settings->smtpHost() . "\n");
        fwrite(STDOUT, '  smtp port:   ' . (string) $settings->smtpPort() . "\n");
        $enc = $settings->smtpEncryption();
        fwrite(STDOUT, '  encryption:  ' . ($enc !== '' ? $enc : '(none)') . "\n");
        fwrite(STDOUT, '  smtp user:   ' . ($settings->smtpUser() !== '' ? $settings->smtpUser() : '(none)') . "\n");
        fwrite(STDOUT, '  smtp pass:   ' . ($settings->smtpPassword() !== '' ? '(set)' : '(none)') . "\n");
    }
    $base = $settings->appBaseUrl();
    fwrite(STDOUT, '  app base:    ' . ($base !== '' ? $base : '(not set)') . "\n");
    fwrite(STDOUT, "\n");
}
