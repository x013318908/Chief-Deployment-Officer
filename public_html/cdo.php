<?php

// SPDX-License-Identifier: CC0-1.0

declare(strict_types=1);

const CDO_APP_NAME = 'Chief-Deployment-Officer';
const CDO_APP_VERSION = '0.2.0';
const CDO_JSONRPC_VERSION = '2.0';
const CDO_PROTOCOL_VERSION = '2025-11-25';
const CDO_SUPPORTED_PROTOCOL_VERSIONS = [
    '2025-11-25',
    '2025-06-18',
    '2025-03-26',
];
const CDO_AUTH_FILE_SUFFIX = '_auth.json';
const CDO_ENV_FILE_SUFFIX = '_env.json';
const CDO_DEBUG_LOG_FILE_SUFFIX = '_debug.log';
const CDO_APPROVAL_QUERY_KEY = 'cdo_approve';
const CDO_ENV_UPLOAD_QUERY_KEY = 'cdo_env_upload';
const CDO_AUTH_IDLE_SECONDS = 2592000;
const CDO_INTERNAL_FILE_PREFIX = '.cdo_';
const CDO_MAX_READ_FILE_BYTES = 1048576;
const CDO_ENV_UPLOAD_EXPIRES_SECONDS = 600;
const CDO_ENV_UPLOAD_MAX_BYTES = 262144;
const CDO_ENV_TARGET_FILE_NAME = 'production.env';

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

function cdo_entrypoint_file_basename(): string
{
    $scriptFilename = (string) ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__);
    $basename = basename($scriptFilename);

    return $basename !== '' ? $basename : basename(__FILE__);
}

function cdo_entrypoint_file_prefix(): string
{
    $filename = cdo_entrypoint_file_basename();
    $prefix = pathinfo($filename, PATHINFO_FILENAME);

    if (!is_string($prefix) || $prefix === '') {
        $prefix = 'cdo';
    }

    $prefix = preg_replace('/[^A-Za-z0-9._-]+/', '-', $prefix);

    if (!is_string($prefix)) {
        return 'cdo';
    }

    $prefix = trim($prefix, '-');

    return $prefix === '' ? 'cdo' : $prefix;
}

function cdo_related_file_name(string $suffix): string
{
    return '.' . cdo_entrypoint_file_prefix() . $suffix;
}

function cdo_auth_file_name(): string
{
    return cdo_related_file_name(CDO_AUTH_FILE_SUFFIX);
}

function cdo_env_file_name(): string
{
    return cdo_related_file_name(CDO_ENV_FILE_SUFFIX);
}

function cdo_debug_log_file_name(): string
{
    return cdo_related_file_name(CDO_DEBUG_LOG_FILE_SUFFIX);
}

function cdo_auth_reset_instruction(): string
{
    return 'Delete ' . cdo_auth_file_name() . ' to reset.';
}

function cdo_auth_distribution_warning(): string
{
    return 'Do not distribute ' . cdo_auth_file_name() . ' or ' . cdo_debug_log_file_name() . '.';
}

