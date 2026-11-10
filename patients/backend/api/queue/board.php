<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../controllers/QueueController.php';
handle_api_errors(static fn() => QueueController::board());
