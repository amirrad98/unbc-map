<?php
declare(strict_types=1);

/** The HTTP dispatcher never accepts an upstream URL or transport configuration. */
function atlasProxyTarget(string $target): string
{
    if (strlen($target) > 16384 || preg_match('/[\x00-\x20\\\\#]/', $target)
        || preg_match('/%(?![0-9a-fA-F]{2})/', $target)) throw new InvalidArgumentException('Invalid proxy path');
    $path = rawurldecode(explode('?', $target, 2)[0]);
    if (str_contains($path, '%') || preg_match('/[\x00-\x20\\\\#]/', $path) || str_contains($path, '//')
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) throw new InvalidArgumentException('Invalid proxy path');
    if ($path !== '' && $path !== '.well-known/jwks.json'
        && !preg_match('~^v1(?:/|$)~', $path)
        && !preg_match('~^npm/@clerk/(?:clerk-js@6(?:\.[0-9]+){0,2}|ui@1(?:\.[0-9]+){0,2})/dist/[a-zA-Z0-9._/-]+$~', $path))
        throw new InvalidArgumentException('Unknown proxy path');
    if (preg_match('~^v1/(?:users|sessions|domains|instances|invitations|webhooks|api_keys|jwks)(?:/|$)~i', $path))
        throw new InvalidArgumentException('Backend API paths are not proxied');
    return 'https://frontend-api.clerk.dev/' . $target;
}

function atlasProxyHeaders(array $incoming, string $secret, string $proxyUrl, string $clientIp): array
{
    if (!filter_var($clientIp, FILTER_VALIDATE_IP)) throw new InvalidArgumentException('Invalid client IP');
    $skip = ['host','connection','proxy-connection','content-length','transfer-encoding','te','trailer','upgrade',
        'accept-encoding','expect','forwarded','x-real-ip','true-client-ip','clerk-secret-key','clerk-proxy-url'];
    // RFC hop-by-hop tokens supplied in Connection cannot be forwarded either.
    foreach ($incoming as $name => $value) if (strtolower($name) === 'connection')
        $skip = array_merge($skip, array_map('trim', explode(',', strtolower($value))));
    $headers = [];
    foreach ($incoming as $name => $value) {
        $lower = strtolower($name);
        if (in_array($lower, $skip, true) || str_starts_with($lower, 'x-forwarded-')
            || str_starts_with($lower, 'cf-') || str_starts_with($lower, 'x-clerk-')) continue;
        if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+.^_`|~-]+$/D', $name) || preg_match('/[\r\n\x00]/', $value))
            throw new InvalidArgumentException('Invalid request header');
        $headers[] = $name . ': ' . $value;
    }
    $headers[] = 'Clerk-Proxy-Url: ' . $proxyUrl;
    $headers[] = 'Clerk-Secret-Key: ' . $secret;
    $headers[] = 'X-Forwarded-For: ' . $clientIp;
    return $headers;
}

/** Direct-call test seams never flow from HTTP, env vars or production config. */
function atlasCurlTransport(string $url, string $method, array $headers, string $body,
    ?string $testCa = null, int $timeoutMs = 20000, int $limit = 16777216): array
{
    $response = '';
    $responseHeaders = [];
    $curl = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT_MS => min(5000, $timeoutMs),
        CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            if (preg_match('~^HTTP/\S+ \d+~', $line)) $responseHeaders = [];
            elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[] = [trim($name), trim($value)];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, $limit): int {
            if (strlen($response) + strlen($chunk) > $limit) return 0;
            $response .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($testCa !== null) $options[CURLOPT_CAINFO] = $testCa;
    if (!in_array($method, ['GET','HEAD'], true)) $options[CURLOPT_POSTFIELDS] = $body;
    if ($method === 'HEAD') $options[CURLOPT_NOBODY] = true;
    curl_setopt_array($curl, $options);
    if (curl_exec($curl) === false) {
        curl_close($curl);
        // Do not log cURL URLs, headers, cookies, request bodies or credentials.
        throw new RuntimeException('Clerk proxy transport unavailable');
    }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status < 100 || $status > 599) throw new RuntimeException('Invalid upstream status');
    return ['status'=>$status, 'headers'=>$responseHeaders, 'body'=>$response];
}

function atlasProxyResponse(array $response, string $secret, array $config): array
{
    $headers = [];
    $skip = ['connection','proxy-connection','content-length','transfer-encoding','te','trailer','upgrade',
        'content-encoding','cache-control','expires','etag','last-modified','clerk-secret-key','clerk-proxy-url'];
    foreach ($response['headers'] as [$name, $value]) if (strtolower($name) === 'connection')
        $skip = array_merge($skip, array_map('trim', explode(',', strtolower($value))));
    foreach ($response['headers'] as [$name, $value]) {
        if (in_array(strtolower($name), $skip, true) || preg_match('/[\r\n\x00]/', $name . $value)) continue;
        if (strtolower($name) === 'location') {
            foreach ([$config['issuer'], 'https://frontend-api.clerk.dev'] as $origin) {
                if ($value === $origin || str_starts_with($value, $origin . '/')) {
                    $value = $config['proxyUrl'] . substr($value, strlen($origin));
                    break;
                }
            }
        }
        $headers[] = [$name, str_replace($secret, '[redacted]', $value)];
    }
    return ['status'=>$response['status'], 'headers'=>$headers, 'body'=>str_replace($secret, '[redacted]', $response['body'])];
}

function atlasServeProxy(array $config, string $target): never
{
    try { $url = atlasProxyTarget($target); }
    catch (InvalidArgumentException $error) { atlasError(404, 'Not found'); }
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS'], true)) atlasError(405, 'Method not allowed');
    // This is outside public_html, outside the managed checkout and never packaged.
    $secretPath = dirname(__DIR__, 3) . '/.nhhr-auth/clerk-secret';
    $private = is_file($secretPath) && !is_link($secretPath) && !is_link(dirname($secretPath))
        && (fileperms($secretPath) & 0077) === 0;
    $secret = $private ? trim((string) @file_get_contents($secretPath)) : '';
    if (!preg_match('/^sk_live_[a-zA-Z0-9_-]{20,}$/D', $secret)) {
        // Inactive-deployment verification: caller can compare their own IP hash.
        // No raw IP, credential or visitor log is exposed. Disappears on activation.
        if ($target === '' && filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP))
            header('X-NHHR-Proxy-IP-Check: ' . hash('sha256', $_SERVER['REMOTE_ADDR']));
        atlasError(503, 'Secure sign-in proxy is not configured.');
    }
    if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 1048576) atlasError(413, 'Request too large');
    $stream = fopen('php://input', 'rb');
    $body = stream_get_contents($stream, 1048577);
    fclose($stream);
    if ($body === false || strlen($body) > 1048576) atlasError(413, 'Request too large');
    try {
        $headers = atlasProxyHeaders(getallheaders(), $secret, $config['proxyUrl'], $_SERVER['REMOTE_ADDR'] ?? '');
        $response = atlasProxyResponse(atlasCurlTransport($url, $method, $headers, $body), $secret, $config);
    } catch (Throwable $error) { atlasError(502, 'Secure sign-in service is temporarily unavailable.'); }
    http_response_code($response['status']);
    foreach ($response['headers'] as [$name, $value]) header($name . ': ' . $value, false);
    if ($method !== 'HEAD') echo $response['body'];
    exit;
}
