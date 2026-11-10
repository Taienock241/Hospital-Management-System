<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/') {
    header('Location: /patients/frontend/index.html', true, 302);
    return true;
}
if ($path === '/patients' || $path === '/patients/') {
    header('Location: /patients/frontend/index.html', true, 302);
    return true;
}
if ($path === '/staff' || $path === '/staff/') {
    header('Location: /staff/login.html', true, 302);
    return true;
}

// The staff portal API remains the single shared admin/staff API.
if ($path === '/api' || str_starts_with($path, '/api/')) {
    require __DIR__ . '/staff/backend/public/api.php';
    return true;
}

// The patient pages keep their existing URL contract, under one patient-only prefix.
if (str_starts_with($path, '/patients/backend/api/')) {
    $apiRoot = realpath(__DIR__ . '/patients/backend/api');
    $candidate = realpath(__DIR__ . str_replace('/', DIRECTORY_SEPARATOR, rawurldecode($path)));
    if ($apiRoot === false || $candidate === false ||
        !str_starts_with($candidate, $apiRoot . DIRECTORY_SEPARATOR) ||
        strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php') {
        http_response_code(404);
        exit;
    }
    require $candidate;
    return true;
}
if (str_starts_with($path, '/patients/backend/')) {
    http_response_code(404);
    exit;
}

$routes = [
    '/patients/frontend/' => __DIR__ . '/patients/frontend/',
    '/patients/assets/' => __DIR__ . '/patients/assets/',
    '/staff/' => __DIR__ . '/staff/frontend/',
];
foreach ($routes as $prefix => $directory) {
    if (str_starts_with($path, $prefix)) {
        $relative = rawurldecode(substr($path, strlen($prefix)));
        $root = realpath($directory);
        $file = realpath($directory . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        if ($root === false || $file === false || !is_file($file) ||
            !str_starts_with($file, $root . DIRECTORY_SEPARATOR) ||
            strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
            http_response_code(404);
            exit;
        }

        $types = [
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'text/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($types[$extension])) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $types[$extension]);
        readfile($file);
        return true;
    }
}

http_response_code(404);
echo 'Not found';
return true;
