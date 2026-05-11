# Chief-Deployment-Officer

[日本語版 README](README.ja.md)

Chief-Deployment-Officer is a development foundation for a PHP single-file deployment tool.

This README is for people who develop or distribute `cdo.php`, and for AI agents that need to understand the implementation. User-facing approval guidance is kept in the deployed `cdo.php` browser page and approval screens.

The current implementation includes single-agent approval-based authentication and a minimal set of file operation tools.

- Production entrypoint: `public_html/cdo.php`
- Development helpers: `composer serve`, `composer lint`, `composer test`, `composer qa`
- MCP connectivity check: `npm run mcp:inspect`
- Implemented MCP methods: `ping`, `initialize`, `tools/list`, `tools/call`
- Public tools: `server_status`, `request_auth`
- Protected tools: `list_dir`, `read_file`, `write_file`, `create_dir`, `delete_file`, `delete_dir`, `rename_path`, `get_env_path`, `request_env_upload`, `get_runtime_info`

## Requirements

- PHP 7.4+ is the production target.
- Local verification currently uses PHP 8.5.
- Node.js 22.7.5+ is useful for MCP Inspector.

## Setup

```powershell
composer install
npm install
```

## Common Commands

```powershell
composer serve
composer qa
npm run mcp:inspect
```

`composer serve` is intended to stay running for development, so Composer's process timeout is disabled for that command.

After `composer serve`, open `http://127.0.0.1:8787/` for browser checks.

The MCP endpoint can be either of the following.

- Recommended: `http://127.0.0.1:8787/mcp`
- Direct file path: `http://127.0.0.1:8787/cdo.php`

When a browser or AI agent accesses `cdo.php`, it shows a guidance page with the MCP endpoint, authentication state, approved-agent metadata, and dangerous-operation notes. The page shows the user section first and the AI-agent section second.

When giving a remote `cdo.php` URL to an AI agent, tell it to use that exact URL as the MCP endpoint. The agent should not guess a local repository path or alternate URL. It should first read the deployed page's AI section or call `server_status` and inspect `agentGuide`, then call `tools/list`, then `server_status`, then `request_auth` if needed.

`/sse` is routed to `cdo.php` by the development router, but SSE transport itself is not implemented. In MCP Inspector, choose Streamable HTTP and use either `/mcp` or `/cdo.php`.

Authentication is not OAuth/OIDC. Discovery endpoints such as `/.well-known/oauth-protected-resource` are not implemented. Do not use MCP Inspector's OAuth flow; manually configure the token returned by `request_auth` as a header.

## File Renaming And Authentication Notes

`public_html/cdo.php` is expected to work after being renamed to any `.php` filename. In production, rename the `cdo` portion to a hard-to-guess name and use the renamed filename in the MCP endpoint URL.

This authentication flow is the minimum defense for giving a bearer token to one user-approved agent. Renaming the file only reduces mechanical discovery and must not be treated as a standalone security boundary.

## Pre-Production Checklist

- Use HTTPS. On HTTP, bearer tokens and approval URLs can be intercepted.
- Rename `cdo.php` to a hard-to-guess PHP filename, and use the renamed filename in the MCP endpoint URL.
- Consider additional server-side access controls such as IP restrictions, Basic authentication, or placing the file under a management-only path.
- Back up the target directory before using `write_file`, `delete_file`, `delete_dir`, or `rename_path`.
- To reset an approval, delete the related authentication file in the deployment directory. Example: `cdo.php` uses `.cdo_auth.json`; `agent-a.php` uses `.agent-a_auth.json`.
- Do not include `.*_auth.json`, `.*_env.json`, or `.*_debug.log` in public distributions or shared files.
- Do not leave unused unauthenticated CDO files on a public server. Delete unused copies.

## Multiple Agents

Chief-Deployment-Officer uses a `1 file = 1 agent authorization` operating model. Related files are tied to the entrypoint filename, so separate copies such as `agent-a.php` and `agent-b.php` in the same directory have separate authentication states.

Example: `cdo.php` uses `.cdo_auth.json`, `.cdo_env.json`, and `.cdo_debug.log`; `agent-a.php` uses `.agent-a_auth.json`, `.agent-a_env.json`, and `.agent-a_debug.log`. The env storage hash also includes the filename, so env storage is separated as well. Copies in subdirectories also display their own path and MCP endpoint. CDO does not add multi-agent management inside a single file.

## Authentication Flow

