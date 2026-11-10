<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../controllers/AuthController.php';
handle_api_errors(static fn() => AuthController::register());
