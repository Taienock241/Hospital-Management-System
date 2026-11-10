<?php
declare(strict_types=1);

// Both portals use the same PostgreSQL connection settings and schema.
require_once dirname(__DIR__, 3) . '/staff/backend/config/database.php';

$pdo = database();
