<?php
declare(strict_types=1);

function require_admin(): int
{
    if (($_SESSION['role'] ?? '') !== 'admin' || !isset($_SESSION['user_id'])) {
        respond(403, false, 'Administrator access is required.');
    }
    return (int) $_SESSION['user_id'];
}
