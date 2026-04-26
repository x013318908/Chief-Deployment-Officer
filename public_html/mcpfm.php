<?php

declare(strict_types=1);

const MCPFM_APP_NAME = 'MCP File Manager';
const MCPFM_APP_VERSION = '0.2.0-dev';
const MCPFM_JSONRPC_VERSION = '2.0';
const MCPFM_PROTOCOL_VERSION = '2025-11-25';
const MCPFM_SUPPORTED_PROTOCOL_VERSIONS = [
    '2025-11-25',
    '2025-06-18',
    '2025-03-26',
];
const MCPFM_AUTH_FILE_NAME = '.mcpfm_auth.json';
const MCPFM_DEBUG_LOG_FILE_NAME = '.mcpfm_debug.log';
const MCPFM_APPROVAL_QUERY_KEY = 'mcpfm_approve';
const MCPFM_AUTH_IDLE_SECONDS = 2592000;
const MCPFM_INTERNAL_FILE_PREFIX = '.mcpfm_';

function mcpfm_send_json(int $statusCode, array $payload, array $headers = []): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
}

function mcpfm_send_empty(int $statusCode, array $headers = []): void
{
    http_response_code($statusCode);

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
}

function mcpfm_get_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$serverKey])) {
        return (string) $_SERVER[$serverKey];
    }

    if ($name === 'Authorization') {
        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
            if (isset($_SERVER[$key])) {
                return (string) $_SERVER[$key];
            }
        }
    }

    return '';
}

function mcpfm_get_request_body(): string
{
    static $body;

    if ($body === null) {
        $body = (string) file_get_contents('php://input');
    }

    return $body;
}

function mcpfm_accepts_event_stream(): bool
{
    return stripos(mcpfm_get_header('Accept'), 'text/event-stream') !== false;
}

