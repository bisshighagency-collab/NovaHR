<?php
/** Small JSON response + request-body helpers shared by every controller. */

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

/** @return array<string,mixed> */
function request_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_fields(array $body, array $fields): void
{
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($body[$field]) || $body[$field] === '') {
            $missing[] = $field;
        }
    }
    if ($missing) {
        json_error('Missing required field(s): ' . implode(', ', $missing), 422);
    }
}
