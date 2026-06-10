<?php
declare(strict_types=1);

// Backward-compatible shim. Mail configuration now lives in app_config.php
// and must come from environment variables instead of hard-coded secrets.

require_once __DIR__ . '/app_config.php';

return app_mail_config();
