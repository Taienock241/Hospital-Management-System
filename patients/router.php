<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($requestPath === '/') {
    header('Location: /frontend/index.html', true, 302);
    return true;
}

// Expose API PHP files only; keep backend configuration and source private.
if (str_starts_with($requestPath, '/backend/')) {
    $apiRoot = realpath(__DIR__ . '/backend/api');
    $candidate = realpath(__DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $requestPath));
    if (
        $apiRoot === false ||
        $candidate === false ||
        !str_starts_with($candidate, $apiRoot . DIRECTORY_SEPARATOR) ||
        !str_ends_with(strtolower($candidate), '.php')
    ) {
        http_response_code(404);
        return true;
    }

    require $candidate;
    return true;
}

return false;
