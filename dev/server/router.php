<?php

declare(strict_types=1);

$publicDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'public_html';
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = rawurldecode((string) parse_url($requestUri, PHP_URL_PATH));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

function mcpfm_dev_send_json(int $statusCode, array $payload): bool
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    return true;
}

if ($path === '') {
    $path = '/';
}

$candidate = $publicDir . str_replace('/', DIRECTORY_SEPARATOR, $path);

if ($path !== '/' && is_file($candidate)) {
    return false;
}

if ($path === '/favicon.ico') {
    http_response_code(204);
    return true;
}

$oauthDiscoveryPrefixes = [
    '/.well-known/oauth-protected-resource',
    '/.well-known/oauth-authorization-server',
    '/.well-known/openid-configuration',
];

foreach ($oauthDiscoveryPrefixes as $prefix) {
    if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
        if ($method === 'OPTIONS') {
            http_response_code(204);
            header('Allow: GET, OPTIONS');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            return true;
        }

        return mcpfm_dev_send_json(404, [
            'error' => 'oauth_not_implemented',
            'message' => 'OAuth and OIDC discovery endpoints are not implemented in this server.',
            'detail' => 'Use the request_auth MCP tool and then send Authorization: Bearer <token> manually.',
            'path' => $path,
        ]);
    }
}

$defaultEntrypoint = $publicDir . DIRECTORY_SEPARATOR . 'mcpfm.php';

if (!is_file($defaultEntrypoint)) {
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$_SERVER['SCRIPT_NAME'] = '/mcpfm.php';
$_SERVER['PHP_SELF'] = '/mcpfm.php';
$_SERVER['SCRIPT_FILENAME'] = $defaultEntrypoint;

require $defaultEntrypoint;
return true;