function mcpfm_request_host(): string
{
    return (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
}

function mcpfm_normalize_host(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (strpos($value, '://') !== false) {
        $host = (string) parse_url($value, PHP_URL_HOST);
    } else {
        $host = (string) parse_url('//' . $value, PHP_URL_HOST);
    }

    return strtolower(trim($host, '[]'));
}

function mcpfm_is_local_host(string $host): bool
{
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function mcpfm_origin_is_allowed(): bool
{
    $origin = mcpfm_get_header('Origin');

    if ($origin === '') {
        return true;
    }

    $originHost = mcpfm_normalize_host($origin);
    $requestHost = mcpfm_normalize_host(mcpfm_request_host());

    if ($originHost === '' || $requestHost === '') {
        return false;
    }

    if ($originHost === $requestHost) {
        return true;
    }

    return mcpfm_is_local_host($originHost) && mcpfm_is_local_host($requestHost);
}

function mcpfm_protocol_version_is_supported(?string $version): bool
{
    if ($version === null || $version === '') {
        return true;
    }

    return in_array($version, MCPFM_SUPPORTED_PROTOCOL_VERSIONS, true);
}

function mcpfm_is_list_array(array $value): bool
{
    $index = 0;

    foreach ($value as $key => $_) {
        if ($key !== $index) {
            return false;
        }

        $index++;
    }

    return true;
}

function mcpfm_negotiate_protocol_version(string $clientVersion): string
{
    if (in_array($clientVersion, MCPFM_SUPPORTED_PROTOCOL_VERSIONS, true)) {
        return $clientVersion;
    }

    return MCPFM_PROTOCOL_VERSION;
}

function mcpfm_jsonrpc_result($id, array $result, int $statusCode = 200, array $headers = []): void
{
    mcpfm_send_json($statusCode, [
        'jsonrpc' => MCPFM_JSONRPC_VERSION,
        'id' => $id,
        'result' => $result,
    ], $headers);
}

function mcpfm_jsonrpc_error($id, int $code, string $message, int $statusCode = 200, $data = null, array $headers = []): void
{
    $payload = [
        'jsonrpc' => MCPFM_JSONRPC_VERSION,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];

    if ($id !== null) {
        $payload['id'] = $id;
    }

    if ($data !== null) {
        $payload['error']['data'] = $data;
    }

    mcpfm_send_json($statusCode, $payload, $headers);
}

function mcpfm_tool_result(string $text, array $structuredContent): array
{
    return [
        'content' => [
            [
                'type' => 'text',
                'text' => $text,
            ],
        ],
        'structuredContent' => $structuredContent,
    ];
}

function mcpfm_now(): int
{
    return time();
}

function mcpfm_app_root(): string
{
    return __DIR__;
}

function mcpfm_auth_state_path(): string
{
    $override = getenv('MCPFM_AUTH_STATE_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return mcpfm_app_root() . DIRECTORY_SEPARATOR . MCPFM_AUTH_FILE_NAME;
}

function mcpfm_debug_log_path(): string
{
    $override = getenv('MCPFM_DEBUG_LOG_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return mcpfm_app_root() . DIRECTORY_SEPARATOR . MCPFM_DEBUG_LOG_FILE_NAME;
}

function mcpfm_hash_secret(string $value): string
{
    return hash('sha256', $value);
}

function mcpfm_generate_secret(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function mcpfm_get_request_scheme(): string
{
    $forwarded = strtolower(trim(mcpfm_get_header('X-Forwarded-Proto')));

    if ($forwarded !== '') {
        return $forwarded;
    }

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }

    if (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] !== '') {
        return (string) $_SERVER['REQUEST_SCHEME'];
    }

    return 'http';
}

function mcpfm_get_entrypoint_path(): string
{
    return '/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'mcpfm.php'));
}

function mcpfm_request_path(): string
{
    return (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
}

function mcpfm_debug_log(string $event, array $data = []): void
{
    $payload = [
        'time' => gmdate('c'),
        'event' => $event,
        'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'path' => mcpfm_request_path(),
        'mcpSessionId' => mcpfm_get_header('Mcp-Session-Id'),
        'data' => $data,
    ];

    error_log(
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        3,
        mcpfm_debug_log_path()
    );
}

function mcpfm_build_approval_url(string $secret): string
{
    return sprintf(
        '%s://%s%s?%s=%s',
        mcpfm_get_request_scheme(),
        mcpfm_request_host(),
        mcpfm_get_entrypoint_path(),
        MCPFM_APPROVAL_QUERY_KEY,
        rawurlencode($secret)
    );
}

function mcpfm_render_page(string $title, string $bodyHtml, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitle}</title>
  <style>
    :root {
      color-scheme: light dark;
      font-family: "Segoe UI", sans-serif;
      line-height: 1.5;
    }
    body {
      margin: 0;
      background: #f5f1ea;
      color: #1e1d1a;
    }
    main {
      max-width: 760px;
      margin: 0 auto;
      padding: 56px 20px 72px;
    }
    .panel {
      background: rgba(255, 255, 255, 0.84);
      border: 1px solid rgba(30, 29, 26, 0.12);
      border-radius: 20px;
      padding: 24px;
      box-shadow: 0 16px 40px rgba(55, 40, 14, 0.08);
    }
    code {
      font-family: Consolas, monospace;
      font-size: 0.95em;
    }
    p, li {
      margin: 0 0 12px;
    }
    ul {
      padding-left: 20px;
    }
    form {
      margin-top: 20px;
    }
    button {
      border: 0;
      border-radius: 999px;
      padding: 12px 20px;
      background: #1e1d1a;
      color: #f5f1ea;
      font: inherit;
      cursor: pointer;
    }
    @media (prefers-color-scheme: dark) {
      body {
        background: #161512;
        color: #f4efe7;
      }
      .panel {
        background: rgba(32, 30, 25, 0.9);
        border-color: rgba(244, 239, 231, 0.12);
        box-shadow: none;
      }
      button {
        background: #f4efe7;
        color: #161512;
      }
    }
  </style>
</head>
<body>
  <main>
    <section class="panel">
      {$bodyHtml}
    </section>
  </main>
</body>
</html>
HTML;
}

function mcpfm_render_default_page(): void
{
    $entrypoint = htmlspecialchars(mcpfm_get_entrypoint_path(), ENT_QUOTES, 'UTF-8');
    $appVersion = htmlspecialchars(MCPFM_APP_VERSION, ENT_QUOTES, 'UTF-8');
    $protocolVersion = htmlspecialchars(MCPFM_PROTOCOL_VERSION, ENT_QUOTES, 'UTF-8');

    $bodyHtml = <<<HTML
<h1>MCP File Manager</h1>
<p>単一PHPファイルのMCPエンドポイントです。現在は承認型認証と最小の読み取りツールを実装しています。</p>
<ul>
  <li>エントリポイント: <code>{$entrypoint}</code></li>
  <li>アプリバージョン: <code>{$appVersion}</code></li>
  <li>MCPプロトコル: <code>{$protocolVersion}</code></li>
  <li>公開ツール: <code>server_status</code>, <code>request_auth</code></li>
  <li>保護ツール: <code>list_dir</code></li>
  <li>QA: <code>composer qa</code></li>
</ul>
HTML;

    mcpfm_render_page('MCP File Manager', $bodyHtml);
}

function mcpfm_load_auth_state(): ?array
{
    $path = mcpfm_auth_state_path();

    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);

    if (!is_string($raw) || $raw === '') {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is unreadable. Delete .mcpfm_auth.json to reset.',
            'lockedAt' => mcpfm_now(),
        ];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is invalid. Delete .mcpfm_auth.json to reset.',
            'lockedAt' => mcpfm_now(),
        ];
    }

    if (!is_array($decoded)) {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is invalid. Delete .mcpfm_auth.json to reset.',
            'lockedAt' => mcpfm_now(),
        ];
    }

    $state = array_merge([
        'version' => 1,
        'state' => 'locked',
        'agentName' => null,
        'approvalSecret' => null,
        'pendingBearerToken' => null,
        'bearerTokenHash' => null,
        'issuedAt' => null,
        'approvedAt' => null,
        'lastUsedAt' => null,
        'lockedAt' => null,
        'message' => null,
    ], $decoded);

    if (!in_array($state['state'], ['pending', 'approved', 'locked'], true)) {
        $state['state'] = 'locked';
        $state['message'] = 'Auth state file is invalid. Delete .mcpfm_auth.json to reset.';
    }

    if ($state['state'] === 'pending') {
        if (!is_string($state['approvalSecret']) || $state['approvalSecret'] === ''
            || !is_string($state['pendingBearerToken']) || $state['pendingBearerToken'] === '') {
            $state['state'] = 'locked';
            $state['message'] = 'Pending auth state is invalid. Delete .mcpfm_auth.json to reset.';
        }
    }

    if ($state['state'] === 'approved') {
        if (!is_string($state['bearerTokenHash']) || $state['bearerTokenHash'] === '') {
            $state['state'] = 'locked';
            $state['message'] = 'Approved auth state is invalid. Delete .mcpfm_auth.json to reset.';
        }
    }

    return $state;
}

