<?php
declare(strict_types=1);

// Both portals use the same project configuration file.
$envFiles = [dirname(__DIR__, 3) . '/staff/backend/.env'];
foreach ($envFiles as $envFile) {
    if (!is_file($envFile) || !is_readable($envFile)) {
        continue;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Africa/Nairobi');
