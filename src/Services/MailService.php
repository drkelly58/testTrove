<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\MailSettings;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends plain-text (and simple HTML) mail using PHP {@see mail()} or SMTP.
 */
final class MailService
{
    public function __construct(private readonly MailSettings $settings)
    {
    }

    public function send(string $toEmail, string $subject, string $plainBody, ?string $htmlBody = null): bool
    {
        if (!$this->settings->isEnabled()) {
            return false;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->isHTML($htmlBody !== null);
            $mail->Subject = $subject;
            if ($htmlBody !== null) {
                $mail->Body = $htmlBody;
                $mail->AltBody = $plainBody;
            } else {
                $mail->Body = $plainBody;
            }
            $mail->setFrom($this->settings->fromAddress(), $this->settings->fromName());
            $mail->addAddress($toEmail);

            if ($this->settings->transport() === 'smtp') {
                $mail->isSMTP();
                $mail->Host = $this->settings->smtpHost();
                $mail->Port = $this->settings->smtpPort();
                $mail->SMTPAuth = $this->settings->smtpUser() !== '';
                if ($mail->SMTPAuth) {
                    $mail->Username = $this->settings->smtpUser();
                    $mail->Password = $this->settings->smtpPassword();
                }
                $enc = $this->settings->smtpEncryption();
                if ($enc === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } elseif ($enc === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } else {
                    $mail->SMTPSecure = '';
                }
            } else {
                $mail->isMail();
            }

            return $mail->send();
        } catch (Exception $e) {
            error_log('MailService: ' . $e->getMessage());

            return false;
        }
    }
}
