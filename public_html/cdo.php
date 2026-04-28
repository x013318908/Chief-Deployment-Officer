<?php

declare(strict_types=1);

const CDO_APP_NAME = 'Chief-Deployment-Officer';
const CDO_APP_VERSION = '0.2.0-dev';
const CDO_JSONRPC_VERSION = '2.0';
const CDO_PROTOCOL_VERSION = '2025-11-25';
const CDO_SUPPORTED_PROTOCOL_VERSIONS = [
    '2025-11-25',
    '2025-06-18',
    '2025-03-26',
];
const CDO_AUTH_FILE_NAME = '.cdo_auth.json';
const CDO_DEBUG_LOG_FILE_NAME = '.cdo_debug.log';
const CDO_APPROVAL_QUERY_KEY = 'cdo_approve';
const CDO_AUTH_IDLE_SECONDS = 2592000;
const CDO_INTERNAL_FILE_PREFIX = '.cdo_';
const CDO_MAX_READ_FILE_BYTES = 1048576;

function cdo_send_json(int $statusCode, array $payload, array $headers = []): void
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

function cdo_send_empty(int $statusCode, array $headers = []): void
{
    http_response_code($statusCode);

    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
}

function cdo_get_header(string $name): string
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

function cdo_get_request_body(): string
{
    static $body;

    if ($body === null) {
        $body = (string) file_get_contents('php://input');
    }

    return $body;
}

function cdo_accepts_event_stream(): bool
{
    return stripos(cdo_get_header('Accept'), 'text/event-stream') !== false;
}