1. Call `request_auth` while unauthenticated.
2. Open the returned `approvalUrl` in a browser, then the user presses `Approve`.
3. Send the returned `bearerToken` with `X-CDO-Bearer-Token: ...` to use protected tools.

`request_auth` accepts optional `agentName` and `contextHint`. `contextHint` is a short clue to help the user find the conversation that requested approval later. Example: `Codex desktop / Chief-Deployment-Officer release thread / 2026-04-28`. Do not include secrets or tokens.

The approval screen and approval-complete screen are for users. The AI agent should give the `approvalUrl` from `request_auth` to the user and say: "Open this URL and tell me when approval is complete." The `bearerToken` from the same response should be kept by the AI agent; do not ask the user to paste it back. After approval, send `X-CDO-Bearer-Token: <bearerToken>` and call `server_status` again.

In MCP Inspector, add `X-CDO-Bearer-Token: <bearerToken>` to the `Authentication` panel's custom headers. `Authorization: Bearer ...` is also accepted, but the dedicated header is easier to debug in Inspector.

By default, authentication state is stored in the related auth file next to the entrypoint. `cdo.php` uses `.cdo_auth.json`; `agent-a.php` uses `.agent-a_auth.json`. Deleting that file allows a new agent to be approved.
Authentication debug logs are also tied to the filename. `cdo.php` uses `.cdo_debug.log`; `agent-a.php` uses `.agent-a_debug.log`, written as JSON Lines.

## Debugging

`server_status` returns both connectivity metadata and the current authentication decision. These fields are especially useful.

- `endpoint`: MCP endpoint URL constructed from the current request.
- `agentGuide`: AI-agent guidance for connection, authentication, env placement, dangerous operations, and timeout handling.
- `authorizationHeaderPresent`: whether the Authorization header reached the server.
- `inspectorBearerHeaderPresent`: whether the `X-CDO-Bearer-Token` header reached the server.
- `authorized`: whether the current request is accepted as authorized.
- `authReason`: reason such as `missing_state`, `missing_token`, `invalid_token`, or `authorized`.
- `bearerHeaderSource`: the header name actually used.
- `agentName`, `contextHint`, `approvedAt`, `lastUsedAt`: safe metadata for checking which AI agent was approved and when.

If protected tools are not visible in Inspector, first call `server_status` and inspect those fields. Then open the related debug log in the deployment directory to see `tools/list` and `auth_context` decision history.

For development and tests, override state file paths with `CDO_AUTH_STATE_PATH`, `CDO_ENV_STATE_PATH`, and `CDO_DEBUG_LOG_PATH`.

## get_runtime_info

`get_runtime_info` is a safe runtime diagnostic tool for approved agents. It does not return raw `phpinfo()` HTML, full `$_ENV`, full `$_SERVER`, headers, cookies, or environment variable values.

It returns PHP version/SAPI/OS, loaded `php.ini` and scanned ini files, likely `.user.ini` and `.htaccess` PHP directive support, all PHP directives from formatted `ini_get_all(null, true)`, loaded extensions, and major extension capability flags.

Each directive includes `globalValue`, `effectiveValue`, `overridden`, `accessRaw`, `accessLabels`, and `settableVia`. `.user.ini` and `.htaccess` support are estimates based on PHP SAPI and related ini values; CDO does not claim to know hosting-provider settings such as `AllowOverride`.

## Dangerous Operation Rules

- Only approved agents should use `write_file`, `delete_file`, `delete_dir`, and `rename_path`.
- Before delete or rename, verify the target path and contents with `list_dir` / `read_file`.
- Add `confirm: true` only after the user has confirmed the target and operation.
- If a write/delete/rename tool call times out, the AI agent must not immediately retry the same operation. First call `server_status`, then use `list_dir` / `read_file` to confirm whether the change was applied.
- If the change is already applied, treat it as success. If not, ask the user before retrying.
- `delete_dir` only supports empty directories. Recursive delete is not implemented.
- `rename_path` rejects existing destinations. Rename overwrite/replace is not implemented.
- `request_env_upload` only issues a browser URL for a human user. Do not ask the AI agent to paste env contents, and do not upload env contents through MCP.

## list_dir

- The root is the directory containing `cdo.php`.
- The default `path` is `.`.
- It returns direct children only; it is not recursive.
- `.cdo_*` is treated as internal control data and excluded from listings.
- Regular dotfiles are not excluded.

## read_file

