<?php
declare(strict_types=1);

$conn = mysqli_connect("localhost", "root", "", "udcs");

if (!$conn) {
    error_log('UDCS database connection failed: ' . mysqli_connect_error());
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    exit('Service temporarily unavailable.');
}
