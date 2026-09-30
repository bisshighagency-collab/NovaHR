<?php
/**
 * Authentication / RBAC middleware.
 *
 * require_auth()        -> any logged-in principal (admin, employee, or candidate)
 * require_role('admin') -> only that role may proceed
 */

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? apache_request_headers_safe()['Authorization']
        ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }
    // Fallback for endpoints opened as a plain link (e.g. a file download) rather
    // than fetched from JS, where a custom Authorization header can't be attached.
    if (!empty($_GET['token'])) {
        return $_GET['token'];
    }
    return null;
}

function apache_request_headers_safe(): array
{
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        return $h ?: [];
    }
    return [];
}

/** @return array<string,mixed> the decoded JWT claims */
function require_auth(): array
{
    $claims = jwt_decode(bearer_token());
    if (!$claims) {
        json_error('Unauthorized. Please log in again.', 401);
    }
    return $claims;
}

/** @return array<string,mixed> the decoded JWT claims */
function require_role(string ...$roles): array
{
    $claims = require_auth();
    if (!in_array($claims['role'], $roles, true)) {
        json_error('Forbidden. Your role does not have access to this resource.', 403);
    }
    return $claims;
}