function cdo_auth_state_path(): string
{
    $override = getenv('CDO_AUTH_STATE_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return cdo_app_root() . DIRECTORY_SEPARATOR . cdo_auth_file_name();
}

function cdo_env_state_path(): string
{
    $override = getenv('CDO_ENV_STATE_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return cdo_app_root() . DIRECTORY_SEPARATOR . cdo_env_file_name();
}

function cdo_debug_log_path(): string
{
    $override = getenv('CDO_DEBUG_LOG_PATH');

    if (is_string($override) && trim($override) !== '') {
        return $override;
    }

    return cdo_app_root() . DIRECTORY_SEPARATOR . cdo_debug_log_file_name();
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

function cdo_build_env_upload_url(string $secret): string
{
    return sprintf(
        '%s://%s%s?%s=%s',
        cdo_get_request_scheme(),
        cdo_request_host(),
        cdo_get_entrypoint_path(),
        CDO_ENV_UPLOAD_QUERY_KEY,
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

function cdo_public_display_time($value): ?string
{
    $timestamp = is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);

    if ($timestamp <= 0 && is_string($value) && $value !== '') {
        $parsed = strtotime($value);
        $timestamp = $parsed === false ? 0 : $parsed;
    }

    if ($timestamp <= 0) {
        return null;
    }

    return gmdate('Y-m-d H:i:s \U\T\C', $timestamp);
}

function cdo_html_text(?string $value, string $fallback = '未設定'): string
{
    return htmlspecialchars($value ?? $fallback, ENT_QUOTES, 'UTF-8');
}

function cdo_auth_state_message(string $authState): string
{
    switch ($authState) {
        case 'not_configured':
            return 'まだ承認されていません。';
        case 'pending':
            return '承認待ちです。';
        case 'approved':
            return '承認済みです。';
        case 'locked':
            return '認証がロックされています。';
        default:
            return '状態を確認してください。';
    }
}

function cdo_agent_guide_payload(string $endpointUrl): array
{
    return [
        'audience' => 'AI agents using this Chief Deployment Officer endpoint.',
        'endpoint' => $endpointUrl,
        'transport' => 'Use Streamable HTTP with this exact endpoint URL. Do not switch to /sse, /cdo.php, a repository path, or a guessed location. SSE transport is not implemented.',
        'oauth' => 'OAuth/OIDC discovery endpoints are not implemented. Do not start an OAuth flow; use request_auth and bearer headers instead.',
        'firstSteps' => [
            'Call tools/list against this endpoint.',
            'Do not inspect a local repository first unless the user explicitly asks.',
            'Call tools/call server_status and read authState, authorized, authReason, agentName, contextHint, approvedAt, and lastUsedAt.',
            'If not approved, call tools/call request_auth with agentName and contextHint.',
            'Give approvalUrl to the user, ask them to open it and approve, then wait until they report approval is complete.',
            'Keep the returned bearer token private in the agent session. Do not ask the user to paste it back.',
            'After approval, send X-CDO-Bearer-Token: <bearerToken> on future MCP requests. Authorization: Bearer <bearerToken> is also accepted.',
            'Call server_status again and confirm authorized=true before using protected file tools.',
        ],
        'contextHint' => 'Use contextHint as a short clue that helps the user find the approving conversation later, such as "Codex desktop / project or deploy thread / YYYY-MM-DD". Do not include secrets or tokens.',
        'inspector' => 'In MCP Inspector, choose Streamable HTTP and put X-CDO-Bearer-Token: <bearerToken> in the Authentication custom headers. Do not use the OAuth flow.',
        'multiAgent' => 'One file equals one agent authorization. For multiple agents, copy this PHP file to separate filenames in the same directory, such as agent-a.php and agent-b.php, or into separate subdirectories. Each copy has its own URL, auth state, env state, debug log, and approval flow.',
        'protectedTools' => [
            'list_dir',
            'read_file',
            'write_file',
            'create_dir',
            'delete_file',
            'delete_dir',
            'rename_path',
            'stat_path',
            'hash_file',
            'copy_path',
            'get_env_path',
            'request_env_upload',
            'get_runtime_info',
        ],
        'operationRules' => [
            'The root is the directory that contains this endpoint file.',
            'Use relative paths only. Absolute paths, parent directory references, internal control files such as .cdo_*, .*_auth.json, .*_env.json, and .*_debug.log, and the current endpoint file itself are rejected.',
            'Before write/delete/rename, explain the target path to the user and get explicit confirmation.',
            'Use list_dir and read_file to verify targets before destructive or overwriting operations.',
            'write_file requires overwrite:true for existing files, preserves existing permissions on overwrite, and uses the server umask for new files.',
            'delete_file and delete_dir require confirm:true. delete_dir only removes empty directories; recursive delete is not implemented.',
            'rename_path requires confirm:true and never replaces an existing destination. Rename overwrite/replace is not implemented.',
            'Use stat_path and hash_file to inspect path metadata and verify file integrity before and after changes.',
            'Use copy_path for same-server file backups and rollbacks without sending file contents through the AI agent. copy_path only copies files and never creates parent directories.',
            'CDO does not permanently set operating system environment variables. Use get_env_path to obtain the production env file path, then update the application code to read that path.',
            'request_env_upload returns a one-time browser URL for a human user to upload an env file. The AI agent must not upload, read, download, inspect, or ask the user to paste env contents.',
            'The env file is placed outside the document root when available. If no safe outside-document-root path is available, use the hosting provider environment variables or Secrets settings instead.',
            'Use get_runtime_info to inspect safe PHP runtime diagnostics, loaded extensions, and PHP directive changeability. It is not raw phpinfo() output and does not expose $_ENV, $_SERVER, headers, cookies, or environment variable values.',
        ],
        'timeoutHandling' => [
            'If a write/delete/rename tool call times out, Do not retry the same operation immediately.',
            'First call server_status, then use list_dir and read_file to verify the target state.',
            'If the change is already reflected, treat it as success. If it is not reflected, ask the user before running the operation again.',
        ],
        'reset' => 'To reset approval, delete ' . cdo_auth_file_name() . ' next to this endpoint. ' . cdo_auth_distribution_warning(),
    ];
}

function cdo_agent_guide_text(string $endpointUrl): string
{
    $guide = cdo_agent_guide_payload($endpointUrl);
    $firstSteps = implode("\n", array_map(static function (string $item, int $index): string {
        return ($index + 1) . '. ' . $item;
    }, $guide['firstSteps'], array_keys($guide['firstSteps'])));
    $operationRules = implode("\n", array_map(static function (string $item): string {
        return '- ' . $item;
    }, $guide['operationRules']));
    $timeoutHandling = implode("\n", array_map(static function (string $item): string {
        return '- ' . $item;
    }, $guide['timeoutHandling']));
    $protectedTools = implode(', ', $guide['protectedTools']);

    return <<<TEXT
Chief Deployment Officer

This URL is a Streamable HTTP MCP endpoint for AI agents.

Chief Deployment Officer provides controlled file operations on this server after explicit user approval.

Use this exact URL as the MCP endpoint:
{$endpointUrl}

Suggested prompt:
I want to use this as an MCP endpoint:
{$endpointUrl}

Transport and authentication:
- {$guide['transport']}
- {$guide['oauth']}
- {$guide['inspector']}

AI agent instructions for this endpoint:
{$firstSteps}

contextHint:
{$guide['contextHint']}

Multiple agents:
{$guide['multiAgent']}

Protected tools after approval:
{$protectedTools}

Operation rules:
{$operationRules}

Timeout handling:
{$timeoutHandling}

Reset and distribution:
{$guide['reset']}
TEXT;
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
    textarea {
      box-sizing: border-box;
      width: 100%;
      min-height: 92px;
      border: 1px solid rgba(30, 29, 26, 0.16);
      border-radius: 12px;
      padding: 14px;
      background: rgba(255, 255, 255, 0.72);
      color: inherit;
      font: 0.95em Consolas, monospace;
      resize: vertical;
    }
    .copy-control {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 10px;
      align-items: center;
      margin-top: 12px;
    }
    .copy-input {
      box-sizing: border-box;
      width: 100%;
      min-height: 44px;
      border: 1px solid rgba(30, 29, 26, 0.32);
      border-radius: 8px;
      padding: 10px 14px;
      background: #101820;
      color: #f7f8f8;
      font: 0.95em Consolas, monospace;
    }
    .copy-button {
      display: inline-grid;
      place-items: center;
      width: 36px;
      height: 36px;
      border: 0;
      border-radius: 8px;
      padding: 0;
      background: transparent;
      color: #1e1d1a;
    }
    .copy-button:hover,
    .copy-button:focus-visible {
      background: rgba(30, 29, 26, 0.08);
    }
    .copy-button svg {
      display: block;
      width: 23px;
      height: 23px;
    }
    details {
      border: 1px solid rgba(30, 29, 26, 0.12);
      border-radius: 14px;
      padding: 12px 14px;
      margin: 18px 0;
      background: rgba(255, 255, 255, 0.4);
    }
    summary {
      cursor: pointer;
      font-weight: 700;
    }
    .cta,
    .status {
      border-radius: 16px;
      padding: 16px;
      background: rgba(30, 29, 26, 0.06);
      margin: 18px 0;
    }
    .copy-status {
      min-height: 1.5em;
      margin: 8px 0 0;
      font-size: 0.9em;
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
      textarea {
        border-color: rgba(244, 239, 231, 0.18);
        background: rgba(244, 239, 231, 0.08);
      }
      .copy-input {
        border-color: rgba(244, 239, 231, 0.4);
        background: #0d141b;
        color: #f7f8f8;
      }
      .copy-button {
        color: #f4efe7;
      }
      .copy-button:hover,
      .copy-button:focus-visible {
        background: rgba(244, 239, 231, 0.12);
      }
      details,
      .cta,
      .status {
        border-color: rgba(244, 239, 231, 0.12);
        background: rgba(244, 239, 231, 0.06);
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
    $authStateRaw = (string) $auth['authState'];
    $authState = cdo_html_text($authStateRaw);
    $currentStatus = cdo_html_text(cdo_auth_state_message($authStateRaw));
    $authReason = cdo_html_text((string) $auth['authReason']);
    $agentName = cdo_html_text($auth['agentName']);
    $contextHint = cdo_html_text($auth['contextHint']);
    $state = isset($authContext['state']) && is_array($authContext['state'])
        ? $authContext['state']
        : [];
    $issuedAt = cdo_html_text(cdo_public_display_time($state['issuedAt'] ?? null));
    $approvedAt = cdo_html_text(cdo_public_display_time($state['approvedAt'] ?? null));
    $lastUsedAt = cdo_html_text(cdo_public_display_time($state['lastUsedAt'] ?? null));
    $copyPromptText = "これを使いたい `{$endpointUrl}`";
    $copyPrompt = htmlspecialchars($copyPromptText, ENT_QUOTES, 'UTF-8');
    $aiGuide = htmlspecialchars(cdo_agent_guide_text($endpointUrl), ENT_QUOTES, 'UTF-8');

    $bodyHtml = <<<HTML
<h1>Chief Deployment Officer</h1>

<section class="cta" aria-labelledby="copy-prompt-heading">
<h2 id="copy-prompt-heading">このプロンプトをAIエージェントに渡してください。</h2>
<div class="copy-control">
  <input id="cdo-agent-prompt" class="copy-input" type="text" readonly value="{$copyPrompt}">
  <button class="copy-button" type="button" data-copy-target="cdo-agent-prompt" data-copy-status="copy-prompt-status" aria-label="コピー" title="コピー">
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
      <rect x="8" y="3" width="11" height="14" rx="2" stroke="currentColor" stroke-width="2"></rect>
      <path d="M5 7H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-1" stroke="currentColor" stroke-width="2" stroke-linecap="round"></path>
    </svg>
  </button>
</div>
<p class="copy-status" id="copy-prompt-status" aria-live="polite"></p>
</section>

<section class="status" aria-labelledby="current-status-heading">
<h2 id="current-status-heading">現在の状態</h2>
<p>現在の状態: {$currentStatus}</p>
</section>

<details>
<summary>詳細情報</summary>
<ul>
  <li>認証状態: <code>{$authState}</code></li>
  <li>認証理由: <code>{$authReason}</code></li>
  <li>承認AI: <code>{$agentName}</code></li>
  <li>スレッド手がかり: <code>{$contextHint}</code></li>
  <li>発行日時: <code>{$issuedAt}</code></li>
  <li>承認日時: <code>{$approvedAt}</code></li>
  <li>最終利用日時: <code>{$lastUsedAt}</code></li>
</ul>
</details>

<section aria-labelledby="user-guidance-heading">
<h2 id="user-guidance-heading">使い方</h2>
<ol>
  <li>AIエージェントに上記URLを渡します。</li>
  <li>AIエージェントが承認用URLを表示します。</li>
  <li>その承認用URLをブラウザで開き、内容を確認して承認します。</li>
  <li>承認後、AIエージェントとの会話に戻って「承認しました」と伝えてください。</li>
</ol>

<h3>注意</h3>
<ul>
  <li>承認したAIエージェントは、このサーバー上のファイルを読み書きできるようになります。</li>
  <li>削除・上書き・リネームなどの操作は、AIに内容を確認してから実行させてください。</li>
  <li>AIエージェントが応答待ちでtimeoutした場合でも、サーバー側では操作が反映済みの可能性があります。AIには即再実行ではなく状態確認をさせてください。</li>
</ul>
</section>

<details>
<summary>AIエージェント向けの接続手順</summary>
<p>以下はAIエージェント向けの手順です。</p>
<pre><code>{$aiGuide}</code></pre>
</details>

<script>
(function () {
  var buttons = document.querySelectorAll('[data-copy-target]');

  function setStatus(element, message) {
    if (element) {
      element.textContent = message;
    }
  }

  buttons.forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy-target'));
      var status = document.getElementById(button.getAttribute('data-copy-status'));

      if (!target) {
        return;
      }

      var text = target.value || target.textContent || '';
      var fallbackCopy = function () {
        target.focus();

        if (typeof target.select === 'function') {
          target.select();
        }

        try {
          document.execCommand('copy');
          setStatus(status, 'コピーしました。');
        } catch (error) {
          setStatus(status, 'コピーできませんでした。選択済みのテキストをコピーしてください。');
        }
      };

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          setStatus(status, 'コピーしました。');
        }).catch(fallbackCopy);
        return;
      }

      fallbackCopy();
    });
  });
}());
</script>
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
            'message' => 'Auth state file is unreadable. ' . cdo_auth_reset_instruction(),
            'lockedAt' => cdo_now(),
        ];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is invalid. ' . cdo_auth_reset_instruction(),
            'lockedAt' => cdo_now(),
        ];
    }

    if (!is_array($decoded)) {
        return [
            'state' => 'locked',
            'message' => 'Auth state file is invalid. ' . cdo_auth_reset_instruction(),
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
        $state['message'] = 'Auth state file is invalid. ' . cdo_auth_reset_instruction();
    }

    if ($state['state'] === 'pending') {
        if (!is_string($state['approvalSecret']) || $state['approvalSecret'] === ''
            || !is_string($state['pendingBearerToken']) || $state['pendingBearerToken'] === '') {
            $state['state'] = 'locked';
            $state['message'] = 'Pending auth state is invalid. ' . cdo_auth_reset_instruction();
        }
    }

    if ($state['state'] === 'approved') {
        if (!is_string($state['bearerTokenHash']) || $state['bearerTokenHash'] === '') {
            $state['state'] = 'locked';
            $state['message'] = 'Approved auth state is invalid. ' . cdo_auth_reset_instruction();
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

function cdo_load_env_state(): array
{
    $path = cdo_env_state_path();

    if (!is_file($path)) {
        return [
            'version' => 1,
            'envPath' => null,
            'uploadedAt' => null,
            'pendingUpload' => null,
        ];
    }

    $raw = @file_get_contents($path);

    if (!is_string($raw) || $raw === '') {
        return [
            'version' => 1,
            'envPath' => null,
            'uploadedAt' => null,
            'pendingUpload' => null,
        ];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return [
            'version' => 1,
            'envPath' => null,
            'uploadedAt' => null,
            'pendingUpload' => null,
        ];
    }

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $state = array_merge([
        'version' => 1,
        'envPath' => null,
        'uploadedAt' => null,
        'pendingUpload' => null,
    ], $decoded);

    if (!is_string($state['envPath']) || trim($state['envPath']) === '') {
        $state['envPath'] = null;
    }

    if (!is_int($state['uploadedAt']) && !is_numeric($state['uploadedAt'])) {
        $state['uploadedAt'] = null;
    }

    if (!is_array($state['pendingUpload'])) {
        $state['pendingUpload'] = null;
    }

    return $state;
}

function cdo_save_env_state(array $state): void
{
    $state['version'] = 1;

    file_put_contents(
        cdo_env_state_path(),
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function cdo_normalized_absolute_path(string $path): string
{
    $path = str_replace('\\', '/', $path);

    return rtrim($path, '/');
}

function cdo_path_is_under_root(string $path, string $root): bool
{
    $path = cdo_normalized_absolute_path($path);
    $root = cdo_normalized_absolute_path($root);

    if (cdo_is_windows_environment()) {
        $path = strtolower($path);
        $root = strtolower($root);
    }

    return $path === $root || strpos($path, $root . '/') === 0;
}

function cdo_document_root_path(): ?string
{
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;

    if (!is_string($documentRoot) || trim($documentRoot) === '') {
        return null;
    }

    $realpath = realpath($documentRoot);

    return is_string($realpath) ? $realpath : null;
}

function cdo_env_app_root_hash(): string
{
    $root = realpath(cdo_app_root());

    if (!is_string($root)) {
        $root = cdo_app_root();
    }

    $hashInput = cdo_normalized_absolute_path($root) . '/' . cdo_entrypoint_file_basename();

    return substr(hash('sha256', $hashInput), 0, 16);
}

function cdo_public_html_ancestor_path(string $path): ?string
{
    $current = realpath($path);

    if (!is_string($current)) {
        $current = $path;
    }

    while (true) {
        $basename = basename($current);

        if (strcasecmp($basename, 'public_html') === 0) {
            return $current;
        }

        $parent = dirname($current);

        if ($parent === $current) {
            return null;
        }

        $current = $parent;
    }
}

function cdo_env_storage_base_directory(): ?string
{
    $documentRoot = cdo_document_root_path();

    if ($documentRoot === null) {
        return null;
    }

    $publicHtmlRoot = cdo_public_html_ancestor_path($documentRoot);

    if ($publicHtmlRoot !== null) {
        return dirname($publicHtmlRoot);
    }

    return dirname($documentRoot);
}

function cdo_computed_env_path(): ?string
{
    $baseDirectory = cdo_env_storage_base_directory();

    if ($baseDirectory === null) {
        return null;
    }

    return $baseDirectory
        . DIRECTORY_SEPARATOR
        . '.cdo-secrets'
        . DIRECTORY_SEPARATOR
        . cdo_env_app_root_hash()
        . DIRECTORY_SEPARATOR
        . CDO_ENV_TARGET_FILE_NAME;
}

function cdo_env_path_is_outside_document_root(string $envPath): bool
{
    $documentRoot = cdo_document_root_path();

    if ($documentRoot === null) {
        return false;
    }

    return !cdo_path_is_under_root($envPath, $documentRoot);
}

function cdo_env_path_is_outside_public_html(string $envPath): bool
{
    $documentRoot = cdo_document_root_path();

    if ($documentRoot === null) {
        return false;
    }

    $publicHtmlRoot = cdo_public_html_ancestor_path($documentRoot);

    if ($publicHtmlRoot === null) {
        return true;
    }

    return !cdo_path_is_under_root($envPath, $publicHtmlRoot);
}

function cdo_existing_env_ancestor_path(string $envPath): ?string
{
    $candidate = dirname($envPath);

    while (!file_exists($candidate)) {
        $parent = dirname($candidate);

        if ($parent === $candidate) {
            return null;
        }

        $candidate = $parent;
    }

    return is_dir($candidate) ? $candidate : null;
}

function cdo_env_path_status(?string $envPath, ?int $uploadedAt = null): array
{
    $documentRoot = cdo_document_root_path();

    if ($documentRoot === null) {
        return [
            'envPath' => null,
            'available' => false,
            'uploaded' => $uploadedAt !== null,
            'uploadedAt' => cdo_public_time($uploadedAt),
            'outsideDocumentRoot' => false,
            'readableByPhp' => false,
            'writable' => false,
            'reason' => 'document_root_unavailable',
        ];
    }

    if ($envPath === null || trim($envPath) === '') {
        return [
            'envPath' => null,
            'available' => false,
            'uploaded' => $uploadedAt !== null,
            'uploadedAt' => cdo_public_time($uploadedAt),
            'outsideDocumentRoot' => false,
            'readableByPhp' => false,
            'writable' => false,
            'reason' => 'env_path_unavailable',
        ];
    }

    $outsideDocumentRoot = cdo_env_path_is_outside_document_root($envPath);
    $outsidePublicHtml = cdo_env_path_is_outside_public_html($envPath);
    $directory = dirname($envPath);
    $ancestor = cdo_existing_env_ancestor_path($envPath);
    $writable = is_dir($directory) ? is_writable($directory) : ($ancestor !== null && is_writable($ancestor));
    $readableByPhp = is_file($envPath) ? is_readable($envPath) : ($ancestor !== null && is_readable($ancestor));
    $reason = 'ready';

    if (!$outsideDocumentRoot) {
        $reason = 'inside_document_root';
    } elseif (!$outsidePublicHtml) {
        $reason = 'inside_public_html';
    } elseif (!$writable) {
        $reason = 'env_directory_not_writable';
    } elseif (!$readableByPhp) {
        $reason = 'env_path_not_readable_by_php';
    }

    return [
        'envPath' => $envPath,
        'available' => $outsideDocumentRoot && $outsidePublicHtml && $writable && $readableByPhp,
        'uploaded' => $uploadedAt !== null,
        'uploadedAt' => cdo_public_time($uploadedAt),
        'outsideDocumentRoot' => $outsideDocumentRoot,
        'readableByPhp' => $readableByPhp,
        'writable' => $writable,
        'reason' => $reason,
    ];
}

function cdo_env_path_payload(): array
{
    $state = cdo_load_env_state();
    $uploadedAt = isset($state['uploadedAt']) && is_numeric($state['uploadedAt'])
        ? (int) $state['uploadedAt']
        : null;
    $configuredEnvPath = is_string($state['envPath'] ?? null) ? trim((string) $state['envPath']) : '';
    $envPath = $configuredEnvPath !== '' ? $configuredEnvPath : cdo_computed_env_path();

    return cdo_env_path_status($envPath, $uploadedAt);
}

function cdo_request_env_upload_payload(): array
{
    $payload = cdo_env_path_payload();

    if (!$payload['available'] || !is_string($payload['envPath']) || $payload['envPath'] === '') {
        return [
            'status' => 'unavailable',
            'message' => 'CDO could not find a writable secrets path outside the document root. Use your hosting provider environment variables or Secrets settings instead.',
            'uploadUrl' => null,
            'envPath' => $payload['envPath'],
            'expiresAt' => null,
            'expiresInSeconds' => null,
            'available' => false,
            'reason' => $payload['reason'],
        ];
    }

    $secret = cdo_generate_secret();
    $issuedAt = cdo_now();
    $expiresAt = $issuedAt + CDO_ENV_UPLOAD_EXPIRES_SECONDS;
    $state = cdo_load_env_state();
    $state['pendingUpload'] = [
        'tokenHash' => cdo_hash_secret($secret),
        'envPath' => $payload['envPath'],
        'issuedAt' => $issuedAt,
        'expiresAt' => $expiresAt,
    ];
    cdo_save_env_state($state);

    return [
        'status' => 'pending_upload',
        'message' => 'Give uploadUrl to the user. The browser upload link is valid once for 10 minutes. The agent cannot read, upload, download, or inspect env contents.',
        'uploadUrl' => cdo_build_env_upload_url($secret),
        'envPath' => $payload['envPath'],
        'expiresAt' => cdo_public_time($expiresAt),
        'expiresInSeconds' => CDO_ENV_UPLOAD_EXPIRES_SECONDS,
        'available' => true,
        'reason' => $payload['reason'],
    ];
}

function cdo_runtime_scalar_value($value): ?string
{
    if ($value === null || $value === false) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return null;
}

function cdo_runtime_ini_value(string $name): ?string
{
    $value = ini_get($name);

    return cdo_runtime_scalar_value($value);
}

function cdo_runtime_scanned_ini_files(): array
{
    $scannedFiles = php_ini_scanned_files();

    if (!is_string($scannedFiles) || trim($scannedFiles) === '') {
        return [];
    }

    $files = preg_split('/,\s*/', $scannedFiles);

    if (!is_array($files)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', $files), static function (string $file): bool {
        return $file !== '';
    }));
}

function cdo_runtime_user_ini_support(): array
{
    $filename = cdo_runtime_ini_value('user_ini.filename');
    $cacheTtl = cdo_runtime_ini_value('user_ini.cache_ttl');
    $filenameConfigured = is_string($filename) && trim($filename) !== '';
    $supportedBySapi = in_array(PHP_SAPI, ['cgi-fcgi', 'fpm-fcgi'], true);
    $likelySupported = $filenameConfigured && $supportedBySapi;
    $reason = 'supported_by_sapi';

    if (!$filenameConfigured) {
        $reason = 'user_ini_filename_empty';
    } elseif (!$supportedBySapi) {
        $reason = 'unsupported_sapi_' . PHP_SAPI;
    }

    return [
        'filename' => $filename,
        'cacheTtl' => $cacheTtl,
        'supportedBySapi' => $supportedBySapi,
        'likelySupported' => $likelySupported,
        'reason' => $reason,
    ];
}

function cdo_runtime_htaccess_directive_support(): array
{
    $likelySupported = PHP_SAPI === 'apache2handler';

    return [
        'likelySupported' => $likelySupported,
        'reason' => $likelySupported
            ? 'current_sapi_is_apache2handler'
            : 'current_sapi_is_' . PHP_SAPI . '_not_apache2handler',
    ];
}

function cdo_runtime_ini_access_labels(int $access): array
{
    $labels = [];

    if (($access & INI_USER) !== 0) {
        $labels[] = 'INI_USER';
    }

    if (($access & INI_PERDIR) !== 0) {
        $labels[] = 'INI_PERDIR';
    }

    if (($access & INI_SYSTEM) !== 0) {
        $labels[] = 'INI_SYSTEM';
    }

    if ($access === INI_ALL) {
        $labels[] = 'INI_ALL';
    }

    return $labels;
}

function cdo_runtime_directive_settable_via(int $access, bool $userIniLikelySupported, bool $htaccessLikelySupported): array
{
    return [
        'iniSet' => ($access & INI_USER) !== 0,
        'userIni' => $userIniLikelySupported && (($access & (INI_USER | INI_PERDIR)) !== 0),
        'htaccess' => $htaccessLikelySupported && (($access & INI_PERDIR) !== 0),
        'phpIni' => $access > 0,
    ];
}

function cdo_runtime_directives_payload(array $userIniSupport, array $htaccessSupport): array
{
    $directives = ini_get_all(null, true);

    if (!is_array($directives)) {
        return [];
    }

    ksort($directives);
    $payload = [];
    $userIniLikelySupported = (bool) ($userIniSupport['likelySupported'] ?? false);
    $htaccessLikelySupported = (bool) ($htaccessSupport['likelySupported'] ?? false);

    foreach ($directives as $name => $directive) {
        if (!is_string($name) || !is_array($directive)) {
            continue;
        }

        $globalValue = cdo_runtime_scalar_value($directive['global_value'] ?? null);
        $effectiveValue = cdo_runtime_scalar_value($directive['local_value'] ?? null);
        $access = isset($directive['access']) && is_numeric($directive['access'])
            ? (int) $directive['access']
            : 0;

        $payload[$name] = [
            'globalValue' => $globalValue,
            'effectiveValue' => $effectiveValue,
            'overridden' => $globalValue !== $effectiveValue,
            'accessRaw' => $access,
            'accessLabels' => cdo_runtime_ini_access_labels($access),
            'settableVia' => cdo_runtime_directive_settable_via(
                $access,
                $userIniLikelySupported,
                $htaccessLikelySupported
            ),
        ];
    }

    return $payload;
}

function cdo_runtime_extension_capability_names(): array
{
    return [
        'curl',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_mysql',
        'sqlite3',
        'zip',
        'zlib',
        'gd',
        'intl',
        'xml',
        'dom',
        'fileinfo',
        'ftp',
    ];
}

function cdo_runtime_extensions_payload(): array
{
    $loadedExtensions = get_loaded_extensions();
    sort($loadedExtensions, SORT_STRING | SORT_FLAG_CASE);
    $capabilities = [];

    foreach (cdo_runtime_extension_capability_names() as $extensionName) {
        $capabilities[$extensionName] = extension_loaded($extensionName);
    }

    return [
        'loaded' => array_values($loadedExtensions),
        'capabilities' => $capabilities,
    ];
}

function cdo_runtime_info_payload(): array
{
    $userIniSupport = cdo_runtime_user_ini_support();
    $htaccessSupport = cdo_runtime_htaccess_directive_support();

    return [
        'php' => [
            'version' => PHP_VERSION,
            'versionId' => PHP_VERSION_ID,
            'sapi' => PHP_SAPI,
            'osFamily' => PHP_OS_FAMILY,
            'os' => PHP_OS,
        ],
        'configurationFiles' => [
            'loadedPhpIni' => cdo_runtime_scalar_value(php_ini_loaded_file()),
            'scannedIniFiles' => cdo_runtime_scanned_ini_files(),
        ],
        'userIniSupport' => $userIniSupport,
        'htaccessDirectiveSupport' => $htaccessSupport,
        'directives' => cdo_runtime_directives_payload($userIniSupport, $htaccessSupport),
        'extensions' => cdo_runtime_extensions_payload(),
    ];
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
    $state['message'] = 'The MCP bearer token has expired after 30 days of inactivity. ' . cdo_auth_reset_instruction();
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
            'description' => 'Return non-sensitive server metadata, auth status, and AI-agent usage guidance for connectivity checks.',
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
                    'endpoint' => ['type' => 'string'],
                    'ready' => ['type' => 'boolean'],
                    'agentGuide' => [
                        'type' => 'object',
                        'properties' => [
                            'audience' => ['type' => 'string'],
                            'endpoint' => ['type' => 'string'],
                            'transport' => ['type' => 'string'],
                            'oauth' => ['type' => 'string'],
                            'firstSteps' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'contextHint' => ['type' => 'string'],
                            'inspector' => ['type' => 'string'],
                            'multiAgent' => ['type' => 'string'],
                            'protectedTools' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'operationRules' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'timeoutHandling' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'reset' => ['type' => 'string'],
                        ],
                        'required' => [
                            'audience',
                            'endpoint',
                            'transport',
                            'oauth',
                            'firstSteps',
                            'contextHint',
                            'inspector',
                            'multiAgent',
                            'protectedTools',
                            'operationRules',
                            'timeoutHandling',
                            'reset',
                        ],
                    ],
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
                    'endpoint',
                    'ready',
                    'agentGuide',
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

function cdo_get_stat_path_tool(): array
{
    return [
        'name' => 'stat_path',
        'title' => 'Stat Path',
        'description' => 'Return existence, type, size, mtime, and readability/writability for a relative path under the MCP entrypoint directory.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => ['path'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'exists' => ['type' => 'boolean'],
                'type' => ['type' => 'string'],
                'size' => ['type' => ['integer', 'null']],
                'mtime' => ['type' => ['integer', 'null']],
                'readable' => ['type' => 'boolean'],
                'writable' => ['type' => 'boolean'],
            ],
            'required' => ['path', 'name', 'exists', 'type', 'size', 'mtime', 'readable', 'writable'],
        ],
    ];
}

function cdo_get_hash_file_tool(): array
{
    return [
        'name' => 'hash_file',
        'title' => 'Hash File',
        'description' => 'Return the SHA-256 hash, size, and mtime for a readable file under the MCP entrypoint directory without returning file contents.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
            ],
            'required' => ['path'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'algorithm' => ['type' => 'string'],
                'hash' => ['type' => 'string'],
                'size' => ['type' => 'integer'],
                'mtime' => ['type' => 'integer'],
            ],
            'required' => ['path', 'name', 'algorithm', 'hash', 'size', 'mtime'],
        ],
    ];
}

