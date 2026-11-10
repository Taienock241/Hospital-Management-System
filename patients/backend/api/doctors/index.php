<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../controllers/DirectoryController.php';
handle_api_errors(static fn() => DirectoryController::doctors());