function cdo_request_host(): string
{
    return (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
}

function cdo_normalize_host(string $value): string
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

function cdo_is_local_host(string $host): bool
{
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function cdo_origin_is_allowed(): bool
{
    $origin = cdo_get_header('Origin');

    if ($origin === '') {
        return true;
    }

    $originHost = cdo_normalize_host($origin);
    $requestHost = cdo_normalize_host(cdo_request_host());

    if ($originHost === '' || $requestHost === '') {
        return false;
    }

    if ($originHost === $requestHost) {
        return true;
    }

    return cdo_is_local_host($originHost) && cdo_is_local_host($requestHost);
}

function cdo_protocol_version_is_supported(?string $version): bool
{
    if ($version === null || $version === '') {
        return true;
    }

    return in_array($version, CDO_SUPPORTED_PROTOCOL_VERSIONS, true);
}

function cdo_is_list_array(array $value): bool
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

function cdo_negotiate_protocol_version(string $clientVersion): string
{
    if (in_array($clientVersion, CDO_SUPPORTED_PROTOCOL_VERSIONS, true)) {
        return $clientVersion;
    }

    return CDO_PROTOCOL_VERSION;
}

function cdo_jsonrpc_result($id, array $result, int $statusCode = 200, array $headers = []): void
{
    cdo_send_json($statusCode, [
        'jsonrpc' => CDO_JSONRPC_VERSION,
        'id' => $id,
        'result' => $result,
    ], $headers);
}

function cdo_jsonrpc_error($id, int $code, string $message, int $statusCode = 200, $data = null, array $headers = []): void
{
    $payload = [
        'jsonrpc' => CDO_JSONRPC_VERSION,
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

    cdo_send_json($statusCode, $payload, $headers);
}

function cdo_tool_result(string $text, array $structuredContent): array
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

function cdo_now(): int
{
    return time();
}

function cdo_app_root(): string
{
    return __DIR__;
}

function cdo_auth_state_path(): string
{
    $override = getenv('CDO_AUTH_STATE_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return cdo_app_root() . DIRECTORY_SEPARATOR . CDO_AUTH_FILE_NAME;
}

function cdo_debug_log_path(): string
{
    $override = getenv('CDO_DEBUG_LOG_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return cdo_app_root() . DIRECTORY_SEPARATOR . CDO_DEBUG_LOG_FILE_NAME;
}

function cdo_hash_secret(string $value): string
{
    return hash('sha256', $value);
}

function cdo_generate_secret(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function cdo_get_request_scheme(): string
{
    $forwarded = strtolower(trim(cdo_get_header('X-Forwarded-Proto')));

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

function cdo_get_entrypoint_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);

    if ($path === '') {
        $path = (string) parse_url((string) ($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH);
    }

    if ($path === '' || $path === '/') {
        return '/cdo.php';
    }

    $path = str_replace('\\', '/', $path);

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }

        $segments[] = $segment;
    }

    if ($segments === []) {
        return '/cdo.php';
    }

    return '/' . implode('/', $segments);
}

function cdo_request_path(): string
{
    return (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
}

function cdo_debug_log(string $event, array $data = []): void
{
    $payload = [
        'time' => gmdate('c'),
        'event' => $event,
        'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        'path' => cdo_request_path(),
        'mcpSessionId' => cdo_get_header('Mcp-Session-Id'),
        'data' => $data,
    ];

    error_log(
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        3,
        cdo_debug_log_path()
    );
}

function cdo_build_approval_url(string $secret): string
{
    return sprintf(
        '%s://%s%s?%s=%s',
        cdo_get_request_scheme(),
        cdo_request_host(),
        cdo_get_entrypoint_path(),
        CDO_APPROVAL_QUERY_KEY,
        rawurlencode($secret)
    );
}

function cdo_build_public_url(string $path): string
{
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    return sprintf(
        '%s://%s%s',
        cdo_get_request_scheme(),
        cdo_request_host(),
        $path
    );
}

function cdo_public_text($value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    return $value === '' ? null : $value;
}

function cdo_public_time($value): ?string
{
    $timestamp = is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);

    if ($timestamp <= 0) {
        return null;
    }

    return gmdate('c', $timestamp);
}

function cdo_html_text(?string $value, string $fallback = 'not set'): string
{
    return htmlspecialchars($value ?? $fallback, ENT_QUOTES, 'UTF-8');
}

function cdo_render_page(string $title, string $bodyHtml, int $statusCode = 200): void
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
    pre {
      overflow-x: auto;
      border-radius: 12px;
      padding: 14px;
      background: rgba(30, 29, 26, 0.06);
      white-space: pre-wrap;
    }
    h2 {
      margin: 28px 0 12px;
    }
    h3 {
      margin: 20px 0 10px;
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
      pre {
        background: rgba(244, 239, 231, 0.08);
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

function cdo_render_default_page(): void
{
    $authContext = cdo_auth_context();
    $auth = cdo_auth_public_payload($authContext);
    $entrypointPath = cdo_get_entrypoint_path();
    $endpointUrl = cdo_build_public_url($entrypointPath);
    $entrypoint = htmlspecialchars($entrypointPath, ENT_QUOTES, 'UTF-8');
    $endpoint = htmlspecialchars($endpointUrl, ENT_QUOTES, 'UTF-8');
    $appVersion = htmlspecialchars(CDO_APP_VERSION, ENT_QUOTES, 'UTF-8');
    $protocolVersion = htmlspecialchars(CDO_PROTOCOL_VERSION, ENT_QUOTES, 'UTF-8');
    $authState = cdo_html_text((string) $auth['authState']);
    $authorized = $auth['authorized'] ? 'true' : 'false';
    $authReason = cdo_html_text((string) $auth['authReason']);
    $agentName = cdo_html_text($auth['agentName']);
    $contextHint = cdo_html_text($auth['contextHint']);
    $issuedAt = cdo_html_text($auth['issuedAt']);
    $approvedAt = cdo_html_text($auth['approvedAt']);
    $lastUsedAt = cdo_html_text($auth['lastUsedAt']);
    $lockedAt = cdo_html_text($auth['lockedAt']);
    $message = cdo_html_text($auth['message']);
    $aiGuide = htmlspecialchars(<<<'TEXT'
AI agent instructions for this endpoint:
1. If the user gave you this URL, use this exact URL as the Streamable HTTP MCP endpoint.
2. Do not replace this URL with /cdo.php, a repository path, or another guessed location.
3. Do not inspect a local repository first unless the user explicitly asks; start by calling tools/list against this endpoint.
4. Call tools/list, then call tools/call server_status to read authState, authorized, agentName, contextHint, approvedAt, and lastUsedAt.
5. If not approved, call tools/call request_auth with arguments like {"agentName":"Codex","contextHint":"Codex desktop / project or deploy thread / YYYY-MM-DD"}.
6. Give the approvalUrl to the user, ask them to open it and approve, then wait until they tell you approval is complete.
7. Keep the returned bearer token private in the agent session. Do not ask the user to paste it back.
8. After approval, send X-CDO-Bearer-Token: <bearerToken> on future MCP requests.
9. Call server_status again to confirm authorized=true, then use protected file tools.
10. Before write/delete/rename, explain the target path to the user and get explicit confirmation.
TEXT, ENT_QUOTES, 'UTF-8');

    $bodyHtml = <<<HTML
<h1>Chief-Deployment-Officer</h1>
<p>単一PHPファイルのMCPエンドポイントです。このブラウザ画面は、先にユーザー向け確認、次にAIエージェント向け接続手順を表示します。</p>

<section aria-labelledby="user-guidance-heading">
<h2 id="user-guidance-heading">User guidance / ユーザー向け</h2>
<p>AIエージェントにこのページのURLを渡すと、エージェントはこのURLをMCP endpointとして使い、必要に応じて承認要求を作れます。</p>
<ul>
  <li>AIに渡すURL / MCP endpoint: <code>{$endpoint}</code></li>
  <li>認証設定: <code>{$authState}</code></li>
  <li>このリクエストは認証済み: <code>{$authorized}</code></li>
  <li>認証理由: <code>{$authReason}</code></li>
  <li>承認AI: <code>{$agentName}</code></li>
  <li>スレッド手がかり: <code>{$contextHint}</code></li>
  <li>発行日時 issuedAt (UTC): <code>{$issuedAt}</code></li>
  <li>承認日時 approvedAt (UTC): <code>{$approvedAt}</code></li>
  <li>最終利用日時 lastUsedAt (UTC): <code>{$lastUsedAt}</code></li>
  <li>ロック日時 lockedAt (UTC): <code>{$lockedAt}</code></li>
  <li>メッセージ: <code>{$message}</code></li>
</ul>
<p>承認済みの場合は、上の「承認AI」「スレッド手がかり」「承認日時」を使って、どのAIエージェント・対話スレッドに許可したかを探せます。</p>
<p>不要になった認証は、認証状態ファイルを削除してから <code>request_auth</code> をやり直してください。</p>

<h3>危険操作の注意</h3>
<ul>
  <li><code>write_file</code>, <code>delete_file</code>, <code>delete_dir</code>, <code>rename_path</code> は承認済みエージェントだけが使えます。</li>
  <li>書込・削除・リネーム前に、対象パスを <code>list_dir</code> / <code>read_file</code> で確認し、必要に応じてバックアップしてください。</li>
  <li><code>confirm: true</code> は、ユーザーが対象と操作内容を確認した後だけ付けてください。</li>
</ul>
</section>

<section aria-labelledby="ai-guidance-heading">
<h2 id="ai-guidance-heading">AI agent guidance / AIエージェント向け</h2>
<p>ユーザーがこのURLをAIエージェントに渡した場合は、このURL自体をMCP endpointとしてそのまま使ってください。ローカルリポジトリや別パスを推測してから始める必要はありません。</p>
<pre><code>{$aiGuide}</code></pre>

<h3>Connection details</h3>
<ul>
  <li>エントリポイント: <code>{$entrypoint}</code></li>
  <li>MCP endpoint: <code>{$endpoint}</code></li>
  <li>アプリバージョン: <code>{$appVersion}</code></li>
  <li>MCPプロトコル: <code>{$protocolVersion}</code></li>
  <li>公開ツール: <code>server_status</code>, <code>request_auth</code></li>
  <li>保護ツール: <code>list_dir</code>, <code>read_file</code>, <code>write_file</code>, <code>create_dir</code>, <code>delete_file</code>, <code>delete_dir</code>, <code>rename_path</code></li>
</ul>
</section>
HTML;

    cdo_render_page('Chief-Deployment-Officer', $bodyHtml);
}

function cdo_load_auth_state(): ?array
{
    $path = cdo_auth_state_path();

    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);

    if (!is_string($raw) || $raw === '') {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is unreadable. Delete .cdo_auth.json to reset.',
            'lockedAt' => cdo_now(),
        ];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is invalid. Delete .cdo_auth.json to reset.',
            'lockedAt' => cdo_now(),
        ];
    }

    if (!is_array($decoded)) {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is invalid. Delete .cdo_auth.json to reset.',
            'lockedAt' => cdo_now(),
        ];
    }

    $state = array_merge([
        'version' => 1,
        'state' => 'locked',
        'agentName' => null,
        'contextHint' => null,
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
        $state['message'] = 'Auth state file is invalid. Delete .cdo_auth.json to reset.';
    }

    if ($state['state'] === 'pending') {
        if (!is_string($state['approvalSecret']) || $state['approvalSecret'] === ''
            || !is_string($state['pendingBearerToken']) || $state['pendingBearerToken'] === '') {
            $state['state'] = 'locked';
            $state['message'] = 'Pending auth state is invalid. Delete .cdo_auth.json to reset.';
        }
    }

    if ($state['state'] === 'approved') {
        if (!is_string($state['bearerTokenHash']) || $state['bearerTokenHash'] === '') {
            $state['state'] = 'locked';
            $state['message'] = 'Approved auth state is invalid. Delete .cdo_auth.json to reset.';
        }
    }

    return $state;
}

function cdo_save_auth_state(array $state): void
{
    $state['version'] = 1;

    file_put_contents(
        cdo_auth_state_path(),
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function cdo_refresh_auth_state(?array $state): ?array
{
    if ($state === null) {
        return null;
    }

    if (($state['state'] ?? null) !== 'approved') {
        return $state;
    }

    $lastUsedAt = (int) ($state['lastUsedAt'] ?? $state['approvedAt'] ?? 0);

    if ($lastUsedAt > 0 && (cdo_now() - $lastUsedAt) <= CDO_AUTH_IDLE_SECONDS) {
        return $state;
    }

    $state['state'] = 'locked';
    $state['lockedAt'] = cdo_now();
    $state['message'] = 'The MCP bearer token has expired after 30 days of inactivity. Delete .cdo_auth.json to reset.';
    cdo_save_auth_state($state);

    return $state;
}

function cdo_extract_bearer_token(string $value, bool $allowRaw = false): ?string
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

function cdo_get_bearer_token_state(): array
{
    $authorization = trim(cdo_get_header('Authorization'));
    $inspectorBearerToken = trim(cdo_get_header('X-CDO-Bearer-Token'));
    $inspectorAuthorization = trim(cdo_get_header('X-CDO-Authorization'));

    $token = cdo_extract_bearer_token($authorization);
    $source = $token !== null ? 'authorization' : null;

    if ($token === null) {
        $token = cdo_extract_bearer_token($inspectorBearerToken, true);
        $source = $token !== null ? 'x-cdo-bearer-token' : null;
    }

    if ($token === null) {
        $token = cdo_extract_bearer_token($inspectorAuthorization, true);
        $source = $token !== null ? 'x-cdo-authorization' : null;
    }

    return [
        'token' => $token,
        'source' => $source,
        'authorizationHeaderPresent' => $authorization !== '',
        'inspectorBearerHeaderPresent' => $inspectorBearerToken !== '',
        'inspectorAuthorizationHeaderPresent' => $inspectorAuthorization !== '',
    ];
}

function cdo_auth_debug_payload(array $authContext): array
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
            ? substr(cdo_hash_secret($token), 0, 12)
            : null,
        'storedTokenHashPrefix' => isset($state['bearerTokenHash']) && is_string($state['bearerTokenHash']) && $state['bearerTokenHash'] !== ''
            ? substr((string) $state['bearerTokenHash'], 0, 12)
            : null,
    ];
}

function cdo_auth_state_public_payload(?array $state): array
{
    return [
        'agentName' => cdo_public_text($state['agentName'] ?? null),
        'contextHint' => cdo_public_text($state['contextHint'] ?? null),
        'issuedAt' => cdo_public_time($state['issuedAt'] ?? null),
        'approvedAt' => cdo_public_time($state['approvedAt'] ?? null),
        'lastUsedAt' => cdo_public_time($state['lastUsedAt'] ?? null),
        'lockedAt' => cdo_public_time($state['lockedAt'] ?? null),
        'message' => cdo_public_text($state['message'] ?? null),
    ];
}

function cdo_auth_public_payload(array $authContext): array
{
    $state = isset($authContext['state']) && is_array($authContext['state'])
        ? $authContext['state']
        : null;

    return array_merge([
        'authConfigured' => $state !== null,
        'authState' => $state['state'] ?? 'not_configured',
        'authorized' => (bool) ($authContext['isAuthorized'] ?? false),
        'authReason' => (string) ($authContext['reason'] ?? 'unknown'),
        'authorizationHeaderPresent' => (bool) ($authContext['authorizationHeaderPresent'] ?? false),
        'inspectorBearerHeaderPresent' => (bool) ($authContext['inspectorBearerHeaderPresent'] ?? false),
        'inspectorAuthorizationHeaderPresent' => (bool) ($authContext['inspectorAuthorizationHeaderPresent'] ?? false),
        'bearerHeaderSource' => $authContext['tokenSource'] ?? null,
    ], cdo_auth_state_public_payload($state));
}

function cdo_log_auth_context(array $authContext): array
{
    cdo_debug_log('auth_context', cdo_auth_debug_payload($authContext));

    return $authContext;
}

function cdo_auth_context(): array
{
    $state = cdo_refresh_auth_state(cdo_load_auth_state());
    $tokenState = cdo_get_bearer_token_state();
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
        return cdo_log_auth_context($context);
    }

    $context['reason'] = (string) ($state['state'] ?? 'unknown');

    if (($state['state'] ?? null) !== 'approved') {
        return cdo_log_auth_context($context);
    }

    if ($token === null) {
        $context['reason'] = 'missing_token';
        return cdo_log_auth_context($context);
    }

    if (!hash_equals((string) $state['bearerTokenHash'], cdo_hash_secret($token))) {
        $context['reason'] = 'invalid_token';
        return cdo_log_auth_context($context);
    }

    $context['isAuthorized'] = true;
    $context['reason'] = 'authorized';

    if ((int) ($state['lastUsedAt'] ?? 0) !== cdo_now()) {
        $state['lastUsedAt'] = cdo_now();
        cdo_save_auth_state($state);
        $context['state'] = $state;
    }

    return cdo_log_auth_context($context);
}

function cdo_get_public_tools(): array
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
                    'agentName' => ['type' => ['string', 'null']],
                    'contextHint' => ['type' => ['string', 'null']],
                    'issuedAt' => ['type' => ['string', 'null']],
                    'approvedAt' => ['type' => ['string', 'null']],
                    'lastUsedAt' => ['type' => ['string', 'null']],
                    'lockedAt' => ['type' => ['string', 'null']],
                    'message' => ['type' => ['string', 'null']],
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
                    'agentName',
                    'contextHint',
                    'issuedAt',
                    'approvedAt',
                    'lastUsedAt',
                    'lockedAt',
                    'message',
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
                    'contextHint' => ['type' => 'string'],
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
                    'agentName' => ['type' => ['string', 'null']],
                    'contextHint' => ['type' => ['string', 'null']],
                    'issuedAt' => ['type' => ['string', 'null']],
                    'approvedAt' => ['type' => ['string', 'null']],
                    'lastUsedAt' => ['type' => ['string', 'null']],
                    'lockedAt' => ['type' => ['string', 'null']],
                ],
                'required' => [
                    'status',
                    'message',
                ],
            ],
        ],
    ];
}

