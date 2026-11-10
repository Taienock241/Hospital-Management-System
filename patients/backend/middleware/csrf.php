<?php
declare(strict_types=1);

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        respond(403, false, 'Your session token has expired. Refresh the page and try again.');
    }
}
