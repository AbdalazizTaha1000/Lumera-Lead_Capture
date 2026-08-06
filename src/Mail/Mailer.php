<?php

declare(strict_types=1);

namespace Lumera\Mail;

use Lumera\Core\Config;
use Lumera\Core\Logger;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * SMTP transport. Credentials come exclusively from the environment; nothing
 * here is configurable from the dashboard or exposed to the browser.
 *
 * PHP's mail() is deliberately not used.
 */
final class Mailer
{
    public function isConfigured(): bool
    {
        return Config::string('SMTP_HOST', '') !== ''
            && Config::string('MAIL_FROM_ADDRESS', '') !== '';
    }

    /**
     * @param list<string> $to
     * @return array{ok: bool, error?: string}
     */
    public function send(
        array $to,
        string $subject,
        string $html,
        string $text,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): array {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP is not configured.'];
        }

        $recipients = array_values(array_filter(
            $to,
            static fn ($address) => is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));

        if ($recipients === []) {
            return ['ok' => false, 'error' => 'No valid recipient configured.'];
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = Config::string('SMTP_HOST', '');
            $mail->Port       = Config::int('SMTP_PORT', 587);
            $mail->SMTPAuth   = Config::bool('SMTP_AUTH', true);
            $mail->CharSet    = PHPMailer::CHARSET_UTF8;
            $mail->Encoding   = PHPMailer::ENCODING_BASE64;
            $mail->Timeout    = 15;
            $mail->SMTPDebug  = SMTP::DEBUG_OFF;

            if ($mail->SMTPAuth) {
                $mail->Username = Config::string('SMTP_USERNAME', '');
                $mail->Password = Config::string('SMTP_PASSWORD', '');
            }

            $encryption = strtolower(Config::string('SMTP_ENCRYPTION', 'tls'));

            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif (in_array($encryption, ['ssl', 'smtps'], true)) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom(
                Config::string('MAIL_FROM_ADDRESS', ''),
                Config::string('MAIL_FROM_NAME', 'Lead Capture')
            );

            foreach ($recipients as $address) {
                $mail->addAddress($address);
            }

            if (
                Config::string('LEAD_REPLY_TO_MODE', 'lead_email') === 'lead_email'
                && is_string($replyToEmail)
                && filter_var($replyToEmail, FILTER_VALIDATE_EMAIL) !== false
            ) {
                $mail->addReplyTo($replyToEmail, (string) ($replyToName ?? ''));
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;

            $mail->send();

            return ['ok' => true];
        } catch (MailException $e) {
            // Internal detail only — the public response never carries this.
            Logger::error('mail.send_failed', ['error' => $mail->ErrorInfo ?: $e->getMessage()]);

            return ['ok' => false, 'error' => $this->safeError($mail->ErrorInfo ?: $e->getMessage())];
        } catch (\Throwable $e) {
            Logger::error('mail.transport_error', ['message' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Mail transport error.'];
        }
    }

    /** Strips anything credential-shaped before the string is stored on the lead. */
    private function safeError(string $error): string
    {
        $error = preg_replace('/\b(?:AUTH|PASS|LOGIN)\b[^\s]*\s*\S+/i', '[redacted]', $error) ?? $error;

        $password = Config::string('SMTP_PASSWORD', '');

        if ($password !== '') {
            $error = str_replace($password, '[redacted]', $error);
        }

        return mb_substr($error, 0, 500);
    }
}