function cdo_get_list_dir_tool(): array
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

function cdo_get_read_file_tool(): array
{
    return [
        'name' => 'read_file',
        'title' => 'Read File',
        'description' => 'Read a file under the MCP entrypoint directory with path and size safeguards.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'maxBytes' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => CDO_MAX_READ_FILE_BYTES,
                ],
            ],
            'required' => ['path'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'size' => ['type' => 'integer'],
                'mtime' => ['type' => 'integer'],
                'encoding' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'truncated' => ['type' => 'boolean'],
                'bytesRead' => ['type' => 'integer'],
            ],
            'required' => ['path', 'name', 'size', 'mtime', 'encoding', 'content', 'truncated', 'bytesRead'],
        ],
    ];
}

function cdo_get_write_file_tool(): array
{
    return [
        'name' => 'write_file',
        'title' => 'Write File',
        'description' => 'Create or overwrite a file under the MCP entrypoint directory with path safeguards.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'encoding' => [
                    'type' => 'string',
                    'enum' => ['utf-8', 'base64'],
                ],
                'overwrite' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'content', 'encoding'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'encoding' => ['type' => 'string'],
                'bytesWritten' => ['type' => 'integer'],
                'overwritten' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'name', 'encoding', 'bytesWritten', 'overwritten'],
        ],
    ];
}

