<?php
declare(strict_types=1);

function require_patient(): int
{
    if (($_SESSION['role'] ?? '') !== 'patient' || !isset($_SESSION['patient_id'])) {
        respond(401, false, 'Please sign in to continue.');
    }
    return (int) $_SESSION['patient_id'];
}