function mcpfm_save_auth_state(array $state): void
{
    $state['version'] = 1;

    file_put_contents(
        mcpfm_auth_state_path(),
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function mcpfm_refresh_auth_state(?array $state): ?array
{
    if ($state === null) {
        return null;
    }

    if (($state['state'] ?? null) !== 'approved') {
        return $state;
    }

    $lastUsedAt = (int) ($state['lastUsedAt'] ?? $state['approvedAt'] ?? 0);

    if ($lastUsedAt > 0 && (mcpfm_now() - $lastUsedAt) <= MCPFM_AUTH_IDLE_SECONDS) {
        return $state;
    }

    $state['state'] = 'locked';
    $state['lockedAt'] = mcpfm_now();
    $state['message'] = 'The MCP bearer token has expired after 30 days of inactivity. Delete .mcpfm_auth.json to reset.';
    mcpfm_save_auth_state($state);

    return $state;
}

function mcpfm_extract_bearer_token(string $value, bool $allowRaw = false): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/^Bearer\s+(.+)$/i', $value, $matches)) {
        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    if ($allowRaw) {
        return $value;
    }

    return null;
}

function mcpfm_get_bearer_token_state(): array
{
    $authorization = trim(mcpfm_get_header('Authorization'));
    $inspectorBearerToken = trim(mcpfm_get_header('X-MCPFM-Bearer-Token'));
    $inspectorAuthorization = trim(mcpfm_get_header('X-MCPFM-Authorization'));

    $token = mcpfm_extract_bearer_token($authorization);
    $source = $token !== null ? 'authorization' : null;

    if ($token === null) {
        $token = mcpfm_extract_bearer_token($inspectorBearerToken, true);
        $source = $token !== null ? 'x-mcpfm-bearer-token' : null;
    }

    if ($token === null) {
        $token = mcpfm_extract_bearer_token($inspectorAuthorization, true);
        $source = $token !== null ? 'x-mcpfm-authorization' : null;
    }

    return [
        'token' => $token,
        'source' => $source,
        'authorizationHeaderPresent' => $authorization !== '',
        'inspectorBearerHeaderPresent' => $inspectorBearerToken !== '',
        'inspectorAuthorizationHeaderPresent' => $inspectorAuthorization !== '',
    ];
}

function mcpfm_auth_debug_payload(array $authContext): array
{
    $token = $authContext['token'] ?? null;
    $state = $authContext['state'] ?? null;

    return [
        'authConfigured' => $state !== null,
        'authState' => $state['state'] ?? null,
        'authorized' => (bool) ($authContext['isAuthorized'] ?? false),
        'authReason' => (string) ($authContext['reason'] ?? 'unknown'),
        'authorizationHeaderPresent' => (bool) ($authContext['authorizationHeaderPresent'] ?? false),
        'inspectorBearerHeaderPresent' => (bool) ($authContext['inspectorBearerHeaderPresent'] ?? false),
        'inspectorAuthorizationHeaderPresent' => (bool) ($authContext['inspectorAuthorizationHeaderPresent'] ?? false),
        'bearerHeaderSource' => $authContext['tokenSource'] ?? null,
        'tokenHashPrefix' => is_string($token) && $token !== ''
            ? substr(mcpfm_hash_secret($token), 0, 12)
            : null,
        'storedTokenHashPrefix' => isset($state['bearerTokenHash']) && is_string($state['bearerTokenHash']) && $state['bearerTokenHash'] !== ''
            ? substr((string) $state['bearerTokenHash'], 0, 12)
            : null,
    ];
}

function mcpfm_log_auth_context(array $authContext): array
{
    mcpfm_debug_log('auth_context', mcpfm_auth_debug_payload($authContext));

    return $authContext;
}

function mcpfm_auth_context(): array
{
    $state = mcpfm_refresh_auth_state(mcpfm_load_auth_state());
    $tokenState = mcpfm_get_bearer_token_state();
    $token = $tokenState['token'];

    $context = [
        'state' => $state,
        'token' => $token,
        'tokenSource' => $tokenState['source'],
        'isAuthorized' => false,
        'reason' => 'missing_state',
        'authorizationHeaderPresent' => $tokenState['authorizationHeaderPresent'],
        'inspectorBearerHeaderPresent' => $tokenState['inspectorBearerHeaderPresent'],
        'inspectorAuthorizationHeaderPresent' => $tokenState['inspectorAuthorizationHeaderPresent'],
    ];

    if ($state === null) {
        return mcpfm_log_auth_context($context);
    }

    $context['reason'] = (string) ($state['state'] ?? 'unknown');

    if (($state['state'] ?? null) !== 'approved') {
        return mcpfm_log_auth_context($context);
    }

    if ($token === null) {
        $context['reason'] = 'missing_token';
        return mcpfm_log_auth_context($context);
    }

    if (!hash_equals((string) $state['bearerTokenHash'], mcpfm_hash_secret($token))) {
        $context['reason'] = 'invalid_token';
        return mcpfm_log_auth_context($context);
    }

    $context['isAuthorized'] = true;
    $context['reason'] = 'authorized';

    if ((int) ($state['lastUsedAt'] ?? 0) !== mcpfm_now()) {
        $state['lastUsedAt'] = mcpfm_now();
        mcpfm_save_auth_state($state);
        $context['state'] = $state;
    }

    return mcpfm_log_auth_context($context);
}

function mcpfm_get_public_tools(): array
{
    return [
        [
            'name' => 'server_status',
            'title' => 'Server Status',
            'description' => 'Return non-sensitive server metadata for connectivity checks.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'required' => [],
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'appName' => ['type' => 'string'],
                    'appVersion' => ['type' => 'string'],
                    'protocolVersion' => ['type' => 'string'],
                    'supportedProtocolVersions' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'entrypoint' => ['type' => 'string'],
                    'ready' => ['type' => 'boolean'],
                    'authConfigured' => ['type' => 'boolean'],
                    'authState' => ['type' => ['string', 'null']],
                    'authorized' => ['type' => 'boolean'],
                    'authReason' => ['type' => 'string'],
                    'authorizationHeaderPresent' => ['type' => 'boolean'],
                    'inspectorBearerHeaderPresent' => ['type' => 'boolean'],
                    'inspectorAuthorizationHeaderPresent' => ['type' => 'boolean'],
                    'bearerHeaderSource' => ['type' => ['string', 'null']],
                    'tokenHashPrefix' => ['type' => ['string', 'null']],
                    'storedTokenHashPrefix' => ['type' => ['string', 'null']],
                ],
                'required' => [
                    'appName',
                    'appVersion',
                    'protocolVersion',
                    'supportedProtocolVersions',
                    'entrypoint',
                    'ready',
                    'authConfigured',
                    'authState',
                    'authorized',
                    'authReason',
                    'authorizationHeaderPresent',
                    'inspectorBearerHeaderPresent',
                    'inspectorAuthorizationHeaderPresent',
                    'bearerHeaderSource',
                    'tokenHashPrefix',
                    'storedTokenHashPrefix',
                ],
            ],
        ],
        [
            'name' => 'request_auth',
            'title' => 'Request Authentication',
            'description' => 'Start or inspect the single-agent approval flow and receive an approval URL plus bearer token when pending.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'agentName' => ['type' => 'string'],
                ],
                'required' => [],
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                    'approvalUrl' => ['type' => 'string'],
                    'bearerToken' => ['type' => 'string'],
                    'preferredHeaderName' => ['type' => 'string'],
                    'alreadyConfigured' => ['type' => 'boolean'],
                    'approved' => ['type' => 'boolean'],
                ],
                'required' => [
                    'status',
                    'message',
                ],
            ],
        ],
    ];
}