function cdo_get_create_dir_tool(): array
{
    return [
        'name' => 'create_dir',
        'title' => 'Create Directory',
        'description' => 'Create a directory under the MCP entrypoint directory.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'recursive' => ['type' => 'boolean'],
            ],
            'required' => ['path'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'created' => ['type' => 'boolean'],
                'alreadyExisted' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'name', 'created', 'alreadyExisted'],
        ],
    ];
}

function cdo_get_delete_file_tool(): array
{
    return [
        'name' => 'delete_file',
        'title' => 'Delete File',
        'description' => 'Delete a file under the MCP entrypoint directory. Requires confirm:true.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'confirm' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'confirm'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'deleted' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'name', 'deleted'],
        ],
    ];
}

function cdo_get_delete_dir_tool(): array
{
    return [
        'name' => 'delete_dir',
        'title' => 'Delete Directory',
        'description' => 'Delete an empty directory under the MCP entrypoint directory. Requires confirm:true.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'confirm' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'confirm'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'deleted' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'name', 'deleted'],
        ],
    ];
}

function cdo_get_rename_path_tool(): array
{
    return [
        'name' => 'rename_path',
        'title' => 'Rename Path',
        'description' => 'Rename or move a file or directory under the MCP entrypoint directory. Requires confirm:true and never replaces existing paths.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'confirm' => ['type' => 'boolean'],
            ],
            'required' => ['from', 'to', 'confirm'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'renamed' => ['type' => 'boolean'],
            ],
            'required' => ['from', 'to', 'name', 'type', 'renamed'],
        ],
    ];
}

function cdo_get_tools(array $authContext): array
{
    $tools = cdo_get_public_tools();

    if ($authContext['isAuthorized']) {
        $tools[] = cdo_get_list_dir_tool();
        $tools[] = cdo_get_read_file_tool();
        $tools[] = cdo_get_write_file_tool();
        $tools[] = cdo_get_create_dir_tool();
        $tools[] = cdo_get_delete_file_tool();
        $tools[] = cdo_get_delete_dir_tool();
        $tools[] = cdo_get_rename_path_tool();
    }

    return $tools;
}

function cdo_server_status_payload(array $authContext): array
{
    return array_merge([
        'appName' => CDO_APP_NAME,
        'appVersion' => CDO_APP_VERSION,
        'protocolVersion' => CDO_PROTOCOL_VERSION,
        'supportedProtocolVersions' => CDO_SUPPORTED_PROTOCOL_VERSIONS,
        'entrypoint' => cdo_get_entrypoint_path(),
        'ready' => true,
    ], cdo_auth_public_payload($authContext));
}

function cdo_create_pending_auth_state(?string $agentName, ?string $contextHint): array
{
    $state = [
        'state' => 'pending',
        'agentName' => $agentName,
        'contextHint' => $contextHint,
        'approvalSecret' => cdo_generate_secret(),
        'pendingBearerToken' => cdo_generate_secret(),
        'bearerTokenHash' => null,
        'issuedAt' => cdo_now(),
        'approvedAt' => null,
        'lastUsedAt' => null,
        'lockedAt' => null,
        'message' => null,
    ];

    cdo_save_auth_state($state);

    return $state;
}

function cdo_request_auth_payload(array $authContext, array $params): array
{
    $agentName = null;
    $contextHint = null;

    if (isset($params['agentName']) && is_string($params['agentName'])) {
        $agentName = trim($params['agentName']) !== '' ? trim($params['agentName']) : null;
    }

    if (isset($params['contextHint']) && is_string($params['contextHint'])) {
        $contextHint = trim($params['contextHint']) !== '' ? trim($params['contextHint']) : null;
    }

    $state = $authContext['state'];

    if ($authContext['isAuthorized'] && $state !== null && ($state['state'] ?? null) === 'approved') {
        return array_merge(cdo_auth_state_public_payload($state), [
            'status' => 'approved',
            'message' => 'This bearer token is already approved.',
            'approved' => true,
        ]);
    }

    if ($state === null) {
        $state = cdo_create_pending_auth_state($agentName, $contextHint);
    }

    if (($state['state'] ?? null) === 'pending') {
        return array_merge(cdo_auth_state_public_payload($state), [
            'status' => 'pending_approval',
            'message' => 'Give the approval URL to the user and wait for them to approve this agent. In MCP Inspector, prefer X-CDO-Bearer-Token: <token>.',
            'approvalUrl' => cdo_build_approval_url((string) $state['approvalSecret']),
            'bearerToken' => (string) $state['pendingBearerToken'],
            'preferredHeaderName' => 'X-CDO-Bearer-Token',
            'approved' => false,
        ]);
    }

    if (($state['state'] ?? null) === 'locked') {
        return array_merge(cdo_auth_state_public_payload($state), [
            'status' => 'locked',
            'message' => (string) ($state['message'] ?? 'Authentication is locked. Delete .cdo_auth.json to reset.'),
            'approved' => false,
        ]);
    }

    return array_merge(cdo_auth_state_public_payload($state), [
        'status' => 'already_configured',
        'message' => 'An approved agent is already configured. Delete .cdo_auth.json to reset.',
        'alreadyConfigured' => true,
        'approved' => false,
    ]);
}

function cdo_normalize_relative_path(?string $path): array
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
    $filesystemPath = cdo_app_root();

    if ($segments !== []) {
        $filesystemPath .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    return [
        'relativePath' => $relativePath,
        'filesystemPath' => $filesystemPath,
    ];
}

