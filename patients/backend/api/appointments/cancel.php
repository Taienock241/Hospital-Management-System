<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../controllers/AppointmentController.php';
handle_api_errors(static fn() => AppointmentController::cancel());