function mcpfm_get_list_dir_tool(): array
{
    return [
        'name' => 'list_dir',
        'title' => 'List Directory',
        'description' => 'List the direct children of a relative path under the MCP entrypoint directory.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => [],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'entries' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'path' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'size' => ['type' => 'integer'],
                            'mtime' => ['type' => 'integer'],
                        ],
                        'required' => ['name', 'path', 'type', 'size', 'mtime'],
                    ],
                ],
            ],
            'required' => ['path', 'entries'],
        ],
    ];
}

function mcpfm_get_tools(array $authContext): array
{
    $tools = mcpfm_get_public_tools();

    if ($authContext['isAuthorized']) {
        $tools[] = mcpfm_get_list_dir_tool();
    }

    return $tools;
}

function mcpfm_server_status_payload(array $authContext): array
{
    return array_merge([
        'appName' => MCPFM_APP_NAME,
        'appVersion' => MCPFM_APP_VERSION,
        'protocolVersion' => MCPFM_PROTOCOL_VERSION,
        'supportedProtocolVersions' => MCPFM_SUPPORTED_PROTOCOL_VERSIONS,
        'entrypoint' => basename(mcpfm_get_entrypoint_path()),
        'ready' => true,
    ], mcpfm_auth_debug_payload($authContext));
}