function cdo_resolve_existing_path(array $normalized): string
{
    $rootPath = realpath(cdo_app_root());
    $targetPath = realpath((string) $normalized['filesystemPath']);

    if (!is_string($rootPath) || !is_string($targetPath)) {
        throw new InvalidArgumentException('Path does not exist.');
    }

    $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
    $targetPath = str_replace('\\', '/', $targetPath);
    $rootPathForCompare = strtolower($rootPath);
    $targetPathForCompare = strtolower($targetPath);

    if ($targetPathForCompare !== $rootPathForCompare
        && strpos($targetPathForCompare, $rootPathForCompare . '/') !== 0) {
        throw new InvalidArgumentException('Path resolves outside the entrypoint directory.');
    }

    return $targetPath;
}

function cdo_resolve_existing_ancestor_path(string $filesystemPath): string
{
    $candidate = $filesystemPath;

    while (!file_exists($candidate)) {
        $parent = dirname($candidate);

        if ($parent === $candidate) {
            throw new InvalidArgumentException('Path parent does not exist.');
        }

        $candidate = $parent;
    }

    $ancestorPath = realpath($candidate);

    if (!is_string($ancestorPath)) {
        throw new InvalidArgumentException('Path parent does not exist.');
    }

    $rootPath = realpath(cdo_app_root());

    if (!is_string($rootPath)) {
        throw new InvalidArgumentException('Entry point directory is not available.');
    }

    $rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
    $ancestorPath = str_replace('\\', '/', $ancestorPath);
    $rootPathForCompare = strtolower($rootPath);
    $ancestorPathForCompare = strtolower($ancestorPath);

    if ($ancestorPathForCompare !== $rootPathForCompare
        && strpos($ancestorPathForCompare, $rootPathForCompare . '/') !== 0) {
        throw new InvalidArgumentException('Path resolves outside the entrypoint directory.');
    }

    if (!is_dir($ancestorPath)) {
        throw new InvalidArgumentException('Path parent is not a directory.');
    }

    return $ancestorPath;
}

function cdo_path_contains_internal_file(string $relativePath): bool
{
    foreach (explode('/', $relativePath) as $segment) {
        if (strpos($segment, CDO_INTERNAL_FILE_PREFIX) === 0) {
            return true;
        }
    }

    return false;
}

function cdo_current_entrypoint_path(): string
{
    $scriptFilename = (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    $entrypointPath = realpath($scriptFilename);

    if (!is_string($entrypointPath)) {
        $entrypointPath = $scriptFilename;
    }

    return strtolower(str_replace('\\', '/', $entrypointPath));
}

function cdo_target_is_current_entrypoint(string $filesystemPath): bool
{
    $targetPath = realpath($filesystemPath);

    if (!is_string($targetPath)) {
        $parentPath = realpath(dirname($filesystemPath));

        if (!is_string($parentPath)) {
            return false;
        }

        $targetPath = rtrim($parentPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($filesystemPath);
    }

    return strtolower(str_replace('\\', '/', $targetPath)) === cdo_current_entrypoint_path();
}

function cdo_validate_writable_relative_path(array $normalized, string $operation): void
{
    $relativePath = (string) $normalized['relativePath'];

    if ($relativePath === '.') {
        throw new InvalidArgumentException($operation . ' path must not be the entrypoint directory.');
    }

    if (cdo_path_contains_internal_file($relativePath)) {
        throw new InvalidArgumentException('Internal control files cannot be modified.');
    }

    if (cdo_target_is_current_entrypoint((string) $normalized['filesystemPath'])) {
        throw new InvalidArgumentException('The current entrypoint file cannot be modified.');
    }
}

function cdo_require_confirm(array $params, string $operation): void
{
    if (!isset($params['confirm']) || $params['confirm'] !== true) {
        throw new InvalidArgumentException($operation . ' requires confirm:true.');
    }
}

function cdo_directory_is_empty(string $directoryPath): bool
{
    $iterator = new DirectoryIterator($directoryPath);

    foreach ($iterator as $item) {
        if (!$item->isDot()) {
            return false;
        }
    }

    return true;
}

function cdo_decode_write_content(array $params): array
{
    if (!isset($params['content']) || !is_string($params['content'])) {
        throw new InvalidArgumentException('write_file content must be a string.');
    }

    if (!isset($params['encoding']) || !is_string($params['encoding'])) {
        throw new InvalidArgumentException('write_file encoding must be "utf-8" or "base64".');
    }

    $encoding = $params['encoding'];

    if ($encoding === 'utf-8') {
        if (preg_match('//u', $params['content']) !== 1) {
            throw new InvalidArgumentException('write_file content must be valid UTF-8.');
        }

        return [
            'encoding' => $encoding,
            'bytes' => $params['content'],
        ];
    }

    if ($encoding === 'base64') {
        $decoded = base64_decode($params['content'], true);

        if (!is_string($decoded)) {
            throw new InvalidArgumentException('write_file content must be valid base64.');
        }

        return [
            'encoding' => $encoding,
            'bytes' => $decoded,
        ];
    }

    throw new InvalidArgumentException('write_file encoding must be "utf-8" or "base64".');
}

function cdo_list_dir_payload(array $params): array
{
    $path = '.';

    if (isset($params['path'])) {
        if (!is_string($params['path'])) {
            throw new InvalidArgumentException('list_dir path must be a string.');
        }

        $path = $params['path'];
    }

    $normalized = cdo_normalize_relative_path($path);
    $directoryPath = cdo_resolve_existing_path($normalized);
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

        if (strpos($name, CDO_INTERNAL_FILE_PREFIX) === 0) {
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

function cdo_read_file_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('read_file path must be a string.');
    }

    $maxBytes = CDO_MAX_READ_FILE_BYTES;

    if (isset($params['maxBytes'])) {
        if (!is_int($params['maxBytes'])) {
            throw new InvalidArgumentException('read_file maxBytes must be an integer.');
        }

        if ($params['maxBytes'] < 1 || $params['maxBytes'] > CDO_MAX_READ_FILE_BYTES) {
            throw new InvalidArgumentException('read_file maxBytes must be between 1 and ' . CDO_MAX_READ_FILE_BYTES . '.');
        }

        $maxBytes = $params['maxBytes'];
    }

    $normalized = cdo_normalize_relative_path($params['path']);
    $relativePath = $normalized['relativePath'];

    if ($relativePath === '.') {
        throw new InvalidArgumentException('read_file path must point to a file.');
    }

    if (cdo_path_contains_internal_file($relativePath)) {
        throw new InvalidArgumentException('Internal control files cannot be read.');
    }

    $filePath = cdo_resolve_existing_path($normalized);

    if (!is_file($filePath)) {
        throw new InvalidArgumentException('File does not exist.');
    }

    if (!is_readable($filePath)) {
        throw new InvalidArgumentException('File is not readable.');
    }

    $size = (int) filesize($filePath);
    $bytesToRead = min($size, $maxBytes);
    $handle = fopen($filePath, 'rb');

    if ($handle === false) {
        throw new InvalidArgumentException('File could not be opened.');
    }

    try {
        $content = $bytesToRead > 0 ? fread($handle, $bytesToRead) : '';
    } finally {
        fclose($handle);
    }

    if (!is_string($content)) {
        throw new InvalidArgumentException('File could not be read.');
    }

    $encoding = preg_match('//u', $content) === 1 ? 'utf-8' : 'base64';

    return [
        'path' => $relativePath,
        'name' => basename($relativePath),
        'size' => $size,
        'mtime' => (int) filemtime($filePath),
        'encoding' => $encoding,
        'content' => $encoding === 'utf-8' ? $content : base64_encode($content),
        'truncated' => $size > $bytesToRead,
        'bytesRead' => strlen($content),
    ];
}

function cdo_write_file_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('write_file path must be a string.');
    }

    $overwrite = false;

    if (isset($params['overwrite'])) {
        if (!is_bool($params['overwrite'])) {
            throw new InvalidArgumentException('write_file overwrite must be a boolean.');
        }

        $overwrite = $params['overwrite'];
    }

    $decoded = cdo_decode_write_content($params);
    $normalized = cdo_normalize_relative_path($params['path']);
    cdo_validate_writable_relative_path($normalized, 'write_file');

    $filePath = (string) $normalized['filesystemPath'];
    $relativePath = (string) $normalized['relativePath'];
    $parentPath = dirname($filePath);
    $resolvedParentPath = cdo_resolve_existing_ancestor_path($parentPath);

    if (str_replace('\\', '/', realpath($parentPath) ?: '') !== str_replace('\\', '/', $resolvedParentPath)) {
        throw new InvalidArgumentException('Parent directory does not exist.');
    }

    if (is_dir($filePath)) {
        throw new InvalidArgumentException('write_file path points to a directory.');
    }

    $alreadyExists = is_file($filePath);

    if ($alreadyExists && !$overwrite) {
        throw new InvalidArgumentException('File already exists. Set overwrite to true to replace it.');
    }

    if (file_exists($filePath) && !$alreadyExists) {
        throw new InvalidArgumentException('write_file path exists but is not a file.');
    }

    $temporaryPath = tempnam($resolvedParentPath, '.cdo_write_');

    if (!is_string($temporaryPath)) {
        throw new InvalidArgumentException('Temporary file could not be created.');
    }

    $handle = fopen($temporaryPath, 'wb');

    if ($handle === false) {
        @unlink($temporaryPath);
        throw new InvalidArgumentException('Temporary file could not be opened.');
    }

    try {
        $bytes = (string) $decoded['bytes'];
        $bytesLength = strlen($bytes);
        $offset = 0;

        while ($offset < $bytesLength) {
            $written = fwrite($handle, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new InvalidArgumentException('Temporary file could not be written.');
            }

            $offset += $written;
        }
    } finally {
        fclose($handle);
    }

    $renamed = @rename($temporaryPath, $filePath);

    if (!$renamed && $alreadyExists && $overwrite) {
        @unlink($filePath);
        $renamed = @rename($temporaryPath, $filePath);
    }

    if (!$renamed) {
        @unlink($temporaryPath);
        throw new InvalidArgumentException('File could not be written.');
    }

    return [
        'path' => $relativePath,
        'name' => basename($relativePath),
        'encoding' => (string) $decoded['encoding'],
        'bytesWritten' => strlen((string) $decoded['bytes']),
        'overwritten' => $alreadyExists,
    ];
}

