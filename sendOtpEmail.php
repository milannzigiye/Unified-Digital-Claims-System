<?php
require 'vendor/autoload.php';
require_once __DIR__ . '/app_config.php';

use PHPMailer\PHPMailer\PHPMailer;

function sendOtpEmail($email, $name, $otp, &$errorMessage = null): bool {
    $errorMessage = null;

    try {
        $mail = new PHPMailer(true);

        // SECTION: Connect to SMTP and set the sender account.
        configure_mailer($mail, 'UNIFIED DIGITAL CLAIMS SYSTEM');
        $mail->addAddress($email, $name);

        // SECTION: Build and send the OTP message.
        $mail->isHTML(true);
        $mail->Subject = 'Your Login OTP Code';
        $mail->Body = "
            <p>Hello <strong>$name</strong>,</p>
            <p>Your OTP code is:</p>
            <h2 style='color:#0d6efd;'>$otp</h2>
            <p>This code expires in <strong>10 minutes</strong>.</p>
            <p>UNIFIED DIGITAL CLAIMS SYSTEM</p>
        ";

        $mail->send();
        return true;
    } catch (Throwable $e) {
        $errorMessage = 'Failed to send OTP email. Please verify the SMTP environment configuration and try again.';
        error_log('OTP send failed: ' . $e->getMessage());
        return false;
    }
}