function mcpfm_create_pending_auth_state(?string $agentName): array
{
    $state = [
        'state' => 'pending',
        'agentName' => $agentName,
        'approvalSecret' => mcpfm_generate_secret(),
        'pendingBearerToken' => mcpfm_generate_secret(),
        'bearerTokenHash' => null,
        'issuedAt' => mcpfm_now(),
        'approvedAt' => null,
        'lastUsedAt' => null,
        'lockedAt' => null,
        'message' => null,
    ];

    mcpfm_save_auth_state($state);

    return $state;
}

function mcpfm_request_auth_payload(array $authContext, array $params): array
{
    $agentName = null;

    if (isset($params['agentName']) && is_string($params['agentName'])) {
        $agentName = trim($params['agentName']) !== '' ? trim($params['agentName']) : null;
    }

    $state = $authContext['state'];

    if ($authContext['isAuthorized'] && $state !== null && ($state['state'] ?? null) === 'approved') {
        return [
            'status' => 'approved',
            'message' => 'This bearer token is already approved.',
            'approved' => true,
        ];
    }

    if ($state === null) {
        $state = mcpfm_create_pending_auth_state($agentName);
    }

    if (($state['state'] ?? null) === 'pending') {
        return [
            'status' => 'pending_approval',
            'message' => 'Give the approval URL to the user and wait for them to approve this agent. In MCP Inspector, prefer X-MCPFM-Bearer-Token: <token>.',
            'approvalUrl' => mcpfm_build_approval_url((string) $state['approvalSecret']),
            'bearerToken' => (string) $state['pendingBearerToken'],
            'preferredHeaderName' => 'X-MCPFM-Bearer-Token',
            'approved' => false,
        ];
    }

    if (($state['state'] ?? null) === 'locked') {
        return [
            'status' => 'locked',
            'message' => (string) ($state['message'] ?? 'Authentication is locked. Delete .mcpfm_auth.json to reset.'),
            'approved' => false,
        ];
    }

    return [
        'status' => 'already_configured',
        'message' => 'An approved agent is already configured. Delete .mcpfm_auth.json to reset.',
        'alreadyConfigured' => true,
        'approved' => false,
    ];
}