- The root is the directory containing `cdo.php`.
- `path` is required. Absolute paths and `..` are rejected.
- Internal control files beginning with `.cdo_*` are not readable.
- `maxBytes` controls the read limit. The maximum is `1048576` bytes.
- UTF-8 text is returned directly; binary content is returned as base64.

## write_file

- The root is the directory containing `cdo.php`.
- `path`, `content`, and `encoding` are required. `encoding` must be `utf-8` or `base64`.
- Existing files are rejected unless `overwrite: true` is provided.
- When `overwrite: true` overwrites an existing file, the existing file permissions are preserved.
- New file permissions are not fixed; they follow normal creation semantics, equivalent to `0666 & ~umask()`.
- Parent directories are not created implicitly. Call `create_dir` first when needed.
- Absolute paths, `..`, `.cdo_*` internal control files, and the current entrypoint file itself are rejected.

## create_dir

- The root is the directory containing `cdo.php`.
- `path` is required. Absolute paths and `..` are rejected.
- Parent directories are created only when `recursive: true` is provided.
- If the path already exists as a directory, the operation succeeds. If it exists as a file, the operation is rejected.
- `.cdo_*` internal control names and the current entrypoint file itself are rejected.

## delete_file

- The root is the directory containing `cdo.php`.
- `path` and `confirm: true` are required.
- Absolute paths, `..`, `.cdo_*` internal control files, and the current entrypoint file itself are rejected.
- Directories cannot be deleted with this tool. Use `delete_dir` for directories.

## delete_dir

- The root is the directory containing `cdo.php`.
- `path` and `confirm: true` are required.
- Only empty directories can be deleted. Non-empty directories and files are rejected.
- Recursive delete is not implemented.
- Absolute paths, `..`, `.cdo_*` internal control files, and the current entrypoint file itself are rejected.

## rename_path

- The root is the directory containing `cdo.php`.
- `from`, `to`, and `confirm: true` are required.
- Files and directories can be renamed or moved.
- Existing destinations are always rejected; overwrite/replace is not implemented.
- Destination parent directories are not created implicitly.
- Both source and destination reject absolute paths, `..`, `.cdo_*` internal control files, and the current entrypoint file itself.

## production.env / Secrets Placement MVP

CDO does not permanently set OS environment variables. On shared hosting, `putenv()` during a PHP request cannot change the parent process or future requests.

Instead, CDO places one production env file outside the public web area. If a `public_html` ancestor exists above `DOCUMENT_ROOT`, CDO places `.cdo-secrets/{cdo_app_root_hash}/production.env` one level above that `public_html`. If no `public_html` ancestor is found, CDO uses one level above `DOCUMENT_ROOT`. `cdo_app_root_hash` is derived from the placement directory and entrypoint filename, so renamed CDO copies in the same directory get separate storage. CDO returns a single chosen `envPath`, not a candidate list.

- `get_env_path`: returns only `envPath`, `available`, `uploaded`, `uploadedAt`, `outsideDocumentRoot`, `readableByPhp`, `writable`, and `reason`.
- `request_env_upload`: issues a one-time browser upload URL valid for 10 minutes. Existing unused URLs are replaced.

These tools do not return env contents, key names, values, or download URLs. The AI agent receives only `envPath` and should update the target application code to read that env file. The env contents are provided by the user through the browser one-time upload screen.

Normal flow: call `get_env_path` to check placement and upload state; if not uploaded, call `request_env_upload` to issue the user-facing URL; after upload, call `get_env_path` again and check `uploaded` and `uploadedAt`.

The upload screen accepts any filename, but the saved filename is always `production.env`. Maximum size is `256KB`; files containing NULL bytes are rejected. Saving uses a temporary file and replacement, and sets file permissions to `0600` when possible. No backup is created.

If CDO cannot secure a safe path outside the document root or outside `public_html`, env placement is rejected. This also covers shared hosts where a custom-domain document root is nested under a default public directory and could be visible from the default domain. CDO does not generate `.htaccess`, `.user.ini`, `php.ini`, or FPM pool settings. In that case, use the hosting provider's environment variable settings, Secrets settings, or control panel.

## Directory Layout

- `public_html/`: single-file production-oriented entrypoint.
- `dev/qa/`: helper scripts such as lint checks.
- `dev/tests/`: dependency-free smoke tests.

## Current Implementation Scope

- Runtime dependencies are still not added, to preserve single-file operation.
- MCP Inspector is included only as an external development tool.
- `cdo.php` is expected to keep working without depending on the fixed filename.
- Delete supports files and empty directories only. Recursive delete is not implemented.
- Rename overwrite/replace is not implemented.
