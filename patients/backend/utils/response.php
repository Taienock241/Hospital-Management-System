<?php
declare(strict_types=1);

function respond(int $status, bool $success, string $message, mixed $data = null, array $errors = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $body['data'] = $data;
    }
    if ($errors !== []) {
        $body['errors'] = $errors;
    }
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_method(string ...$methods): void
{
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        respond(405, false, 'Method not allowed.');
    }
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        respond(400, false, 'A valid JSON request body is required.');
    }
    return $data;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function handle_api_errors(callable $action): void
{
    try {
        $action();
    } catch (Throwable $exception) {
        error_log('Hopewell API error: ' . $exception->getMessage());
        respond(500, false, 'The request could not be completed. Please try again later.');
    }
}