function mcpfm_normalize_relative_path(?string $path): array
{
    $path = $path === null ? '.' : trim($path);

    if ($path === '') {
        $path = '.';
    }

    if (strpos($path, "\0") !== false) {
        throw new InvalidArgumentException('Path must not contain NULL bytes.');
    }

    $path = str_replace('\\', '/', $path);

    if (preg_match('/^[A-Za-z]:\//', $path) === 1 || strpos($path, '//') === 0 || strpos($path, '/') === 0) {
        throw new InvalidArgumentException('Absolute paths are not allowed.');
    }

    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            throw new InvalidArgumentException('Parent directory references are not allowed.');
        }

        $segments[] = $segment;
    }

    $relativePath = $segments === [] ? '.' : implode('/', $segments);
    $filesystemPath = mcpfm_app_root();

    if ($segments !== []) {
        $filesystemPath .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    return [
        'relativePath' => $relativePath,
        'filesystemPath' => $filesystemPath,
    ];
}

function mcpfm_list_dir_payload(array $params): array
{
    $path = '.';

    if (isset($params['path'])) {
        if (!is_string($params['path'])) {
            throw new InvalidArgumentException('list_dir path must be a string.');
        }

        $path = $params['path'];
    }

    $normalized = mcpfm_normalize_relative_path($path);
    $directoryPath = $normalized['filesystemPath'];
    $relativePath = $normalized['relativePath'];

    if (!is_dir($directoryPath)) {
        throw new InvalidArgumentException('Directory does not exist.');
    }

    $entries = [];
    $iterator = new DirectoryIterator($directoryPath);

    foreach ($iterator as $item) {
        if ($item->isDot()) {
            continue;
        }

        $name = $item->getFilename();

        if (strpos($name, MCPFM_INTERNAL_FILE_PREFIX) === 0) {
            continue;
        }

        $childPath = $relativePath === '.' ? $name : $relativePath . '/' . $name;
        $entries[] = [
            'name' => $name,
            'path' => $childPath,
            'type' => $item->isDir() ? 'dir' : 'file',
            'size' => $item->isDir() ? 0 : (int) $item->getSize(),
            'mtime' => (int) $item->getMTime(),
        ];
    }

    usort($entries, static function (array $left, array $right): int {
        if ($left['type'] !== $right['type']) {
            return $left['type'] === 'dir' ? -1 : 1;
        }

        return strcmp($left['name'], $right['name']);
    });

    return [
        'path' => $relativePath,
        'entries' => $entries,
    ];
}

function mcpfm_render_invalid_approval_page(): void
{
    $bodyHtml = <<<HTML
<h1>Approval Link Not Available</h1>
<p>この承認リンクは無効か、すでに使用済みです。</p>
<p><code>.mcpfm_auth.json</code> を削除したあとに、MCPクライアントから再度 <code>request_auth</code> を実行してください。</p>
HTML;

    mcpfm_render_page('Approval Link Not Available', $bodyHtml, 404);
}

function mcpfm_render_approval_page(array $state): void
{
    $query = htmlspecialchars((string) $_GET[MCPFM_APPROVAL_QUERY_KEY], ENT_QUOTES, 'UTF-8');
    $agentName = isset($state['agentName']) && is_string($state['agentName']) && $state['agentName'] !== ''
        ? htmlspecialchars((string) $state['agentName'], ENT_QUOTES, 'UTF-8')
        : 'unknown-agent';
    $action = htmlspecialchars(
        mcpfm_get_entrypoint_path() . '?' . MCPFM_APPROVAL_QUERY_KEY . '=' . rawurlencode((string) $_GET[MCPFM_APPROVAL_QUERY_KEY]),
        ENT_QUOTES,
        'UTF-8'
    );

    $bodyHtml = <<<HTML
<h1>MCP Approval</h1>
<p>エージェント <code>{$agentName}</code> にこのファイルマネージャーへのアクセスを許可しますか。</p>
<p>承認すると、1エージェント専用の Bearer token が有効になります。</p>
<form method="post" action="{$action}">
  <input type="hidden" name="approval_secret" value="{$query}">
  <button type="submit" name="approve" value="yes">はい</button>
</form>
HTML;

    mcpfm_render_page('MCP Approval', $bodyHtml);
}