function cdo_create_dir_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('create_dir path must be a string.');
    }

    $recursive = false;

    if (isset($params['recursive'])) {
        if (!is_bool($params['recursive'])) {
            throw new InvalidArgumentException('create_dir recursive must be a boolean.');
        }

        $recursive = $params['recursive'];
    }

    $normalized = cdo_normalize_relative_path($params['path']);
    cdo_validate_writable_relative_path($normalized, 'create_dir');

    $directoryPath = (string) $normalized['filesystemPath'];
    $relativePath = (string) $normalized['relativePath'];

    if (file_exists($directoryPath)) {
        $existingPath = cdo_resolve_existing_path($normalized);

        if (!is_dir($existingPath)) {
            throw new InvalidArgumentException('Path already exists and is not a directory.');
        }

        return [
            'path' => $relativePath,
            'name' => basename($relativePath),
            'created' => false,
            'alreadyExisted' => true,
        ];
    }

    $parentPath = dirname($directoryPath);

    if ($recursive) {
        cdo_resolve_existing_ancestor_path($directoryPath);
    } else {
        $resolvedParentPath = cdo_resolve_existing_ancestor_path($parentPath);

        if (str_replace('\\', '/', realpath($parentPath) ?: '') !== str_replace('\\', '/', $resolvedParentPath)) {
            throw new InvalidArgumentException('Parent directory does not exist.');
        }
    }

    if (!mkdir($directoryPath, 0775, $recursive) && !is_dir($directoryPath)) {
        throw new InvalidArgumentException('Directory could not be created.');
    }

    cdo_resolve_existing_path($normalized);

    return [
        'path' => $relativePath,
        'name' => basename($relativePath),
        'created' => true,
        'alreadyExisted' => false,
    ];
}

function cdo_delete_file_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('delete_file path must be a string.');
    }

    cdo_require_confirm($params, 'delete_file');

    $normalized = cdo_normalize_relative_path($params['path']);
    cdo_validate_writable_relative_path($normalized, 'delete_file');

    cdo_resolve_existing_path($normalized);

    $filePath = (string) $normalized['filesystemPath'];
    $relativePath = (string) $normalized['relativePath'];

    if (is_dir($filePath)) {
        throw new InvalidArgumentException('delete_file path points to a directory.');
    }

    if (!is_file($filePath)) {
        throw new InvalidArgumentException('File does not exist.');
    }

    if (!@unlink($filePath)) {
        throw new InvalidArgumentException('File could not be deleted.');
    }

    return [
        'path' => $relativePath,
        'name' => basename($relativePath),
        'deleted' => true,
    ];
}

function cdo_delete_dir_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('delete_dir path must be a string.');
    }

    cdo_require_confirm($params, 'delete_dir');

    $normalized = cdo_normalize_relative_path($params['path']);
    cdo_validate_writable_relative_path($normalized, 'delete_dir');

    cdo_resolve_existing_path($normalized);

    $directoryPath = (string) $normalized['filesystemPath'];
    $relativePath = (string) $normalized['relativePath'];

    if (!is_dir($directoryPath) || is_link($directoryPath)) {
        throw new InvalidArgumentException('delete_dir path must point to a directory.');
    }

    if (!cdo_directory_is_empty($directoryPath)) {
        throw new InvalidArgumentException('Directory is not empty.');
    }

    if (!@rmdir($directoryPath)) {
        throw new InvalidArgumentException('Directory could not be deleted.');
    }

    return [
        'path' => $relativePath,
        'name' => basename($relativePath),
        'deleted' => true,
    ];
}

