<?php
declare(strict_types=1);

// Authentication always precedes file serving, including conditional/range requests.
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

function atlasError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}

try {
    $runtime = __DIR__ . '/.atlas-auth';
    require $runtime . '/vendor/autoload.php';
    require $runtime . '/verify.php';
    require $runtime . '/login.php';
    $config = json_decode(file_get_contents($runtime . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
    $pem = file_get_contents($runtime . '/clerk-public-key.pem');
    if (!is_array($config) || !$pem || !openssl_pkey_get_public($pem)) throw new RuntimeException('Invalid auth configuration');
} catch (Throwable $error) {
    atlasError(503, 'Secure access is temporarily unavailable.');
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) atlasError(405, 'Method not allowed');
$rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!is_string($rawPath) || preg_match('/%(?![a-fA-F0-9]{2})/', $rawPath)) atlasError(400, 'Invalid path');
$path = rawurldecode($rawPath);
if (!str_starts_with($path, $config['base']) || preg_match('/[\\\\\x00-\x1f%]/', $path)
    || preg_match('~(?:^|/)\.~', $path)) atlasError(404, 'Not found');
$relative = substr($path, strlen($config['base']));
if ($relative === 'release.json') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') readfile(__DIR__ . '/.atlas/release.json');
    exit;
}

$token = $_COOKIE['__session'] ?? '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (!preg_match('/^Bearer ([A-Za-z0-9_.-]+)$/D', $_SERVER['HTTP_AUTHORIZATION'], $match)) atlasError(401, 'Sign-in required');
    $token = $match[1];
}
$claims = atlasVerifySession(is_string($token) ? $token : '', $config, $pem);
if (!$claims || $relative === 'sign-in') {
    // Assets/data never return the sign-in HTML as a successful download.
    $document = $relative === '' || $relative === 'sign-in'
        || ($relative !== 'session' && !preg_match('~^(?:assets|data|fonts|licenses)(?:/|$)~', $relative)
            && (pathinfo($relative, PATHINFO_EXTENSION) === '' || str_ends_with($relative, '.html')));
    if (!$document || isset($_SERVER['HTTP_AUTHORIZATION'])) atlasError(401, 'Sign-in required');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') atlasLoginPage($config);
    exit;
}
if ($relative === 'session') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') echo '{"authenticated":true}';
    exit;
}

$root = realpath(__DIR__ . '/.atlas');
$candidate = $root . '/' . $relative;
if (is_dir($candidate)) $candidate .= '/index.html';
if (!is_file($candidate) && pathinfo($relative, PATHINFO_EXTENSION) === ''
    && !preg_match('~^(?:assets|data|fonts|licenses)(?:/|$)~', $relative)) $candidate = $root . '/index.html';
$file = realpath($candidate);
if (!$root || !$file || !str_starts_with($file, $root . '/') || !is_file($file)) atlasError(404, 'Not found');
$types = ['html'=>'text/html; charset=utf-8', 'js'=>'text/javascript; charset=utf-8', 'mjs'=>'text/javascript; charset=utf-8',
    'css'=>'text/css; charset=utf-8', 'json'=>'application/json', 'geojson'=>'application/geo+json', 'csv'=>'text/csv; charset=utf-8',
    'svg'=>'image/svg+xml', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp', 'ico'=>'image/x-icon',
    'woff'=>'font/woff', 'woff2'=>'font/woff2', 'ttf'=>'font/ttf', 'otf'=>'font/otf', 'txt'=>'text/plain; charset=utf-8', 'md'=>'text/plain; charset=utf-8',
    'pdf'=>'application/pdf', 'pbf'=>'application/x-protobuf', 'wasm'=>'application/wasm'];
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!isset($types[$extension])) atlasError(404, 'Not found');
header('Content-Type: ' . $types[$extension]);
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
if ($extension === 'html') {
    $html = file_get_contents($file);
    echo str_replace('</head>', atlasClerkScripts($config, false) . '</head>', $html);
} else {
    readfile($file);
}
