<?php

function send_email_smtp($toEmails, $subject, $htmlBody, $plainBody = '', $attachments = []) {
    // Requiere instalar: composer require phpmailer/phpmailer
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException('No se encontro vendor/autoload.php. Instala PHPMailer con Composer.');
    }

    require_once $autoload;
    require_once __DIR__ . '/../config/email.php';

    $cfg = getEmailConfig();

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = $cfg['port'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];

    if (strtolower((string)$cfg['secure']) === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);

    foreach ($toEmails as $email) {
        $email = trim((string)$email);
        if ($email !== '') {
            $mail->addAddress($email);
        }
    }

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $plainBody !== '' ? $plainBody : strip_tags($htmlBody);

    // Adjuntos opcionales: cada item puede ser string (ruta) o ['path' => ..., 'name' => ...]
    foreach ($attachments as $attachment) {
        $path = null;
        $name = null;

        if (is_string($attachment)) {
            $path = $attachment;
        } elseif (is_array($attachment)) {
            $path = isset($attachment['path']) ? (string)$attachment['path'] : null;
            $name = isset($attachment['name']) ? (string)$attachment['name'] : null;
        }

        if (!$path || !is_file($path)) {
            continue;
        }

        if ($name) {
            $mail->addAttachment($path, $name);
        } else {
            $mail->addAttachment($path);
        }
    }

    return $mail->send();
}