function cdo_rename_path_payload(array $params): array
{
    if (!isset($params['from']) || !is_string($params['from'])) {
        throw new InvalidArgumentException('rename_path from must be a string.');
    }

    if (!isset($params['to']) || !is_string($params['to'])) {
        throw new InvalidArgumentException('rename_path to must be a string.');
    }

    cdo_require_confirm($params, 'rename_path');

    $fromNormalized = cdo_normalize_relative_path($params['from']);
    $toNormalized = cdo_normalize_relative_path($params['to']);
    cdo_validate_writable_relative_path($fromNormalized, 'rename_path from');
    cdo_validate_writable_relative_path($toNormalized, 'rename_path to');

    $fromResolvedPath = cdo_resolve_existing_path($fromNormalized);
    $fromPath = (string) $fromNormalized['filesystemPath'];
    $toPath = (string) $toNormalized['filesystemPath'];
    $fromRelativePath = (string) $fromNormalized['relativePath'];
    $toRelativePath = (string) $toNormalized['relativePath'];

    if (!is_file($fromPath) && !is_dir($fromPath)) {
        throw new InvalidArgumentException('rename_path from must point to a file or directory.');
    }

    if (file_exists($toPath) || is_link($toPath)) {
        throw new InvalidArgumentException('rename_path destination already exists.');
    }

    $toParentPath = dirname($toPath);
    $resolvedToParentPath = cdo_resolve_existing_ancestor_path($toParentPath);

    if (str_replace('\\', '/', realpath($toParentPath) ?: '') !== str_replace('\\', '/', $resolvedToParentPath)) {
        throw new InvalidArgumentException('rename_path destination parent does not exist.');
    }

    $type = is_dir($fromPath) ? 'dir' : 'file';

    if ($type === 'dir') {
        $fromResolvedForCompare = strtolower(rtrim(str_replace('\\', '/', $fromResolvedPath), '/'));
        $toParentForCompare = strtolower(rtrim(str_replace('\\', '/', $resolvedToParentPath), '/'));

        if ($toParentForCompare === $fromResolvedForCompare
            || strpos($toParentForCompare, $fromResolvedForCompare . '/') === 0) {
            throw new InvalidArgumentException('rename_path cannot move a directory into itself.');
        }
    }

    if (!@rename($fromPath, $toPath)) {
        throw new InvalidArgumentException('Path could not be renamed.');
    }

    return [
        'from' => $fromRelativePath,
        'to' => $toRelativePath,
        'name' => basename($toRelativePath),
        'type' => $type,
        'renamed' => true,
    ];
}

function cdo_render_invalid_approval_page(): void
{
    $bodyHtml = <<<HTML
<h1>Approval Link Not Available</h1>
<p>この承認リンクは無効か、すでに使用済みです。</p>
<p><code>.cdo_auth.json</code> を削除したあとに、MCPクライアントから再度 <code>request_auth</code> を実行してください。</p>
HTML;

    cdo_render_page('Approval Link Not Available', $bodyHtml, 404);
}

function cdo_render_approval_page(array $state): void
{
    $agentName = isset($state['agentName']) && is_string($state['agentName']) && $state['agentName'] !== ''
        ? htmlspecialchars((string) $state['agentName'], ENT_QUOTES, 'UTF-8')
        : 'unknown-agent';
    $contextHint = cdo_html_text(cdo_public_text($state['contextHint'] ?? null));
    $issuedAt = cdo_html_text(cdo_public_time($state['issuedAt'] ?? null));

    $bodyHtml = <<<HTML
<h1>MCP Approval</h1>
<p>この画面は、ユーザーがAIエージェントへのアクセス許可を確認するための承認画面です。</p>
<p>次のAIエージェントに、Chief-Deployment-Officer の保護ツール利用を許可します。</p>
<ul>
  <li>承認AI: <code>{$agentName}</code></li>
  <li>スレッド手がかり: <code>{$contextHint}</code></li>
  <li>発行日時 issuedAt (UTC): <code>{$issuedAt}</code></li>
</ul>
<p>心当たりがない場合や、対象の対話スレッドを確認できない場合は、この画面を閉じてください。</p>
<p>承認すると、1エージェント専用の Bearer token が有効になります。この画面にtokenは表示しません。</p>
<form method="post">
  <button type="submit" name="approve" value="yes">承認する</button>
</form>
HTML;

    cdo_render_page('MCP Approval', $bodyHtml);
}

function cdo_render_approval_success_page(array $state): void
{
    $agentName = cdo_html_text(cdo_public_text($state['agentName'] ?? null));
    $contextHint = cdo_html_text(cdo_public_text($state['contextHint'] ?? null));
    $approvedAt = cdo_html_text(cdo_public_time($state['approvedAt'] ?? null));

    $bodyHtml = <<<HTML
<h1>Approval Complete</h1>
<p>この画面は、ユーザー向けの承認完了画面です。</p>
<p>AIエージェント用の Bearer token を有効化しました。</p>
<p>承認AI: <code>{$agentName}</code></p>
<p>スレッド手がかり: <code>{$contextHint}</code></p>
<p>承認日時 approvedAt (UTC): <code>{$approvedAt}</code></p>
<p>この画面にtokenは表示しません。元のAIエージェントとの対話に戻り、承認が完了したことを伝えてください。</p>
HTML;

    cdo_render_page('Approval Complete', $bodyHtml);
}

function cdo_handle_approval_request(): bool
{
    if (!isset($_GET[CDO_APPROVAL_QUERY_KEY]) || !is_string($_GET[CDO_APPROVAL_QUERY_KEY])) {
        return false;
    }

    $state = cdo_load_auth_state();
    $state = cdo_refresh_auth_state($state);
    $secret = (string) $_GET[CDO_APPROVAL_QUERY_KEY];

    if ($state === null || ($state['state'] ?? null) !== 'pending') {
        cdo_render_invalid_approval_page();
        return true;
    }

    if (!hash_equals((string) $state['approvalSecret'], $secret)) {
        cdo_render_invalid_approval_page();
        return true;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'POST') {
        $pendingBearerToken = (string) $state['pendingBearerToken'];
        $state['state'] = 'approved';
        $state['bearerTokenHash'] = cdo_hash_secret($pendingBearerToken);
        $state['approvalSecret'] = null;
        $state['pendingBearerToken'] = null;
        $state['approvedAt'] = cdo_now();
        $state['lastUsedAt'] = cdo_now();
        $state['lockedAt'] = null;
        $state['message'] = null;
        cdo_save_auth_state($state);
        cdo_render_approval_success_page($state);
        return true;
    }

    cdo_render_approval_page($state);
    return true;
}

