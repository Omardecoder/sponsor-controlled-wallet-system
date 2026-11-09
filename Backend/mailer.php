<?php
// Backend/mailer.php
// Sends email through SMTP (Gmail) with PHPMailer, bundled in Backend/lib/PHPMailer (no Composer needed).
// Settings come from mail_config.php (copy mail_config.example.php). Without that file, MAIL_READY is
// false and send_mail() returns false, so the rest of the system keeps working.
// Usage:  require_once __DIR__ . '/mailer.php';  send_mail($to, $name, $subject, $html, $text);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

if (!defined('MAIL_READY')) {
    define('MAIL_READY', is_file(__DIR__ . '/mail_config.php'));
}
if (MAIL_READY) {
    require_once __DIR__ . '/mail_config.php';
}

/** Sends one email. Returns true if the mail server accepted it. Never throws. */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool
{
    if (!MAIL_READY) {
        error_log('send_mail(): mail_config.php is missing, email to ' . $toEmail . ' not sent');
        return false;
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = (int) MAIL_PORT;
        $mail->SMTPAuth   = MAIL_USERNAME !== '';
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : (MAIL_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $mail->SMTPAutoTLS = MAIL_SECURE !== '';
        $mail->Timeout    = 20;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('send_mail() failed for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/** A simple branded HTML email: a heading, paragraphs (already escaped) and an optional button. */
function mail_layout(string $heading, array $paragraphs, ?string $buttonText = null, ?string $buttonUrl = null): string
{
    $body = '';
    foreach ($paragraphs as $p) {
        $body .= '<p style="margin:0 0 14px;line-height:1.5;">' . $p . '</p>';
    }
    if ($buttonText && $buttonUrl) {
        $body .= '<p style="margin:22px 0;"><a href="' . htmlspecialchars($buttonUrl) . '" style="background:#0f3d3a;color:#ffffff;'
               . 'padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;">'
               . htmlspecialchars($buttonText) . '</a></p>';
    }
    return '<div style="font-family:Arial,Helvetica,sans-serif;color:#1d2b29;max-width:520px;margin:0 auto;padding:24px;">'
         . '<div style="font-size:20px;font-weight:700;color:#0f3d3a;margin-bottom:18px;">SS-Wallet</div>'
         . '<h1 style="font-size:20px;margin:0 0 14px;">' . htmlspecialchars($heading) . '</h1>' . $body
         . '<p style="margin-top:28px;font-size:12px;color:#6b7a78;">This email was sent by SS-Wallet. If you did not expect it, you can ignore it.</p></div>';
}