function cdo_get_copy_path_tool(): array
{
    return [
        'name' => 'copy_path',
        'title' => 'Copy Path',
        'description' => 'Copy a file within the same remote server under the MCP entrypoint directory. This does not read file contents through the agent, does not copy directories, and does not create parent directories.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'overwrite' => ['type' => 'boolean'],
            ],
            'required' => ['from', 'to'],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'bytesCopied' => ['type' => 'integer'],
                'overwritten' => ['type' => 'boolean'],
            ],
            'required' => ['from', 'to', 'name', 'bytesCopied', 'overwritten'],
        ],
    ];
}

function cdo_get_env_path_tool(): array
{
    return [
        'name' => 'get_env_path',
        'title' => 'Get Env Path',
        'description' => 'Return the single production env file path chosen by CDO. This returns only path and safety metadata, never env contents, key names, or values.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => new stdClass(),
            'required' => [],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'envPath' => ['type' => ['string', 'null']],
                'available' => ['type' => 'boolean'],
                'uploaded' => ['type' => 'boolean'],
                'uploadedAt' => ['type' => ['string', 'null']],
                'outsideDocumentRoot' => ['type' => 'boolean'],
                'readableByPhp' => ['type' => 'boolean'],
                'writable' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
            'required' => [
                'envPath',
                'available',
                'uploaded',
                'uploadedAt',
                'outsideDocumentRoot',
                'readableByPhp',
                'writable',
                'reason',
            ],
        ],
    ];
}

