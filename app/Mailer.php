<?php

declare(strict_types=1);

namespace Legends;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Sends the encrypted package to the HR head over authenticated SMTP.
 *
 * The email BODY deliberately contains no sensitive personal data (SIN,
 * banking, medical) — all of that lives only inside the encrypted attachment.
 */
final class Mailer
{
    public function __construct(private array $mailCfg, private array $hrCfg)
    {
    }

    /**
     * @param array $package  ['path'=>.., 'filename'=>.., 'method'=>.., 'encrypted'=>bool]
     * @param array $data     validated scalar data (for the routing summary)
     * @param array $meta     ['reference'=>.., 'timestamp'=>..]
     * @throws \RuntimeException on send failure
     */
    /**
     * Sends (or, in fallback cases, logs) the submission email.
     * Returns a human-readable note when the message was NOT actually emailed
     * (test mode with no test recipient), so the UI can say so instead of
     * looking like a normal send — otherwise null.
     */
    public function send(array $package, array $data, array $meta, bool $isTest = false): ?string
    {
        $transport = strtolower((string) ($this->mailCfg['transport'] ?? 'smtp'));
        $note = null;

        // Test submissions go to the test recipient (never HR). If none is set,
        // fall back to writing a preview .eml so a live [TEST] never reaches HR.
        $testRecipient = (string) ($this->mailCfg['test_recipient'] ?? '');
        $testHasRecipient = $isTest && filter_var($testRecipient, FILTER_VALIDATE_EMAIL);
        if ($isTest && !$testHasRecipient) {
            $transport = 'log';
            $note = 'No test recipient is configured (mail.test_recipient in config/config.php), '
                . 'so this TEST submission was saved on the server (storage/mail) instead of being emailed.';
        }

        $required = $transport === 'smtp'
            ? ['host', 'username', 'password', 'from_email']
            : ['from_email'];
        foreach ($required as $req) {
            if (($this->mailCfg[$req] ?? '') === '') {
                throw new \RuntimeException("Mail is not configured (missing '{$req}'). Set it in config/config.php.");
            }
        }
        $toEmail = $testHasRecipient ? $testRecipient : (string) ($this->hrCfg['email'] ?? '');
        $toName  = $testHasRecipient ? 'Onboarding Test' : (string) ($this->hrCfg['name'] ?? '');
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Recipient email is not configured or is not a valid email address.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Timeout = 25;

            if ($transport === 'smtp') {
                $mail->isSMTP();
                $mail->Host     = (string) $this->mailCfg['host'];
                $mail->Port     = (int) $this->mailCfg['port'];
                $mail->SMTPAuth = true;
                $mail->Username = (string) $this->mailCfg['username'];
                $mail->Password = (string) $this->mailCfg['password'];

                $secure = strtolower((string) ($this->mailCfg['secure'] ?? 'ssl'));
                $mail->SMTPSecure = $secure === 'tls'
                    ? PHPMailer::ENCRYPTION_STARTTLS
                    : PHPMailer::ENCRYPTION_SMTPS;

                if (!($this->mailCfg['verify_tls'] ?? true)) {
                    $mail->SMTPOptions = ['ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]];
                }
            }

            $mail->setFrom((string) $this->mailCfg['from_email'], (string) ($this->mailCfg['from_name'] ?? 'Onboarding'));
            $mail->addAddress($toEmail, $toName);

            // Reply-To → the new hire so HR can respond directly.
            $replyTo = (string) ($this->mailCfg['reply_to'] ?? '');
            if ($replyTo === '' && !empty($data['primary_email'])) {
                $replyTo = (string) $data['primary_email'];
            }
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $employee = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
                $mail->addReplyTo($replyTo, $employee);
            }

            // Real submissions are BCC'd to the payroll archive; test ones are not.
            $bcc = (string) ($this->mailCfg['bcc'] ?? '');
            if (!$isTest && $bcc !== '' && filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                $mail->addBCC($bcc);
            }

            $employee = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: 'New Hire';

            $mail->Subject = ($isTest ? "[TEST] " : "") . "New Hire Setup Package - {$employee}";
            $mail->isHTML(true);
            $mail->Body    = $this->htmlBody($employee, $data, $meta, $package);
            $mail->AltBody = $this->textBody($employee, $data, $meta, $package);

            if (!is_file($package['path'])) {
                throw new \RuntimeException('Encrypted package is missing.');
            }
            $mail->addAttachment($package['path'], (string) $package['filename'], PHPMailer::ENCODING_BASE64, 'application/octet-stream');

            if ($transport === 'log') {
                // Compose but do not send — write the raw message to disk so the
                // flow can be verified before SMTP credentials are configured.
                // This persists PII to disk, so it is dev/testing only and is
                // refused in production.
                if (!$isTest && \cfg('app.env', 'production') === 'production') {
                    throw new \RuntimeException("mail.transport 'log' writes submissions to disk and is not permitted in production. Set mail.transport = 'smtp'.");
                }
                if (!$mail->preSend()) {
                    throw new \RuntimeException('Mail could not be composed: ' . $mail->ErrorInfo);
                }
                $dir = (string) ($this->mailCfg['log_dir'] ?? sys_get_temp_dir());
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
                $file = rtrim($dir, '/') . '/onboarding_' . date('Ymd_His') . '_' . Support::token(3) . '.eml';
                if (@file_put_contents($file, $mail->getSentMIMEMessage()) === false) {
                    throw new \RuntimeException('Could not write the mail log file. Check mail.log_dir permissions.');
                }
                @chmod($file, 0600);
                return $note;
            }

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new \RuntimeException('Email could not be sent: ' . $mail->ErrorInfo, 0, $e);
        }
        return $note;
    }

    private function htmlBody(string $employee, array $data, array $meta, array $package): string
    {
        $e = fn($s) => Support::e((string) $s);
        $contact = FieldMap::CONTACT_METHODS[$data['preferred_contact'] ?? ''] ?? '—';
        $rows = [
            ['Employee', $employee],
            ['Preferred contact', $contact . (!empty($data['primary_email']) ? ' · ' . $data['primary_email'] : '') . (!empty($data['mobile_phone']) ? ' · ' . $data['mobile_phone'] : '')],
            ['Submitted', (string) ($meta['timestamp'] ?? '')],
            ['Reference', (string) ($meta['reference'] ?? '')],
        ];
        $tr = '';
        foreach ($rows as [$k, $v]) {
            $tr .= '<tr><td style="padding:4px 12px 4px 0;color:#666;white-space:nowrap;vertical-align:top;">' . $e($k) . '</td>'
                . '<td style="padding:4px 0;color:#111;font-weight:600;">' . $e($v) . '</td></tr>';
        }
        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111;line-height:1.5;max-width:600px;">'
            . '<p style="font-size:18px;font-weight:700;margin:0 0 4px;">Legends Global — New Hire Submission</p>'
            . '<p style="margin:0 0 16px;color:#555;">A new hire has securely submitted their onboarding information.</p>'
            . '<table style="border-collapse:collapse;font-size:14px;">' . $tr . '</table>'
            . '<div style="margin:18px 0;padding:12px 14px;background:#f4f6f8;border:1px solid #e2e6ea;border-radius:6px;">'
            . '<strong>🔒 The full submission is in the encrypted attachment.</strong><br>'
            . 'File: <code>' . $e($package['filename']) . '</code><br>'
            . 'Open it with the shared passphrase (provided separately — never in this email).'
            . '</div>'
            . ($package['method'] === 'zip-aes256'
                ? '<div style="margin:0 0 18px;padding:12px 14px;background:#fdf6e3;border:1px solid #f0e2b6;border-radius:6px;font-size:13px;">'
                    . '<strong>Windows users:</strong> Windows\' built-in ZIP extractor cannot open AES-encrypted archives '
                    . '(it fails with &ldquo;error 0x80004005&rdquo;). Use the free <a href="https://www.7-zip.org">7-Zip</a>: '
                    . 'right-click the file → 7-Zip → Extract Here, then enter the passphrase. '
                    . 'On a Mac, Keka or The Unarchiver works if the built-in tool cannot open it.'
                    . '</div>'
                : '<p style="font-size:13px;color:#555;">See the decryption note included with your onboarding setup instructions.</p>')
            . '<p style="color:#999;font-size:12px;margin-top:18px;">This message and its attachment contain confidential personal information. '
            . 'Handle per the Legends Global privacy policy and applicable privacy law. Nothing is stored on the web application — this email is the only copy.</p>'
            . '</div>';
    }

    private function textBody(string $employee, array $data, array $meta, array $package): string
    {
        $contact = FieldMap::CONTACT_METHODS[$data['preferred_contact'] ?? ''] ?? '-';
        $l = [];
        $l[] = 'LEGENDS GLOBAL — New Hire Submission';
        $l[] = '';
        $l[] = 'A new hire has securely submitted their onboarding information.';
        $l[] = '';
        $l[] = 'Employee : ' . $employee;
        $l[] = 'Contact  : ' . $contact . (!empty($data['primary_email']) ? ' / ' . $data['primary_email'] : '');
        $l[] = 'Submitted: ' . (string) ($meta['timestamp'] ?? '');
        $l[] = 'Reference: ' . (string) ($meta['reference'] ?? '');
        $l[] = '';
        $l[] = 'The full submission is in the encrypted attachment:';
        $l[] = '  ' . $package['filename'];
        $l[] = 'Open it with the shared passphrase (provided separately).';
        $l[] = '';
        $l[] = 'WINDOWS USERS: Windows\' built-in ZIP extractor cannot open';
        $l[] = 'AES-encrypted archives (it fails with error 0x80004005).';
        $l[] = 'Use the free 7-Zip (www.7-zip.org): right-click the file ->';
        $l[] = '7-Zip -> Extract Here, then enter the passphrase.';
        $l[] = '';
        $l[] = 'Confidential — handle per the Legends Global privacy policy.';
        return implode("\r\n", $l);
    }
}