function cdo_parse_json_message(): ?array
{
    $body = trim(cdo_get_request_body());

    if ($body === '') {
        cdo_jsonrpc_error(null, -32600, 'Request body is required.', 400);
        return null;
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        cdo_jsonrpc_error(null, -32700, 'Invalid JSON.', 400, [
            'detail' => $exception->getMessage(),
        ]);
        return null;
    }

    if (!is_array($decoded) || cdo_is_list_array($decoded)) {
        cdo_jsonrpc_error(null, -32600, 'Batch requests are not supported.', 400);
        return null;
    }

    return $decoded;
}

function cdo_handle_request(array $message): void
{
    $id = $message['id'] ?? null;

    if (($message['jsonrpc'] ?? null) !== CDO_JSONRPC_VERSION) {
        cdo_jsonrpc_error($id, -32600, 'jsonrpc must be "2.0".', 400);
        return;
    }

    if (!isset($message['method'])) {
        if (array_key_exists('result', $message) || array_key_exists('error', $message)) {
            cdo_send_empty(202);
            return;
        }

        cdo_jsonrpc_error($id, -32600, 'Request method is required.', 400);
        return;
    }

    if (!is_string($message['method']) || $message['method'] === '') {
        cdo_jsonrpc_error($id, -32600, 'Request method must be a non-empty string.', 400);
        return;
    }

    if (!cdo_protocol_version_is_supported(cdo_get_header('MCP-Protocol-Version'))) {
        cdo_jsonrpc_error($id, -32600, 'Unsupported MCP-Protocol-Version header.', 400, [
            'supported' => CDO_SUPPORTED_PROTOCOL_VERSIONS,
        ]);
        return;
    }

    $method = $message['method'];
    $params = $message['params'] ?? [];
    $isNotification = !array_key_exists('id', $message);
    $authContext = cdo_auth_context();

    if ($isNotification) {
        cdo_send_empty(202);
        return;
    }

    switch ($method) {
        case 'ping':
            cdo_jsonrpc_result($id, []);
            return;

        case 'initialize':
            if (!is_array($params)) {
                cdo_jsonrpc_error($id, -32602, 'initialize params must be an object.');
                return;
            }

            $clientVersion = $params['protocolVersion'] ?? null;

            if (!is_string($clientVersion) || $clientVersion === '') {
                cdo_jsonrpc_error($id, -32602, 'initialize requires params.protocolVersion.');
                return;
            }

            $negotiatedVersion = cdo_negotiate_protocol_version($clientVersion);

            cdo_jsonrpc_result($id, [
                'protocolVersion' => $negotiatedVersion,
                'capabilities' => [
                    'tools' => [
                        'listChanged' => true,
                    ],
                ],
                'serverInfo' => [
                    'name' => CDO_APP_NAME,
                    'version' => CDO_APP_VERSION,
                ],
                'instructions' => 'Use request_auth first. After approval, call tools with Authorization: Bearer <token> or X-CDO-Bearer-Token: <token> to access protected file tools.',
            ], 200, [
                'MCP-Protocol-Version' => $negotiatedVersion,
            ]);
            return;

        case 'tools/list':
            cdo_debug_log('tools_list', cdo_auth_debug_payload($authContext));
            cdo_jsonrpc_result($id, [
                'tools' => cdo_get_tools($authContext),
            ], 200, [
                'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
            ]);
            return;

        case 'tools/call':
            if (!is_array($params)) {
                cdo_jsonrpc_error($id, -32602, 'tools/call params must be an object.');
                return;
            }

            $toolName = $params['name'] ?? null;

            if (!is_string($toolName) || $toolName === '') {
                cdo_jsonrpc_error($id, -32602, 'tools/call requires params.name.');
                return;
            }

            $arguments = $params['arguments'] ?? [];

            if (!is_array($arguments)) {
                cdo_jsonrpc_error($id, -32602, 'tools/call arguments must be an object.');
                return;
            }

            if ($toolName === 'server_status') {
                $status = cdo_server_status_payload($authContext);
                cdo_debug_log('server_status', $status);
                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Chief-Deployment-Officer is reachable. Entry point: '
                    . $status['entrypoint']
                    . ', app version: '
                    . $status['appVersion']
                    . ', protocol version: '
                    . $status['protocolVersion'],
                    $status
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'request_auth') {
                $payload = cdo_request_auth_payload($authContext, $arguments);
                cdo_jsonrpc_result($id, cdo_tool_result($payload['message'], $payload), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'list_dir') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_list_dir_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Listed ' . count($payload['entries']) . ' entries under ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'read_file') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_read_file_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Read ' . $payload['bytesRead'] . ' bytes from ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'write_file') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_write_file_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Wrote ' . $payload['bytesWritten'] . ' bytes to ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'create_dir') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_create_dir_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    ($payload['created'] ? 'Created directory ' : 'Directory already exists: ') . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'delete_file') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_delete_file_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Deleted file ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'delete_dir') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_delete_dir_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Deleted directory ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'rename_path') {
                if (!$authContext['isAuthorized']) {
                    $message = 'Authentication required. Use request_auth and then send Authorization: Bearer <token> or X-CDO-Bearer-Token: <token>.';

                    if (($authContext['state']['state'] ?? null) === 'locked') {
                        $message = (string) ($authContext['state']['message'] ?? 'Authentication is locked.');
                    }

                    cdo_jsonrpc_error($id, -32001, $message, 200, [
                        'reason' => $authContext['reason'],
                    ], [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                try {
                    $payload = cdo_rename_path_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Renamed ' . $payload['from'] . ' to ' . $payload['to'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            cdo_jsonrpc_error($id, -32601, 'Tool not found.', 200, [
                'tool' => $toolName,
            ], [
                'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
            ]);
            return;

        default:
            cdo_jsonrpc_error($id, -32601, 'Method not found.', 200, [
                'method' => $method,
            ], [
                'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
            ]);
            return;
    }
}

function cdo_handle_http_request(): void
{
    if (!cdo_origin_is_allowed()) {
        cdo_jsonrpc_error(null, -32600, 'Origin is not allowed.', 403);
        return;
    }

    if (cdo_handle_approval_request()) {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'DELETE') {
        cdo_send_empty(405, ['Allow' => 'GET, POST']);
        return;
    }

    if ($method === 'GET') {
        if (cdo_accepts_event_stream()) {
            cdo_send_empty(405, ['Allow' => 'POST']);
            return;
        }

        cdo_render_default_page();
        return;
    }

    if ($method !== 'POST') {
        cdo_send_empty(405, ['Allow' => 'GET, POST']);
        return;
    }

    $message = cdo_parse_json_message();

    if ($message === null) {
        return;
    }

    cdo_handle_request($message);
}

cdo_handle_http_request();