function cdo_get_request_env_upload_tool(): array
{
    return [
        'name' => 'request_env_upload',
        'title' => 'Request Env Upload',
        'description' => 'Generate a one-time browser upload link for replacing the production env file. The agent cannot upload, read, download, or inspect env contents.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => new stdClass(),
            'required' => [],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string'],
                'message' => ['type' => 'string'],
                'uploadUrl' => ['type' => ['string', 'null']],
                'envPath' => ['type' => ['string', 'null']],
                'expiresAt' => ['type' => ['string', 'null']],
                'expiresInSeconds' => ['type' => ['integer', 'null']],
                'available' => ['type' => 'boolean'],
                'reason' => ['type' => 'string'],
            ],
            'required' => [
                'status',
                'message',
                'uploadUrl',
                'envPath',
                'expiresAt',
                'expiresInSeconds',
                'available',
                'reason',
            ],
        ],
    ];
}

function cdo_get_runtime_info_tool(): array
{
    return [
        'name' => 'get_runtime_info',
        'title' => 'Get Runtime Info',
        'description' => 'Return safe PHP runtime diagnostics, loaded extensions, and PHP directive changeability. This is not raw phpinfo() output and does not expose environment variables, headers, or cookies.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => new stdClass(),
            'required' => [],
        ],
        'outputSchema' => [
            'type' => 'object',
            'properties' => [
                'php' => ['type' => 'object'],
                'configurationFiles' => ['type' => 'object'],
                'userIniSupport' => ['type' => 'object'],
                'htaccessDirectiveSupport' => ['type' => 'object'],
                'directives' => ['type' => 'object'],
                'extensions' => ['type' => 'object'],
            ],
            'required' => [
                'php',
                'configurationFiles',
                'userIniSupport',
                'htaccessDirectiveSupport',
                'directives',
                'extensions',
            ],
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
        $tools[] = cdo_get_stat_path_tool();
        $tools[] = cdo_get_hash_file_tool();
        $tools[] = cdo_get_copy_path_tool();
        $tools[] = cdo_get_env_path_tool();
        $tools[] = cdo_get_request_env_upload_tool();
        $tools[] = cdo_get_runtime_info_tool();
    }

    return $tools;
}

