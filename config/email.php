<?php
function getEmailConfig() {
    return [
        'host'       => getenv('GT_SMTP_HOST')       ?: 'smtp.gmail.com',
        'port'       => (int)(getenv('GT_SMTP_PORT') ?: 465),
        'username'   => getenv('GT_SMTP_USER')       ?: 'noreply@len.com.mx',
        'password'   => getenv('GT_SMTP_PASSWORD')   ?: 'byjyoiewkfhiuqnw',
        'from_email' => getenv('GT_SMTP_FROM_EMAIL') ?: 'noreply@len.com.mx',
        'from_name'  => getenv('GT_SMTP_FROM_NAME')  ?: 'RH Sistema',
        'secure'     => getenv('GT_SMTP_SECURE')     ?: 'ssl',
    ];
}
