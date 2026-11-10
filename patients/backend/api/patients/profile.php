<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../controllers/PatientController.php';
handle_api_errors(static function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT') {
        PatientController::updateProfile();
    }
    PatientController::profile();
});