function cdo_server_status_payload(array $authContext): array
{
    $endpointUrl = cdo_build_public_url(cdo_get_entrypoint_path());

    return array_merge([
        'appName' => CDO_APP_NAME,
        'appVersion' => CDO_APP_VERSION,
        'protocolVersion' => CDO_PROTOCOL_VERSION,
        'supportedProtocolVersions' => CDO_SUPPORTED_PROTOCOL_VERSIONS,
        'entrypoint' => cdo_get_entrypoint_path(),
        'endpoint' => $endpointUrl,
        'ready' => true,
        'agentGuide' => cdo_agent_guide_payload($endpointUrl),
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
            'message' => (string) ($state['message'] ?? 'Authentication is locked. ' . cdo_auth_reset_instruction()),
            'approved' => false,
        ]);
    }

    return array_merge(cdo_auth_state_public_payload($state), [
        'status' => 'already_configured',
        'message' => 'An approved agent is already configured. ' . cdo_auth_reset_instruction(),
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

function cdo_is_internal_control_file_name(string $name): bool
{
    if (strpos($name, CDO_INTERNAL_FILE_PREFIX) === 0) {
        return true;
    }

    if (preg_match('/^\..+_(auth|env)\.json$/', $name) === 1) {
        return true;
    }

    if (preg_match('/^\..+_debug\.log$/', $name) === 1) {
        return true;
    }

    return false;
}

function cdo_path_contains_internal_file(string $relativePath): bool
{
    foreach (explode('/', $relativePath) as $segment) {
        if (cdo_is_internal_control_file_name($segment)) {
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

function cdo_validate_readable_relative_path(array $normalized, string $operation): void
{
    $relativePath = (string) $normalized['relativePath'];

    if ($relativePath === '.') {
        throw new InvalidArgumentException($operation . ' path must not be the entrypoint directory.');
    }

    if (cdo_path_contains_internal_file($relativePath)) {
        throw new InvalidArgumentException('Internal control files cannot be read.');
    }
}

function cdo_validate_copy_source_relative_path(array $normalized): void
{
    cdo_validate_readable_relative_path($normalized, 'copy_path from');

    if (cdo_target_is_current_entrypoint((string) $normalized['filesystemPath'])) {
        throw new InvalidArgumentException('The current entrypoint file cannot be copied.');
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

        if (cdo_is_internal_control_file_name($name)) {
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

function cdo_stat_path_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('stat_path path must be a string.');
    }

    $normalized = cdo_normalize_relative_path($params['path']);
    $relativePath = (string) $normalized['relativePath'];
    $filesystemPath = (string) $normalized['filesystemPath'];

    if (cdo_path_contains_internal_file($relativePath)) {
        throw new InvalidArgumentException('Internal control files cannot be inspected.');
    }

    if (!file_exists($filesystemPath)) {
        cdo_resolve_existing_ancestor_path($filesystemPath);

        return [
            'path' => $relativePath,
            'name' => $relativePath === '.' ? '.' : basename($relativePath),
            'exists' => false,
            'type' => 'missing',
            'size' => null,
            'mtime' => null,
            'readable' => false,
            'writable' => false,
        ];
    }

    $resolvedPath = cdo_resolve_existing_path($normalized);
    $type = 'other';
    $size = null;

    if (is_file($resolvedPath)) {
        $type = 'file';
        $size = (int) filesize($resolvedPath);
    } elseif (is_dir($resolvedPath)) {
        $type = 'dir';
        $size = 0;
    }

    return [
        'path' => $relativePath,
        'name' => $relativePath === '.' ? '.' : basename($relativePath),
        'exists' => true,
        'type' => $type,
        'size' => $size,
        'mtime' => (int) filemtime($resolvedPath),
        'readable' => is_readable($resolvedPath),
        'writable' => is_writable($resolvedPath),
    ];
}

function cdo_hash_file_payload(array $params): array
{
    if (!isset($params['path']) || !is_string($params['path'])) {
        throw new InvalidArgumentException('hash_file path must be a string.');
    }

    $normalized = cdo_normalize_relative_path($params['path']);
    cdo_validate_readable_relative_path($normalized, 'hash_file');
    $relativePath = (string) $normalized['relativePath'];
    $filePath = cdo_resolve_existing_path($normalized);

    if (!is_file($filePath)) {
        throw new InvalidArgumentException('hash_file path must point to a file.');
    }

    if (!is_readable($filePath)) {
        throw new InvalidArgumentException('hash_file path is not readable.');
    }

    $hash = hash_file('sha256', $filePath);

    if (!is_string($hash)) {
        throw new InvalidArgumentException('hash_file could not hash the file.');
    }

    return [
        'path' => $relativePath,
        'name' => basename($relativePath),
        'algorithm' => 'sha256',
        'hash' => $hash,
        'size' => (int) filesize($filePath),
        'mtime' => (int) filemtime($filePath),
    ];
}

function cdo_copy_path_payload(array $params): array
{
    if (!isset($params['from']) || !is_string($params['from'])) {
        throw new InvalidArgumentException('copy_path from must be a string.');
    }

    if (!isset($params['to']) || !is_string($params['to'])) {
        throw new InvalidArgumentException('copy_path to must be a string.');
    }

    $overwrite = false;

    if (isset($params['overwrite'])) {
        if (!is_bool($params['overwrite'])) {
            throw new InvalidArgumentException('copy_path overwrite must be a boolean.');
        }

        $overwrite = $params['overwrite'];
    }

    $fromNormalized = cdo_normalize_relative_path($params['from']);
    $toNormalized = cdo_normalize_relative_path($params['to']);
    cdo_validate_copy_source_relative_path($fromNormalized);
    cdo_validate_writable_relative_path($toNormalized, 'copy_path to');

    $fromRelativePath = (string) $fromNormalized['relativePath'];
    $toRelativePath = (string) $toNormalized['relativePath'];
    $sourcePath = cdo_resolve_existing_path($fromNormalized);
    $destinationPath = (string) $toNormalized['filesystemPath'];
    $parentPath = dirname($destinationPath);
    $resolvedParentPath = cdo_resolve_existing_ancestor_path($parentPath);

    if (!is_file($sourcePath)) {
        throw new InvalidArgumentException('copy_path from must point to a file.');
    }

    if (!is_readable($sourcePath)) {
        throw new InvalidArgumentException('copy_path from is not readable.');
    }

    if (str_replace('\\', '/', realpath($parentPath) ?: '') !== str_replace('\\', '/', $resolvedParentPath)) {
        throw new InvalidArgumentException('copy_path destination parent does not exist.');
    }

    if (is_dir($destinationPath)) {
        throw new InvalidArgumentException('copy_path destination points to a directory.');
    }

    $destinationExists = is_file($destinationPath);

    if ($destinationExists && !$overwrite) {
        throw new InvalidArgumentException('copy_path destination already exists. Set overwrite to true to replace it.');
    }

    if (file_exists($destinationPath) && !$destinationExists) {
        throw new InvalidArgumentException('copy_path destination exists but is not a file.');
    }

    if ($destinationExists) {
        $resolvedDestinationPath = realpath($destinationPath);

        if (is_string($resolvedDestinationPath)
            && strtolower(str_replace('\\', '/', $resolvedDestinationPath)) === strtolower(str_replace('\\', '/', $sourcePath))) {
            throw new InvalidArgumentException('copy_path from and to must be different files.');
        }
    }

    $sourceFileMode = cdo_existing_file_mode($sourcePath);
    $temporaryPath = tempnam($resolvedParentPath, '.cdo_copy_');

    if (!is_string($temporaryPath)) {
        throw new InvalidArgumentException('Temporary file could not be created.');
    }

    if (!@copy($sourcePath, $temporaryPath)) {
        @unlink($temporaryPath);
        throw new InvalidArgumentException('File could not be copied.');
    }

    try {
        cdo_apply_file_mode($temporaryPath, $sourceFileMode);
    } catch (InvalidArgumentException $exception) {
        @unlink($temporaryPath);
        throw $exception;
    }

    $renamed = @rename($temporaryPath, $destinationPath);

    if (!$renamed && $destinationExists && $overwrite) {
        @unlink($destinationPath);
        $renamed = @rename($temporaryPath, $destinationPath);
    }

    if (!$renamed) {
        @unlink($temporaryPath);
        throw new InvalidArgumentException('File could not be copied.');
    }

    return [
        'from' => $fromRelativePath,
        'to' => $toRelativePath,
        'name' => basename($toRelativePath),
        'bytesCopied' => (int) filesize($destinationPath),
        'overwritten' => $destinationExists,
    ];
}

function cdo_is_windows_environment(): bool
{
    return DIRECTORY_SEPARATOR === '\\';
}

function cdo_new_file_mode(): int
{
    return 0666 & ~umask();
}

function cdo_existing_file_mode(string $path): int
{
    $mode = @fileperms($path);

    if (!is_int($mode)) {
        throw new InvalidArgumentException('Existing file permissions could not be read.');
    }

    return $mode & 07777;
}

function cdo_apply_file_mode(string $path, int $mode): void
{
    if (@chmod($path, $mode)) {
        return;
    }

    if (cdo_is_windows_environment()) {
        return;
    }

    throw new InvalidArgumentException('Temporary file permissions could not be set.');
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

    $targetFileMode = $alreadyExists ? cdo_existing_file_mode($filePath) : cdo_new_file_mode();
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

    try {
        cdo_apply_file_mode($temporaryPath, $targetFileMode);
    } catch (InvalidArgumentException $exception) {
        @unlink($temporaryPath);
        throw $exception;
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

function cdo_render_invalid_approval_page(?array $state): void
{
    $state = $state ?? [];
    $agentName = cdo_html_text(cdo_public_text($state['agentName'] ?? null));
    $entrypointPath = cdo_get_entrypoint_path();
    $entrypointHref = htmlspecialchars($entrypointPath, ENT_QUOTES, 'UTF-8');
    $authFileName = cdo_html_text(cdo_auth_file_name());

    $bodyHtml = <<<HTML
<h1>無効な承認リンク</h1>
<p>この承認リンクは使用できません。<br>すでに使用済み、期限切れ、または別の承認リクエストで置き換えられた可能性があります。</p>
<p>すでに承認した場合は、AIエージェント（{$agentName}）との会話に戻って「承認しました」と伝えてください。<br>まだ承認していない場合は、AIエージェントに承認リンクを再発行してもらってください。</p>
<p>認証を取り消すには、<code>{$authFileName}</code> を削除してください。<a href="{$entrypointHref}">最初の手順</a>から再びやり直せるようになります。</p>
HTML;

    cdo_render_page('無効な承認リンク', $bodyHtml, 404);
}

function cdo_render_approval_page(array $state): void
{
    $agentName = cdo_html_text(cdo_public_text($state['agentName'] ?? null));
    $contextHint = cdo_html_text(cdo_public_text($state['contextHint'] ?? null));
    $issuedAt = cdo_html_text(cdo_public_time($state['issuedAt'] ?? null));

    $bodyHtml = <<<HTML
<h1>承認</h1>
<p>AIエージェント（{$agentName}）にこのサーバーの操作を許可します。</p>
<p><code>{$contextHint}</code></p>
<p><code>{$issuedAt}</code></p>
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
    $approvedAt = cdo_html_text(cdo_public_display_time($state['approvedAt'] ?? null));

    $bodyHtml = <<<HTML
<h1>承認完了</h1>
<p>AIエージェントがこのサーバーを操作できるようになりました。</p>
<p>AIエージェント（{$agentName}）に「できた」と伝えてください。</p>
<p><code>{$contextHint}</code></p>
<p><code>{$approvedAt}</code></p>
HTML;

    cdo_render_page('承認完了', $bodyHtml);
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
        cdo_render_invalid_approval_page($state);
        return true;
    }

    if (!hash_equals((string) $state['approvalSecret'], $secret)) {
        cdo_render_invalid_approval_page($state);
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

function cdo_env_upload_token_context(string $secret): array
{
    $state = cdo_load_env_state();
    $pending = $state['pendingUpload'] ?? null;

    if (!is_array($pending)
        || !isset($pending['tokenHash'], $pending['envPath'], $pending['expiresAt'])
        || !is_string($pending['tokenHash'])
        || !is_string($pending['envPath'])
        || !is_numeric($pending['expiresAt'])) {
        return [
            'valid' => false,
            'state' => $state,
            'pending' => null,
            'reason' => 'invalid_or_used_upload_token',
            'message' => 'このアップロードURLは無効、または使用済みです。',
        ];
    }

    if ((int) $pending['expiresAt'] < cdo_now()) {
        $state['pendingUpload'] = null;
        cdo_save_env_state($state);

        return [
            'valid' => false,
            'state' => $state,
            'pending' => null,
            'reason' => 'expired_upload_token',
            'message' => 'このアップロードURLは期限切れです。AIエージェントに新しいURLを発行してもらってください。',
        ];
    }

    if (!hash_equals($pending['tokenHash'], cdo_hash_secret($secret))) {
        return [
            'valid' => false,
            'state' => $state,
            'pending' => null,
            'reason' => 'invalid_upload_token',
            'message' => 'このアップロードURLは無効です。',
        ];
    }

    $pathStatus = cdo_env_path_status($pending['envPath'], null);

    if (!$pathStatus['available']) {
        return [
            'valid' => false,
            'state' => $state,
            'pending' => $pending,
            'reason' => $pathStatus['reason'],
            'message' => '安全なenv配置先を利用できません。ホスティング会社の環境変数またはSecrets設定を使ってください。',
        ];
    }

    return [
        'valid' => true,
        'state' => $state,
        'pending' => $pending,
        'reason' => 'ready',
        'message' => 'ready',
    ];
}

function cdo_render_env_upload_invalid_page(string $message, int $statusCode = 403): void
{
    $message = cdo_html_text($message);
    $bodyHtml = <<<HTML
<h1>.env アップロード</h1>
<p>{$message}</p>
<p>AIエージェントとの会話に戻り、必要なら新しいアップロードURLを発行してもらってください。</p>
HTML;

    cdo_render_page('.env アップロード', $bodyHtml, $statusCode);
}

function cdo_render_env_upload_form(string $envPath, ?string $error = null): void
{
    $envPathHtml = cdo_html_text($envPath);
    $maxBytes = number_format(CDO_ENV_UPLOAD_MAX_BYTES);
    $errorHtml = $error === null ? '' : '<p class="status error">' . cdo_html_text($error) . '</p>';
    $action = cdo_html_text(cdo_get_entrypoint_path() . '?' . CDO_ENV_UPLOAD_QUERY_KEY . '=' . rawurlencode((string) $_GET[CDO_ENV_UPLOAD_QUERY_KEY]));

    $bodyHtml = <<<HTML
<h1>.env をアップロード</h1>
<p>この画面では、アプリケーション用のenvファイルを1回だけアップロードできます。</p>
<p>ファイル名は <code>.env</code> でなくてもかまいません。アップロードされた内容は、サーバー上で <code>production.env</code> として保存されます。</p>
<p>保存先: <code>{$envPathHtml}</code></p>
<p>アップロード後、CDOはenvの内容を再表示しません。MCPエージェントも内容、キー名、値、ダウンロードURLを取得できません。</p>
<p>既存のenvファイルはこの内容で置き換えられます。バックアップは作成しません。</p>
<p>最大サイズ: {$maxBytes} bytes。NULL byteを含むファイルは拒否されます。</p>
{$errorHtml}
<form method="post" enctype="multipart/form-data" action="{$action}">
  <p><input type="file" name="envFile" required></p>
  <p><button type="submit">production.env として保存</button></p>
</form>
HTML;

    cdo_render_page('.env をアップロード', $bodyHtml);
}

function cdo_render_env_upload_success_page(string $envPath, int $uploadedAt): void
{
    $envPathHtml = cdo_html_text($envPath);
    $uploadedAtHtml = cdo_html_text(cdo_public_display_time($uploadedAt));
    $bodyHtml = <<<HTML
<h1>.env アップロード完了</h1>
<p>envファイルを <code>production.env</code> として保存しました。</p>
<p>AIエージェントとの会話に戻って「アップロードしました」と伝えてください。</p>
<p>保存先: <code>{$envPathHtml}</code></p>
<p>アップロード日時: <code>{$uploadedAtHtml}</code></p>
<p>CDOはenvの内容を表示・ダウンロード・MCP経由で公開しません。</p>
HTML;

    cdo_render_page('.env アップロード完了', $bodyHtml);
}

function cdo_write_uploaded_env_file(string $envPath, string $content): void
{
    $directory = dirname($envPath);

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create env storage directory.');
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Env storage directory is not writable.');
    }

    $temporaryPath = tempnam($directory, '.cdo-env-');

    if (!is_string($temporaryPath) || $temporaryPath === '') {
        throw new RuntimeException('Failed to create temporary env file.');
    }

    try {
        $bytesWritten = file_put_contents($temporaryPath, $content, LOCK_EX);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new RuntimeException('Failed to write temporary env file.');
        }

        cdo_apply_file_mode($temporaryPath, 0600);

        if (!rename($temporaryPath, $envPath)) {
            throw new RuntimeException('Failed to replace env file atomically.');
        }
    } catch (Throwable $exception) {
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }

        throw $exception;
    }
}

function cdo_handle_env_upload_request(): bool
{
    if (!isset($_GET[CDO_ENV_UPLOAD_QUERY_KEY]) || !is_string($_GET[CDO_ENV_UPLOAD_QUERY_KEY])) {
        return false;
    }

    $secret = (string) $_GET[CDO_ENV_UPLOAD_QUERY_KEY];
    $context = cdo_env_upload_token_context($secret);

    if (!$context['valid']) {
        cdo_render_env_upload_invalid_page((string) $context['message']);
        return true;
    }

    $pending = $context['pending'];
    $envPath = (string) $pending['envPath'];
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        cdo_render_env_upload_form($envPath);
        return true;
    }

    if ($method !== 'POST') {
        cdo_send_empty(405, ['Allow' => 'GET, POST']);
        return true;
    }

    $file = $_FILES['envFile'] ?? null;

    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])) {
        cdo_render_env_upload_form($envPath, 'アップロードするファイルを選択してください。');
        return true;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        cdo_render_env_upload_form($envPath, 'ファイルのアップロードに失敗しました。');
        return true;
    }

    if ((int) $file['size'] > CDO_ENV_UPLOAD_MAX_BYTES) {
        cdo_render_env_upload_form($envPath, 'ファイルサイズが大きすぎます。');
        return true;
    }

    $temporaryUploadPath = (string) $file['tmp_name'];
    $content = @file_get_contents($temporaryUploadPath);

    if (!is_string($content)) {
        cdo_render_env_upload_form($envPath, 'アップロードされたファイルを読み取れませんでした。');
        return true;
    }

    if (strlen($content) > CDO_ENV_UPLOAD_MAX_BYTES) {
        cdo_render_env_upload_form($envPath, 'ファイルサイズが大きすぎます。');
        return true;
    }

    if (strpos($content, "\0") !== false) {
        cdo_render_env_upload_form($envPath, 'NULL byteを含むファイルはアップロードできません。');
        return true;
    }

    $pathStatus = cdo_env_path_status($envPath, null);

    if (!$pathStatus['available']) {
        cdo_render_env_upload_form($envPath, '安全なenv配置先を利用できません。ホスティング会社の環境変数またはSecrets設定を使ってください。');
        return true;
    }

    try {
        cdo_write_uploaded_env_file($envPath, $content);
    } catch (RuntimeException $exception) {
        cdo_render_env_upload_form($envPath, $exception->getMessage());
        return true;
    }

    $state = $context['state'];
    $uploadedAt = cdo_now();
    $state['envPath'] = $envPath;
    $state['uploadedAt'] = $uploadedAt;
    $state['pendingUpload'] = null;
    cdo_save_env_state($state);
    cdo_render_env_upload_success_page($envPath, $uploadedAt);
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

            if ($toolName === 'get_env_path') {
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

                $payload = cdo_env_path_payload();
                cdo_jsonrpc_result($id, cdo_tool_result(
                    $payload['available']
                        ? 'Production env path is available outside the document root.'
                        : 'Production env path is not available. Use hosting provider environment variables or Secrets settings.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'request_env_upload') {
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

                $payload = cdo_request_env_upload_payload();
                cdo_jsonrpc_result($id, cdo_tool_result($payload['message'], $payload), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'get_runtime_info') {
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

                $payload = cdo_runtime_info_payload();
                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Returned safe PHP runtime diagnostics. This is not raw phpinfo() output.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'stat_path') {
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
                    $payload = cdo_stat_path_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    $payload['exists']
                        ? 'Path exists: ' . $payload['path'] . ' (' . $payload['type'] . ').'
                        : 'Path does not exist: ' . $payload['path'] . '.',
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'hash_file') {
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
                    $payload = cdo_hash_file_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'SHA-256 for ' . $payload['path'] . ': ' . $payload['hash'],
                    $payload
                ), 200, [
                    'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                ]);
                return;
            }

            if ($toolName === 'copy_path') {
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
                    $payload = cdo_copy_path_payload($arguments);
                } catch (InvalidArgumentException $exception) {
                    cdo_jsonrpc_error($id, -32602, $exception->getMessage(), 200, null, [
                        'MCP-Protocol-Version' => CDO_PROTOCOL_VERSION,
                    ]);
                    return;
                }

                cdo_jsonrpc_result($id, cdo_tool_result(
                    'Copied ' . $payload['from'] . ' to ' . $payload['to'] . '.',
                    $payload
                ), 200, [
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

    if (cdo_handle_env_upload_request()) {
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
