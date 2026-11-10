<?php
declare(strict_types=1);

require_once __DIR__ . '/config/environment.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/utils/response.php';
require_once __DIR__ . '/utils/validation.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/csrf.php';
require_once __DIR__ . '/middleware/admin.php';
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

set_exception_handler(static function (Throwable $exception): void {
    error_log('Hopewell uncaught exception: ' . $exception->getMessage());
    respond(500, false, 'The request could not be completed. Please try again later.');
});

require_once __DIR__ . '/config/database.php';