function mcpfm_render_approval_success_page(): void
{
    $bodyHtml = <<<HTML
<h1>Approval Complete</h1>
<p>MCP bearer token を有効化しました。</p>
<p>クライアント側では、発行済みの Bearer token を <code>Authorization: Bearer ...</code> または <code>X-MCPFM-Bearer-Token: ...</code> で送ってください。</p>
HTML;

    mcpfm_render_page('Approval Complete', $bodyHtml);
}

function mcpfm_handle_approval_request(): bool
{
    if (!isset($_GET[MCPFM_APPROVAL_QUERY_KEY]) || !is_string($_GET[MCPFM_APPROVAL_QUERY_KEY])) {
        return false;
    }

    $state = mcpfm_load_auth_state();
    $state = mcpfm_refresh_auth_state($state);
    $secret = (string) $_GET[MCPFM_APPROVAL_QUERY_KEY];

    if ($state === null || ($state['state'] ?? null) !== 'pending') {
        mcpfm_render_invalid_approval_page();
        return true;
    }

    if (!hash_equals((string) $state['approvalSecret'], $secret)) {
        mcpfm_render_invalid_approval_page();
        return true;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        $pendingBearerToken = (string) $state['pendingBearerToken'];
        $state['state'] = 'approved';
        $state['bearerTokenHash'] = mcpfm_hash_secret($pendingBearerToken);
        $state['approvalSecret'] = null;
        $state['pendingBearerToken'] = null;
        $state['approvedAt'] = mcpfm_now();
        $state['lastUsedAt'] = mcpfm_now();
        $state['lockedAt'] = null;
        $state['message'] = null;
        mcpfm_save_auth_state($state);
        mcpfm_render_approval_success_page();
        return true;
    }

    mcpfm_render_approval_page($state);
    return true;
}

function mcpfm_parse_json_message(): ?array
{
    $body = trim(mcpfm_get_request_body());

    if ($body === '') {
        mcpfm_jsonrpc_error(null, -32600, 'Request body is required.', 400);
        return null;
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        mcpfm_jsonrpc_error(null, -32700, 'Invalid JSON.', 400, [
            'detail' => $exception->getMessage(),
        ]);
        return null;
    }

    if (!is_array($decoded) || mcpfm_is_list_array($decoded)) {
        mcpfm_jsonrpc_error(null, -32600, 'Batch requests are not supported.', 400);
        return null;
    }

    return $decoded;
}

