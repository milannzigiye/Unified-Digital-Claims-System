<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Load local key=value settings from .env once.
 */
function app_load_env_file(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

/**
 * Read an environment variable with a safe fallback.
 */
function app_env(string $key, ?string $default = null): ?string
{
    app_load_env_file();

    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function app_env_value_is_placeholder(?string $value): bool
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return true;
    }

    return str_contains($value, 'your-smtp-')
        || str_contains($value, 'your_sender_email')
        || str_contains($value, 'your_app_password')
        || $value === 'no-reply@example.com';
}

/**
 * Return normalized SMTP settings from environment variables.
 */
function app_mail_config(): array
{
    $host = app_env('SMTP_HOST', 'smtp.gmail.com');
    $port = (int) app_env('SMTP_PORT', '587');
    $username = app_env('SMTP_USERNAME');
    $password = app_env('SMTP_PASSWORD', app_env('SMTP_PASS'));
    $fromEmail = app_env('SMTP_FROM_EMAIL', $username ?? 'no-reply@bk.rw');
    $fromName = app_env('SMTP_FROM_NAME', 'UNIFIED DIGITAL CLAIMS SYSTEM');

    if (app_env_value_is_placeholder($username) || app_env_value_is_placeholder($password)) {
        throw new RuntimeException('SMTP_USERNAME and SMTP_PASSWORD must be set in environment variables.');
    }
    if (app_env_value_is_placeholder($fromEmail)) {
        $fromEmail = $username;
    }

    return [
        'host' => $host,
        'port' => $port > 0 ? $port : 587,
        'username' => $username,
        'password' => $password,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'allow_insecure_tls' => in_array(strtolower((string) app_env('SMTP_ALLOW_INSECURE_TLS', '0')), ['1', 'true', 'yes'], true),
        'timeout' => max(5, (int) app_env('SMTP_TIMEOUT', '20')),
    ];
}

/**
 * Configure PHPMailer with environment-based SMTP settings.
 */
function configure_mailer(PHPMailer $mail, string $fromName): void
{
    $smtp = app_mail_config();

    $mail->isSMTP();
    $mail->Host = (string) $smtp['host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $smtp['username'];
    $mail->Password = (string) $smtp['password'];
    $mail->Port = (int) $smtp['port'];
    $mail->SMTPSecure = (int) $smtp['port'] === 465
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = (int) $smtp['timeout'];
    if (!empty($smtp['allow_insecure_tls'])) {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }
    $mail->setFrom((string) $smtp['from_email'], $fromName !== '' ? $fromName : (string) $smtp['from_name']);
}
