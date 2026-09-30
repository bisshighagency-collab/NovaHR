<?php
/**
 * Minimal, dependency-free JWT (HS256) implementation.
 *
 * This avoids requiring Composer/firebase/php-jwt so the project runs on a
 * bare PHP + MySQL setup (e.g. XAMPP) with no extra install step. It
 * implements exactly the subset of the JWT spec NovaHR needs: HS256 signing,
 * an expiry claim, and signature verification.
 */

define('JWT_SECRET', getenv('NOVAHR_JWT_SECRET') ?: 'novahr-dev-secret-change-me');
define('JWT_TTL_SECONDS', 60 * 60 * 8); // 8 hour session

function jwt_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwt_base64url_decode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/** @param array<string,mixed> $claims */
function jwt_encode(array $claims): string
{
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $claims['iat'] = time();
    $claims['exp'] = time() + JWT_TTL_SECONDS;

    $segments = [
        jwt_base64url_encode(json_encode($header)),
        jwt_base64url_encode(json_encode($claims)),
    ];
    $signingInput = implode('.', $segments);
    $signature = hash_hmac('sha256', $signingInput, JWT_SECRET, true);
    $segments[] = jwt_base64url_encode($signature);

    return implode('.', $segments);
}

/**
 * Verifies signature + expiry. Returns the decoded claims array, or null if
 * the token is missing, malformed, expired, or has an invalid signature.
 *
 * @return array<string,mixed>|null
 */
function jwt_decode(?string $token): ?array
{
    if (!$token || substr_count($token, '.') !== 2) {
        return null;
    }
    [$headerB64, $payloadB64, $sigB64] = explode('.', $token);

    $signingInput = $headerB64 . '.' . $payloadB64;
    $expectedSig = jwt_base64url_encode(hash_hmac('sha256', $signingInput, JWT_SECRET, true));
    if (!hash_equals($expectedSig, $sigB64)) {
        return null;
    }

    $claims = json_decode(jwt_base64url_decode($payloadB64), true);
    if (!is_array($claims) || !isset($claims['exp']) || $claims['exp'] < time()) {
        return null;
    }
    return $claims;
}