function mcpfm_handle_request(array $message): void
{
    $id = $message['id'] ?? null;

    if (($message['jsonrpc'] ?? null) !== MCPFM_JSONRPC_VERSION) {
        mcpfm_jsonrpc_error($id, -32600, 'jsonrpc must be "2.0".', 400);
        return;
    }

    if (!isset($message['method'])) {
        if (array_key_exists('result', $message) || array_key_exists('error', $message)) {
            mcpfm_send_empty(202);
            return;
        }

        mcpfm_jsonrpc_error($id, -32600, 'Request method is required.', 400);
        return;
    }

    if (!is_string($message['method']) || $message['method'] === '') {
        mcpfm_jsonrpc_error($id, -32600, 'Request method must be a non-empty string.', 400);
        return;
    }

    if (!mcpfm_protocol_version_is_supported(mcpfm_get_header('MCP-Protocol-Version'))) {
        mcpfm_jsonrpc_error($id, -32600, 'Unsupported MCP-Protocol-Version header.', 400, [
            'supported' => MCPFM_SUPPORTED_PROTOCOL_VERSIONS,
        ]);
        return;
    }

    $method = $message['method'];
    $params = $message['params'] ?? [];
    $isNotification = !array_key_exists('id', $message);
    $authContext = mcpfm_auth_context();

    if ($isNotification) {
        mcpfm_send_empty(202);
        return;
    }

    switch ($method) {
        case 'ping':
            mcpfm_jsonrpc_result($id, []);
            return;

        case 'initialize':
            if (!is_array($params)) {
                mcpfm_jsonrpc_error($id, -32602, 'initialize params must be an object.');
                return;
            }

            $clientVersion = $params['protocolVersion'] ?? null;

            if (!is_string($clientVersion) || $clientVersion === '') {
                mcpfm_jsonrpc_error($id, -32602, 'initialize requires params.protocolVersion.');
                return;
            }

            $negotiatedVersion = mcpfm_negotiate_protocol_version($clientVersion);

            mcpfm_jsonrpc_result($id, [
                'protocolVersion' => $negotiatedVersion,
                'capabilities' => [
                    'tools' => [
                        'listChanged' => true,
                    ],
                ],
                'serverInfo' => [
                    'name' => MCPFM_APP_NAME,
                    'version' => MCPFM_APP_VERSION,
                ],
                'instructions' => 'Use request_auth first. After approval, call tools with Authorization: Bearer <token> or X-MCPFM-Bearer-Token: <token> to access list_dir.',
            ], 200, [
                'MCP-Protocol-Version' => $negotiatedVersion,
            ]);
            return;

        case 'tools/list':
            mcpfm_debug_log('tools_list', mcpfm_auth_debug_payload($authContext));
            mcpfm_jsonrpc_result($id, [
                'tools' => mcpfm_get_tools($authContext),
            ], 200, [
                'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
            ]);
            return;

        case 'tools/call':
            if (!is_array($params)) {
                mcpfm_jsonrpc_error($id, -32602, 'tools/call params must be an object.');
                return;
            }

            $toolName = $params['name'] ?? null;

            if (!is_string($toolName) || $toolName === '') {
                mcpfm_jsonrpc_error($id, -32602, 'tools/call requires params.name.');
                return;
            }

            $arguments = $params['arguments'] ?? [];

            if (!is_array($arguments)) {
                mcpfm_jsonrpc_error($id, -32602, 'tools/call arguments must be an object.');
                return;
            }

            if ($toolName === 'server_status') {
                $status = mcpfm_server_status_payload($authContext);
                mcpfm_debug_log('server_status', $status);
                mcpfm_jsonrpc_result($id, mcpfm_tool_result(
                    'MCP File Manager is reachable. Entry point: '
                    . $status['entrypoint']
                    . ', app version: '
                    . $status['appVersion']
                    . ', protocol version: '
                    . $status['protocolVersion'],
                    $status
                ), 200, [
                    'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'request_auth') {
                $payload = mcpfm_request_auth_payload($authContext, $arguments);
                mcpfm_jsonrpc_result($id, mcpfm_tool_result($payload['message'], $payload), 200, [
                    'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'list_dir') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-MCPFM-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    mcpfm_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = mcpfm_list_dir_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    mcpfm_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                mcpfm_jsonrpc_result($id, mcpfm_tool_result(
                    'Listed ' . count($payload['entries']) . ' entries under ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
                ]);
                return;
            }

            mcpfm_jsonrpc_error($id, -32601, 'Tool not found.', 200, [
                'tool' => $toolName,
            ], [
                'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
            ]);
            return;

        default:
            mcpfm_jsonrpc_error($id, -32601, 'Method not found.', 200, [
                'method' => $method,
            ], [
                'MCP-Protocol-Version' => MCPFM_PROTOCOL_VERSION,
            ]);
            return;
    }
}

function mcpfm_handle_http_request(): void
{
    if (!mcpfm_origin_is_allowed()) {
        mcpfm_jsonrpc_error(null, -32600, 'Origin is not allowed.', 403);
        return;
    }

    if (mcpfm_handle_approval_request()) {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'DELETE') {
        mcpfm_send_empty(405, ['Allow' => 'GET, POST']);
        return;
    }

    if ($method === 'GET') {
        if (mcpfm_accepts_event_stream()) {
            mcpfm_send_empty(405, ['Allow' => 'POST']);
            return;
        }

        mcpfm_render_default_page();
        return;
    }

    if ($method !== 'POST') {
        mcpfm_send_empty(405, ['Allow' => 'GET, POST']);
        return;
    }

    $message = mcpfm_parse_json_message();

    if ($message === null) {
        return;
    }

    mcpfm_handle_request($message);
}

mcpfm_handle_http_request();
