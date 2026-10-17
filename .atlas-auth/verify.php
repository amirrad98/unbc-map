<?php
declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** Offline verification with the production instance's public signing key. */
function atlasVerifySession(string $token, array $config, string $pem): ?object
{
    try {
        if ($token === '' || strlen($token) > 16384) return null;
        // The library pins RS256, validates signature, exp, nbf and iat.
        $claims = JWT::decode($token, new Key($pem, 'RS256'));
        if (($claims->iss ?? null) !== $config['issuer']
            || ($claims->azp ?? null) !== $config['origin']
            || !is_int($claims->exp ?? null)
            || !is_int($claims->iat ?? null)
            || !is_int($claims->nbf ?? null)
            || $claims->exp <= time()
            || $claims->nbf > time()
            || $claims->iat > time()
            || !is_string($claims->sub ?? null) || !str_starts_with($claims->sub, 'user_')
            || !is_string($claims->sid ?? null) || !str_starts_with($claims->sid, 'sess_')
            || (isset($claims->sts) && $claims->sts !== 'active')) return null;
        return $claims;
    } catch (Throwable $error) {
        return null;
    }
}

/** Only local Atlas URLs can be used after login. */
function atlasReturnTo(string $value, string $base): string
{
    if (!str_starts_with($value, $base) || preg_match('/[\\\\\x00-\x20]/', $value)) return $base;
    $path = rawurldecode(explode('?', explode('#', $value, 2)[0], 2)[0]);
    if (preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || (str_starts_with($path, $base . 'sign-in') || str_starts_with($path, $base . 'sign-up'))) return $base;
    return $value;
}
