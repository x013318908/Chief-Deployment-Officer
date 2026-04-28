$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
Set-Location $repoRoot

function Assert-True {
    param(
        [bool] $Condition,
        [string] $Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

function Get-FreePort {
    for ($port = 18080; $port -lt 18120; $port++) {
        try {
            $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Parse('127.0.0.1'), $port)
            $listener.Start()
            $listener.Stop()
            return $port
        } catch {
        }
    }

    throw 'Could not find an available port.'
}

function Invoke-SmokeRequest {
    param(
        [string] $Url,
        [string] $Method,
        [hashtable] $Headers = @{},
        [string] $Body = ''
    )

    $params = @{
        Uri = $Url
        Method = $Method
        UseBasicParsing = $true
        TimeoutSec = 5
        ErrorAction = 'Stop'
    }

    if ($Headers.Count -gt 0) {
        $params['Headers'] = @{}

        foreach ($key in $Headers.Keys) {
            if ($key -ne 'Content-Type') {
                $params['Headers'][$key] = $Headers[$key]
            }
        }
    }

    if ($Method -ne 'GET' -and $Body -ne '') {
        $params['Body'] = $Body
    }

    if ($Headers.ContainsKey('Content-Type')) {
        $params['ContentType'] = $Headers['Content-Type']
    }

    try {
        $response = Invoke-WebRequest @params
        return [pscustomobject]@{
            StatusCode = [int] $response.StatusCode
            Content = [string] $response.Content
            Headers = $response.Headers
        }
    } catch {
        $httpResponse = $_.Exception.Response

        if (-not $httpResponse) {
            throw
        }

        $statusCode = [int] $httpResponse.StatusCode
        $content = ''
        $stream = $httpResponse.GetResponseStream()

        if ($stream) {
            $reader = New-Object System.IO.StreamReader($stream)
            try {
                $content = $reader.ReadToEnd()
            } finally {
                $reader.Dispose()
            }
        }

        $headers = @{}

        foreach ($headerName in $httpResponse.Headers.AllKeys) {
            $headers[$headerName] = $httpResponse.Headers[$headerName]
        }

        $httpResponse.Close()

        return [pscustomobject]@{
            StatusCode = $statusCode
            Content = $content
            Headers = $headers
        }
    }
}

function Invoke-JsonRpcTool {
    param(
        [string] $Url,
        [int] $Id,
        [string] $ToolName,
        [hashtable] $Arguments = @{},
        [string] $BearerToken = '',
        [string] $BearerHeaderName = 'Authorization'
    )

    $headers = @{
        Accept = 'application/json, text/event-stream'
        'Content-Type' = 'application/json'
        'MCP-Protocol-Version' = '2025-11-25'
    }

    if ($BearerToken -ne '') {
        if ($BearerHeaderName -eq 'Authorization') {
            $headers[$BearerHeaderName] = 'Bearer ' + $BearerToken
        } else {
            $headers[$BearerHeaderName] = $BearerToken
        }
    }

    $body = @{
        jsonrpc = '2.0'
        id = $Id
        method = 'tools/call'
        params = @{
            name = $ToolName
            arguments = $Arguments
        }
    } | ConvertTo-Json -Depth 8 -Compress

    return Invoke-SmokeRequest -Url $Url -Method 'POST' -Headers $headers -Body $body
}

function Get-ToolNames {
    param([string] $Content)

    $json = $Content | ConvertFrom-Json
    $names = @()

    foreach ($tool in $json.result.tools) {
        $names += [string] $tool.name
    }

    return $names
}

function Remove-IfExists {
    param([string] $Path)

    if (Test-Path $Path) {
        Remove-Item $Path -Force -Recurse
    }
}

$entrypoint = Join-Path $repoRoot 'public_html\cdo.php'
$router = Join-Path $repoRoot 'dev\server\router.php'
$tmpDir = Join-Path $repoRoot 'dev\tests\tmp'
$authFile = Join-Path $tmpDir 'smoke-auth.json'
$debugLog = Join-Path $tmpDir 'smoke-debug.log'
$renamedEntrypoint = Join-Path $repoRoot 'public_html\chief-smoke.php'
$subdirEntrypointDir = Join-Path $repoRoot 'public_html\agent-smoke'
$subdirEntrypoint = Join-Path $subdirEntrypointDir 'cdo.php'
$visibleDotFile = Join-Path $repoRoot 'public_html\.smoke-visible.txt'
$internalControlFile = Join-Path $repoRoot 'public_html\.cdo_secret.txt'
$fixtureDir = Join-Path $repoRoot 'public_html\smoke-fixture'
$fixtureFile = Join-Path $fixtureDir 'alpha.txt'
$fixtureDotFile = Join-Path $fixtureDir '.beta.txt'
$nestedDir = Join-Path $fixtureDir 'nested'
$nestedFile = Join-Path $nestedDir 'inside.txt'
$writeDir = Join-Path $repoRoot 'public_html\smoke-write'
$recursiveWriteDir = Join-Path $repoRoot 'public_html\smoke-recursive'
$deleteDir = Join-Path $repoRoot 'public_html\smoke-delete'
$deleteFile = Join-Path $deleteDir 'delete-me.txt'
$deleteEmptyDir = Join-Path $deleteDir 'empty-dir'
$deleteNonEmptyDir = Join-Path $deleteDir 'non-empty-dir'
$deleteNonEmptyFile = Join-Path $deleteNonEmptyDir 'inside.txt'
$renameDir = Join-Path $repoRoot 'public_html\smoke-rename'
$renameFileSource = Join-Path $renameDir 'file-source.txt'
$renameFileDestination = Join-Path $renameDir 'file-destination.txt'
$renameDirSource = Join-Path $renameDir 'dir-source'
$renameDirFile = Join-Path $renameDirSource 'inside.txt'
$renameDirDestination = Join-Path $renameDir 'dir-destination'
$renameExistingDestination = Join-Path $renameDir 'existing.txt'

Assert-True (Test-Path $entrypoint) "Missing entrypoint: $entrypoint"
Assert-True (Test-Path $router) "Missing router: $router"

New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null
Remove-IfExists $authFile
Remove-IfExists $debugLog
Remove-IfExists $renamedEntrypoint
Remove-IfExists $subdirEntrypointDir
Remove-IfExists $visibleDotFile
Remove-IfExists $internalControlFile
Remove-IfExists $fixtureDir
Remove-IfExists $writeDir
Remove-IfExists $recursiveWriteDir
Remove-IfExists $deleteDir
Remove-IfExists $renameDir

Copy-Item -Path $entrypoint -Destination $renamedEntrypoint -Force
New-Item -ItemType Directory -Path $subdirEntrypointDir | Out-Null
Copy-Item -Path $entrypoint -Destination $subdirEntrypoint -Force
Set-Content -Path $visibleDotFile -Value 'visible-root-dot'
Set-Content -Path $internalControlFile -Value 'internal-secret'
New-Item -ItemType Directory -Path $fixtureDir | Out-Null
New-Item -ItemType Directory -Path $nestedDir | Out-Null
Set-Content -Path $fixtureFile -Value 'alpha'
Set-Content -Path $fixtureDotFile -Value 'beta'
Set-Content -Path $nestedFile -Value 'inside'
New-Item -ItemType Directory -Path $deleteDir | Out-Null
New-Item -ItemType Directory -Path $deleteEmptyDir | Out-Null
New-Item -ItemType Directory -Path $deleteNonEmptyDir | Out-Null
Set-Content -Path $deleteFile -Value 'delete-me'
Set-Content -Path $deleteNonEmptyFile -Value 'non-empty'
New-Item -ItemType Directory -Path $renameDir | Out-Null
New-Item -ItemType Directory -Path $renameDirSource | Out-Null
Set-Content -Path $renameFileSource -Value 'rename-file'
Set-Content -Path $renameDirFile -Value 'rename-dir-file'
Set-Content -Path $renameExistingDestination -Value 'existing'

$previousAuthPath = $env:CDO_AUTH_STATE_PATH
$previousDebugPath = $env:CDO_DEBUG_LOG_PATH
$env:CDO_AUTH_STATE_PATH = $authFile
$env:CDO_DEBUG_LOG_PATH = $debugLog

$port = Get-FreePort
$serverProcess = Start-Process -FilePath 'php' `
    -ArgumentList '-S', ("127.0.0.1:" + $port), '-t', 'public_html', 'dev/server/router.php' `
    -WorkingDirectory $repoRoot `
    -PassThru

$rootUrl = "http://127.0.0.1:$port/"
$mcpUrl = "http://127.0.0.1:$port/mcp"
$fileUrl = "http://127.0.0.1:$port/cdo.php"
$renamedFileUrl = "http://127.0.0.1:$port/chief-smoke.php"
$subdirFileUrl = "http://127.0.0.1:$port/agent-smoke/cdo.php"

try {
    Start-Sleep -Seconds 3

    $probe = Invoke-SmokeRequest -Url $rootUrl -Method 'GET'
    Assert-True ($probe.StatusCode -eq 200) "PHP built-in server did not become ready. Status=$($probe.StatusCode)"

    $html = Invoke-SmokeRequest -Url $rootUrl -Method 'GET'
    Assert-True ($html.StatusCode -eq 200) 'GET / should return 200.'
    Assert-True ($html.Content.Contains('Chief-Deployment-Officer')) 'HTML response should contain app name.'
    Assert-True ($html.Content.Contains('request_auth')) 'HTML response should mention request_auth.'
    Assert-True ($html.Content.Contains('list_dir')) 'HTML response should mention list_dir.'
    Assert-True ($html.Content.Contains('delete_file')) 'HTML response should mention delete_file.'
    Assert-True ($html.Content.Contains('AI agent instructions')) 'HTML response should include AI-agent instructions.'
    $userGuidanceIndex = $html.Content.IndexOf('User guidance')
    $aiGuidanceIndex = $html.Content.IndexOf('AI agent guidance')
    Assert-True ($userGuidanceIndex -ge 0) 'HTML response should include a user-facing section.'
    Assert-True ($aiGuidanceIndex -gt $userGuidanceIndex) 'HTML response should place AI-agent guidance after the user-facing section.'
    Assert-True ($html.Content.Contains('MCP endpoint')) 'HTML response should include the MCP endpoint.'
    Assert-True ($html.Content.Contains('tools/list')) 'HTML response should tell agents to call tools/list first.'
    Assert-True ($html.Content.Contains('server_status')) 'HTML response should tell agents to call server_status.'
    Assert-True ($html.Content.Contains('contextHint')) 'HTML response should explain contextHint usage.'
    Assert-True ($html.Content.Contains('this exact URL')) 'HTML response should tell agents to keep the provided endpoint URL.'
    Assert-True ($html.Content.Contains('Do not inspect a local repository first')) 'HTML response should prevent unnecessary local repo inspection.'
    Assert-True ($html.Content.Contains('approvalUrl')) 'HTML response should tell agents to pass the approval URL to the user.'
    Assert-True ($html.Content.Contains('Do not ask the user to paste it back')) 'HTML response should tell agents not to ask users to paste tokens.'
    Assert-True ($html.Content.Contains('X-CDO-Bearer-Token')) 'HTML response should mention the preferred bearer header.'
    Assert-True ($html.Content.Contains('not_configured')) 'HTML response should report missing auth state before auth.'
    Assert-True (-not $html.Content.Contains('tokenHashPrefix')) 'HTML response should not expose token hash prefixes.'
    Assert-True (-not $html.Content.Contains('storedTokenHashPrefix')) 'HTML response should not expose stored token hash prefixes.'
    Assert-True (-not $html.Content.Contains('approvalSecret')) 'HTML response should not expose approval secrets.'
    Assert-True (-not $html.Content.Contains('pendingBearerToken')) 'HTML response should not expose pending bearer tokens.'
    Assert-True (-not $html.Content.Contains('bearerTokenHash')) 'HTML response should not expose bearer token hashes.'

    $directFile = Invoke-SmokeRequest -Url $fileUrl -Method 'GET'
    Assert-True ($directFile.StatusCode -eq 200) 'GET /cdo.php should return 200.'
    Assert-True ($directFile.Content.Contains('Chief-Deployment-Officer')) 'Direct entrypoint response should contain app name.'

    $renamedHtml = Invoke-SmokeRequest -Url $renamedFileUrl -Method 'GET'
    Assert-True ($renamedHtml.StatusCode -eq 200) 'Renamed entrypoint should return 200.'
    Assert-True ($renamedHtml.Content.Contains('chief-smoke.php')) 'Renamed entrypoint page should report its current filename.'

    $renamedRequestAuth = Invoke-JsonRpcTool -Url $renamedFileUrl -Id 16 -ToolName 'request_auth' -Arguments @{
        agentName = 'renamed-smoke-agent'
    }
    Assert-True ($renamedRequestAuth.StatusCode -eq 200) 'Renamed entrypoint request_auth should return 200.'
    $renamedRequestAuthJson = $renamedRequestAuth.Content | ConvertFrom-Json
    $renamedApprovalUrl = [string] $renamedRequestAuthJson.result.structuredContent.approvalUrl
    Assert-True ($renamedApprovalUrl.Contains('/chief-smoke.php?cdo_approve=')) 'Renamed entrypoint should build approval URLs with the current filename.'
    Remove-IfExists $authFile
    Remove-IfExists $debugLog

    $subdirHtml = Invoke-SmokeRequest -Url $subdirFileUrl -Method 'GET'
    Assert-True ($subdirHtml.StatusCode -eq 200) 'Subdirectory entrypoint should return 200.'
    Assert-True ($subdirHtml.Content.Contains('/agent-smoke/cdo.php')) 'Subdirectory entrypoint page should report its full path.'
    Assert-True ($subdirHtml.Content.Contains($subdirFileUrl)) 'Subdirectory entrypoint page should report its full endpoint URL.'

    $subdirRequestAuth = Invoke-JsonRpcTool -Url $subdirFileUrl -Id 17 -ToolName 'request_auth' -Arguments @{
        agentName = 'subdir-smoke-agent'
    }
    Assert-True ($subdirRequestAuth.StatusCode -eq 200) 'Subdirectory entrypoint request_auth should return 200.'
    $subdirRequestAuthJson = $subdirRequestAuth.Content | ConvertFrom-Json
    $subdirApprovalUrl = [string] $subdirRequestAuthJson.result.structuredContent.approvalUrl
    Assert-True ($subdirApprovalUrl.Contains('/agent-smoke/cdo.php?cdo_approve=')) 'Subdirectory entrypoint should build approval URLs with the subdirectory path.'
    Remove-IfExists $authFile
    Remove-IfExists $debugLog

    $favicon = Invoke-SmokeRequest -Url ("http://127.0.0.1:$port/favicon.ico") -Method 'GET'
    Assert-True ($favicon.StatusCode -eq 204) 'GET /favicon.ico should return 204.'

    $oauthMetadata = Invoke-SmokeRequest -Url ("http://127.0.0.1:$port/.well-known/oauth-protected-resource/mcp") -Method 'GET'
    Assert-True ($oauthMetadata.StatusCode -eq 404) 'OAuth protected resource metadata should return 404 until OAuth is implemented.'
    $oauthMetadataJson = $oauthMetadata.Content | ConvertFrom-Json
    Assert-True ($oauthMetadataJson.error -eq 'oauth_not_implemented') 'OAuth protected resource metadata should return a JSON error body.'

    $eventStreamGet = Invoke-SmokeRequest -Url $mcpUrl -Method 'GET' -Headers @{
        Accept = 'text/event-stream'
    }
    Assert-True ($eventStreamGet.StatusCode -eq 405) 'GET /mcp with text/event-stream should return 405.'

    $legacySseGet = Invoke-SmokeRequest -Url ("http://127.0.0.1:$port/sse") -Method 'GET' -Headers @{
        Accept = 'text/event-stream'
    }
    Assert-True ($legacySseGet.StatusCode -eq 405) 'GET /sse should resolve to the entrypoint and return 405.'

    $initializeBody = @{
        jsonrpc = '2.0'
        id = 1
        method = 'initialize'
        params = @{
            protocolVersion = '2025-11-25'
            capabilities = @{}
            clientInfo = @{
                name = 'smoke-test'
                version = '1.0.0'
            }
        }
    } | ConvertTo-Json -Depth 8 -Compress

    $initialize = Invoke-SmokeRequest -Url $mcpUrl -Method 'POST' -Headers @{
        Accept = 'application/json, text/event-stream'
        'Content-Type' = 'application/json'
    } -Body $initializeBody
    Assert-True ($initialize.StatusCode -eq 200) 'initialize should return 200.'

    $initializeJson = $initialize.Content | ConvertFrom-Json
    Assert-True ($initializeJson.result.protocolVersion -eq '2025-11-25') 'initialize should negotiate the expected protocol version.'
    Assert-True ($initializeJson.result.serverInfo.name -eq 'Chief-Deployment-Officer') 'initialize should expose the server name.'

    $initializedBody = @{
        jsonrpc = '2.0'
        method = 'notifications/initialized'
    } | ConvertTo-Json -Depth 4 -Compress

    $initialized = Invoke-SmokeRequest -Url $mcpUrl -Method 'POST' -Headers @{
        Accept = 'application/json, text/event-stream'
        'Content-Type' = 'application/json'
        'MCP-Protocol-Version' = '2025-11-25'
    } -Body $initializedBody
    Assert-True ($initialized.StatusCode -eq 202) 'initialized notification should return 202.'

    $pingBody = @{
        jsonrpc = '2.0'
        id = 2
        method = 'ping'
    } | ConvertTo-Json -Depth 4 -Compress

    $ping = Invoke-SmokeRequest -Url $mcpUrl -Method 'POST' -Headers @{
        Accept = 'application/json, text/event-stream'
        'Content-Type' = 'application/json'
        'MCP-Protocol-Version' = '2025-11-25'
    } -Body $pingBody
    Assert-True ($ping.StatusCode -eq 200) 'ping should return 200.'

    $toolsListBody = @{
        jsonrpc = '2.0'
        id = 3
        method = 'tools/list'
    } | ConvertTo-Json -Depth 4 -Compress

    $toolsList = Invoke-SmokeRequest -Url $mcpUrl -Method 'POST' -Headers @{
        Accept = 'application/json, text/event-stream'
        'Content-Type' = 'application/json'
        'MCP-Protocol-Version' = '2025-11-25'
    } -Body $toolsListBody
    Assert-True ($toolsList.StatusCode -eq 200) 'tools/list should return 200 before auth.'

    $toolNames = Get-ToolNames -Content $toolsList.Content
    Assert-True ($toolNames -contains 'server_status') 'tools/list should expose server_status before auth.'
    Assert-True ($toolNames -contains 'request_auth') 'tools/list should expose request_auth before auth.'
    Assert-True (-not ($toolNames -contains 'list_dir')) 'tools/list should not expose list_dir before auth.'
    Assert-True (-not ($toolNames -contains 'read_file')) 'tools/list should not expose read_file before auth.'
    Assert-True (-not ($toolNames -contains 'write_file')) 'tools/list should not expose write_file before auth.'
    Assert-True (-not ($toolNames -contains 'create_dir')) 'tools/list should not expose create_dir before auth.'
    Assert-True (-not ($toolNames -contains 'delete_file')) 'tools/list should not expose delete_file before auth.'
    Assert-True (-not ($toolNames -contains 'delete_dir')) 'tools/list should not expose delete_dir before auth.'
    Assert-True (-not ($toolNames -contains 'rename_path')) 'tools/list should not expose rename_path before auth.'

    $unauthorizedListDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 4 -ToolName 'list_dir'
    Assert-True ($unauthorizedListDir.StatusCode -eq 200) 'Unauthorized list_dir should still return JSON-RPC.'
    $unauthorizedListDirJson = $unauthorizedListDir.Content | ConvertFrom-Json
    Assert-True ($unauthorizedListDirJson.error.code -eq -32001) 'Unauthorized list_dir should return an auth error.'

    $unauthorizedReadFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 44 -ToolName 'read_file' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
    }
    Assert-True ($unauthorizedReadFile.StatusCode -eq 200) 'Unauthorized read_file should still return JSON-RPC.'
    $unauthorizedReadFileJson = $unauthorizedReadFile.Content | ConvertFrom-Json
    Assert-True ($unauthorizedReadFileJson.error.code -eq -32001) 'Unauthorized read_file should return an auth error.'

    $unauthorizedWriteFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 49 -ToolName 'write_file' -Arguments @{
        path = 'smoke-write/new.txt'
        content = 'blocked'
        encoding = 'utf-8'
    }
    Assert-True ($unauthorizedWriteFile.StatusCode -eq 200) 'Unauthorized write_file should still return JSON-RPC.'
    $unauthorizedWriteFileJson = $unauthorizedWriteFile.Content | ConvertFrom-Json
    Assert-True ($unauthorizedWriteFileJson.error.code -eq -32001) 'Unauthorized write_file should return an auth error.'

    $unauthorizedCreateDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 50 -ToolName 'create_dir' -Arguments @{
        path = 'smoke-write'
    }
    Assert-True ($unauthorizedCreateDir.StatusCode -eq 200) 'Unauthorized create_dir should still return JSON-RPC.'
    $unauthorizedCreateDirJson = $unauthorizedCreateDir.Content | ConvertFrom-Json
    Assert-True ($unauthorizedCreateDirJson.error.code -eq -32001) 'Unauthorized create_dir should return an auth error.'

    $unauthorizedDeleteFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 69 -ToolName 'delete_file' -Arguments @{
        path = 'smoke-delete/delete-me.txt'
        confirm = $true
    }
    Assert-True ($unauthorizedDeleteFile.StatusCode -eq 200) 'Unauthorized delete_file should still return JSON-RPC.'
    $unauthorizedDeleteFileJson = $unauthorizedDeleteFile.Content | ConvertFrom-Json
    Assert-True ($unauthorizedDeleteFileJson.error.code -eq -32001) 'Unauthorized delete_file should return an auth error.'

    $unauthorizedDeleteDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 70 -ToolName 'delete_dir' -Arguments @{
        path = 'smoke-delete/empty-dir'
        confirm = $true
    }
    Assert-True ($unauthorizedDeleteDir.StatusCode -eq 200) 'Unauthorized delete_dir should still return JSON-RPC.'
    $unauthorizedDeleteDirJson = $unauthorizedDeleteDir.Content | ConvertFrom-Json
    Assert-True ($unauthorizedDeleteDirJson.error.code -eq -32001) 'Unauthorized delete_dir should return an auth error.'

    $unauthorizedRenamePath = Invoke-JsonRpcTool -Url $mcpUrl -Id 71 -ToolName 'rename_path' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = 'smoke-rename/blocked.txt'
        confirm = $true
    }
    Assert-True ($unauthorizedRenamePath.StatusCode -eq 200) 'Unauthorized rename_path should still return JSON-RPC.'
    $unauthorizedRenamePathJson = $unauthorizedRenamePath.Content | ConvertFrom-Json
    Assert-True ($unauthorizedRenamePathJson.error.code -eq -32001) 'Unauthorized rename_path should return an auth error.'

    $serverStatusBeforeAuth = Invoke-JsonRpcTool -Url $mcpUrl -Id 40 -ToolName 'server_status'
    $serverStatusBeforeAuthJson = $serverStatusBeforeAuth.Content | ConvertFrom-Json
    $serverStatusBeforeAuthData = $serverStatusBeforeAuthJson.result.structuredContent
    Assert-True (-not $serverStatusBeforeAuthData.authorized) 'server_status should report unauthorized before auth.'
    Assert-True (-not $serverStatusBeforeAuthData.authorizationHeaderPresent) 'server_status should report missing Authorization before auth.'
    Assert-True (-not $serverStatusBeforeAuthData.authConfigured) 'server_status should report auth not configured before request_auth.'
    Assert-True ($serverStatusBeforeAuthData.authState -eq 'not_configured') 'server_status should report not_configured before request_auth.'
    Assert-True ($serverStatusBeforeAuthData.authReason -eq 'missing_state') 'server_status should explain that auth state is missing before request_auth.'

    $contextHint = 'Codex desktop / smoke auth thread / 2026-04-28'
    $requestAuth = Invoke-JsonRpcTool -Url $mcpUrl -Id 5 -ToolName 'request_auth' -Arguments @{
        agentName = 'smoke-agent'
        contextHint = $contextHint
    }
    Assert-True ($requestAuth.StatusCode -eq 200) 'request_auth should return 200.'
    $requestAuthJson = $requestAuth.Content | ConvertFrom-Json
    $requestAuthData = $requestAuthJson.result.structuredContent
    Assert-True ($requestAuthData.status -eq 'pending_approval') 'First request_auth should create a pending approval.'
    Assert-True (-not [string]::IsNullOrWhiteSpace($requestAuthData.approvalUrl)) 'request_auth should return an approval URL.'
    Assert-True (-not [string]::IsNullOrWhiteSpace($requestAuthData.bearerToken)) 'request_auth should return a bearer token.'
    Assert-True ($requestAuthData.preferredHeaderName -eq 'X-CDO-Bearer-Token') 'request_auth should expose the preferred Inspector header name.'
    Assert-True ($requestAuthData.agentName -eq 'smoke-agent') 'request_auth should echo the safe agent name metadata.'
    Assert-True ($requestAuthData.contextHint -eq $contextHint) 'request_auth should echo the safe context hint metadata.'
    $approvalUrl = [string] $requestAuthData.approvalUrl
    $bearerToken = [string] $requestAuthData.bearerToken
    $approvalSecret = ($approvalUrl -split 'cdo_approve=')[1]

    $requestAuthAgain = Invoke-JsonRpcTool -Url $mcpUrl -Id 6 -ToolName 'request_auth'
    $requestAuthAgainJson = $requestAuthAgain.Content | ConvertFrom-Json
    Assert-True ($requestAuthAgainJson.result.structuredContent.status -eq 'pending_approval') 'Pending request_auth should stay pending.'
    Assert-True ($requestAuthAgainJson.result.structuredContent.approvalUrl -eq $approvalUrl) 'Pending request_auth should re-return the same approval URL.'
    Assert-True ($requestAuthAgainJson.result.structuredContent.bearerToken -eq $bearerToken) 'Pending request_auth should re-return the same bearer token.'

    $approvalGet = Invoke-SmokeRequest -Url $approvalUrl -Method 'GET'
    Assert-True ($approvalGet.StatusCode -eq 200) 'Approval GET should return 200.'
    Assert-True ($approvalGet.Content.Contains('MCP Approval')) 'Approval GET should render the approval page.'
    Assert-True ($approvalGet.Content.Contains('smoke-agent')) 'Approval GET should show the requesting agent name.'
    Assert-True ($approvalGet.Content.Contains($contextHint)) 'Approval GET should show the context hint.'
    Assert-True (-not $approvalGet.Content.Contains($bearerToken)) 'Approval GET should not expose the bearer token.'
    Assert-True (-not $approvalGet.Content.Contains($approvalSecret)) 'Approval GET should not expose the approval secret in the HTML body.'
    Assert-True (-not $approvalGet.Content.Contains('tokenHashPrefix')) 'Approval GET should not expose token hash prefixes.'

    $approvalPost = Invoke-SmokeRequest -Url $approvalUrl -Method 'POST' -Headers @{
        'Content-Type' = 'application/x-www-form-urlencoded'
    } -Body 'approve=yes'
    Assert-True ($approvalPost.StatusCode -eq 200) 'Approval POST should return 200.'
    Assert-True ($approvalPost.Content.Contains('Approval Complete')) 'Approval POST should render the success page.'
    Assert-True ($approvalPost.Content.Contains('smoke-agent')) 'Approval success should show the approved agent name.'
    Assert-True ($approvalPost.Content.Contains($contextHint)) 'Approval success should show the context hint.'
    Assert-True ($approvalPost.Content.Contains('approvedAt')) 'Approval success should show the approval timestamp.'
    Assert-True (-not $approvalPost.Content.Contains($bearerToken)) 'Approval success should not expose the bearer token.'
    Assert-True (-not $approvalPost.Content.Contains($approvalSecret)) 'Approval success should not expose the approval secret.'

    $htmlAfterApproval = Invoke-SmokeRequest -Url $rootUrl -Method 'GET'
    Assert-True ($htmlAfterApproval.StatusCode -eq 200) 'GET / after approval should return 200.'
    Assert-True ($htmlAfterApproval.Content.Contains('approved')) 'HTML response should report approved auth state after approval.'
    Assert-True ($htmlAfterApproval.Content.Contains('smoke-agent')) 'HTML response should show the approved agent name.'
    Assert-True ($htmlAfterApproval.Content.Contains($contextHint)) 'HTML response should show the context hint after approval.'
    Assert-True ($htmlAfterApproval.Content.Contains('approvedAt')) 'HTML response should show approvedAt after approval.'
    Assert-True ($htmlAfterApproval.Content.Contains('lastUsedAt')) 'HTML response should show lastUsedAt after approval.'
    Assert-True (-not $htmlAfterApproval.Content.Contains($bearerToken)) 'HTML response should not expose the bearer token after approval.'
    Assert-True (-not $htmlAfterApproval.Content.Contains($approvalSecret)) 'HTML response should not expose the approval secret after approval.'
    Assert-True (-not $htmlAfterApproval.Content.Contains('tokenHashPrefix')) 'HTML response should not expose token hash prefixes after approval.'
    Assert-True (-not $htmlAfterApproval.Content.Contains('storedTokenHashPrefix')) 'HTML response should not expose stored token hash prefixes after approval.'
    Assert-True (-not $htmlAfterApproval.Content.Contains('pendingBearerToken')) 'HTML response should not expose pending bearer token names after approval.'
    Assert-True (-not $htmlAfterApproval.Content.Contains('bearerTokenHash')) 'HTML response should not expose bearer token hash names after approval.'

    $serverStatusMissingHeader = Invoke-JsonRpcTool -Url $mcpUrl -Id 42 -ToolName 'server_status'
    $serverStatusMissingHeaderJson = $serverStatusMissingHeader.Content | ConvertFrom-Json
    $serverStatusMissingHeaderData = $serverStatusMissingHeaderJson.result.structuredContent
    Assert-True ($serverStatusMissingHeaderData.authConfigured) 'server_status should report configured auth after approval.'
    Assert-True ($serverStatusMissingHeaderData.authState -eq 'approved') 'server_status should expose the approved auth state after approval.'
    Assert-True (-not $serverStatusMissingHeaderData.authorized) 'server_status should still report unauthorized without the header.'
    Assert-True ($serverStatusMissingHeaderData.authReason -eq 'missing_token') 'server_status should explain that the bearer token was not sent.'
    Assert-True ($serverStatusMissingHeaderData.agentName -eq 'smoke-agent') 'server_status should expose safe agent name metadata.'
    Assert-True ($serverStatusMissingHeaderData.contextHint -eq $contextHint) 'server_status should expose safe context hint metadata.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $serverStatusMissingHeaderData.approvedAt)) 'server_status should expose approvedAt.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $serverStatusMissingHeaderData.lastUsedAt)) 'server_status should expose lastUsedAt.'
    $serverStatusMissingHeaderProps = @($serverStatusMissingHeaderData.PSObject.Properties | ForEach-Object { [string] $_.Name })
    Assert-True (-not ($serverStatusMissingHeaderProps -contains 'tokenHashPrefix')) 'server_status should not expose token hash prefixes.'
    Assert-True (-not ($serverStatusMissingHeaderProps -contains 'storedTokenHashPrefix')) 'server_status should not expose stored token hash prefixes.'
    Assert-True (-not $serverStatusMissingHeader.Content.Contains($bearerToken)) 'server_status should not expose the bearer token.'
    Assert-True (-not $serverStatusMissingHeader.Content.Contains($approvalSecret)) 'server_status should not expose the approval secret.'
    Assert-True (-not $serverStatusMissingHeader.Content.Contains('pendingBearerToken')) 'server_status should not expose pending bearer token names.'
    Assert-True (-not $serverStatusMissingHeader.Content.Contains('bearerTokenHash')) 'server_status should not expose bearer token hash names.'

    $toolsListAuthorized = Invoke-SmokeRequest -Url $mcpUrl -Method 'POST' -Headers @{
        Accept = 'application/json, text/event-stream'
        'Content-Type' = 'application/json'
        'MCP-Protocol-Version' = '2025-11-25'
        'X-CDO-Bearer-Token' = $bearerToken
    } -Body $toolsListBody
    $toolNamesAuthorized = Get-ToolNames -Content $toolsListAuthorized.Content
    Assert-True ($toolNamesAuthorized -contains 'list_dir') 'Authorized tools/list should expose list_dir.'
    Assert-True ($toolNamesAuthorized -contains 'read_file') 'Authorized tools/list should expose read_file.'
    Assert-True ($toolNamesAuthorized -contains 'write_file') 'Authorized tools/list should expose write_file.'
    Assert-True ($toolNamesAuthorized -contains 'create_dir') 'Authorized tools/list should expose create_dir.'
    Assert-True ($toolNamesAuthorized -contains 'delete_file') 'Authorized tools/list should expose delete_file.'
    Assert-True ($toolNamesAuthorized -contains 'delete_dir') 'Authorized tools/list should expose delete_dir.'
    Assert-True ($toolNamesAuthorized -contains 'rename_path') 'Authorized tools/list should expose rename_path.'

    $serverStatusAfterAuth = Invoke-JsonRpcTool -Url $mcpUrl -Id 41 -ToolName 'server_status' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $serverStatusAfterAuthJson = $serverStatusAfterAuth.Content | ConvertFrom-Json
    $serverStatusAfterAuthData = $serverStatusAfterAuthJson.result.structuredContent
    Assert-True ($serverStatusAfterAuthData.authorized) 'server_status should report authorized with the correct bearer token.'
    Assert-True ($serverStatusAfterAuthData.inspectorBearerHeaderPresent) 'server_status should report X-CDO-Bearer-Token header presence after auth.'
    Assert-True ($serverStatusAfterAuthData.bearerHeaderSource -eq 'x-cdo-bearer-token') 'server_status should report the inspector header source.'
    Assert-True ($serverStatusAfterAuthData.authReason -eq 'authorized') 'server_status should explain why auth succeeded.'
    Assert-True ($serverStatusAfterAuthData.agentName -eq 'smoke-agent') 'Authenticated server_status should expose safe agent name metadata.'
    Assert-True ($serverStatusAfterAuthData.contextHint -eq $contextHint) 'Authenticated server_status should expose safe context hint metadata.'

    $serverStatusAuthorizationHeader = Invoke-JsonRpcTool -Url $mcpUrl -Id 43 -ToolName 'server_status' -BearerToken $bearerToken -BearerHeaderName 'Authorization'
    $serverStatusAuthorizationHeaderJson = $serverStatusAuthorizationHeader.Content | ConvertFrom-Json
    Assert-True ($serverStatusAuthorizationHeaderJson.result.structuredContent.bearerHeaderSource -eq 'authorization') 'server_status should still accept Authorization headers.'

    $requestAuthConfigured = Invoke-JsonRpcTool -Url $mcpUrl -Id 7 -ToolName 'request_auth'
    $requestAuthConfiguredJson = $requestAuthConfigured.Content | ConvertFrom-Json
    Assert-True ($requestAuthConfiguredJson.result.structuredContent.status -eq 'already_configured') 'Unauthenticated request_auth should report already_configured after approval.'

    $requestAuthApproved = Invoke-JsonRpcTool -Url $mcpUrl -Id 8 -ToolName 'request_auth' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $requestAuthApprovedJson = $requestAuthApproved.Content | ConvertFrom-Json
    Assert-True ($requestAuthApprovedJson.result.structuredContent.status -eq 'approved') 'Authenticated request_auth should report approved.'

    $listRoot = Invoke-JsonRpcTool -Url $mcpUrl -Id 9 -ToolName 'list_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $listRootJson = $listRoot.Content | ConvertFrom-Json
    Assert-True ($listRootJson.result.structuredContent.path -eq '.') 'Root list_dir should report path ".".'
    $rootEntryNames = @($listRootJson.result.structuredContent.entries | ForEach-Object { [string] $_.name })
    Assert-True ($rootEntryNames -contains '.smoke-visible.txt') 'Root list_dir should include general dotfiles.'
    Assert-True ($rootEntryNames -contains 'smoke-fixture') 'Root list_dir should include the fixture directory.'
    Assert-True (-not ($rootEntryNames -contains '.cdo_auth.json')) 'Root list_dir should hide .cdo_* internal files.'

    $listFixture = Invoke-JsonRpcTool -Url $mcpUrl -Id 10 -ToolName 'list_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture'
    }
    $listFixtureJson = $listFixture.Content | ConvertFrom-Json
    Assert-True ($listFixtureJson.result.structuredContent.path -eq 'smoke-fixture') 'Subdirectory list_dir should report the requested relative path.'
    $fixtureEntryNames = @($listFixtureJson.result.structuredContent.entries | ForEach-Object { [string] $_.name })
    $fixtureEntryPaths = @($listFixtureJson.result.structuredContent.entries | ForEach-Object { [string] $_.path })
    Assert-True ($fixtureEntryNames -contains 'alpha.txt') 'Subdirectory list_dir should include regular files.'
    Assert-True ($fixtureEntryNames -contains '.beta.txt') 'Subdirectory list_dir should include dotfiles.'
    Assert-True ($fixtureEntryNames -contains 'nested') 'Subdirectory list_dir should include direct child directories.'
    Assert-True (-not ($fixtureEntryPaths -contains 'smoke-fixture/nested/inside.txt')) 'Subdirectory list_dir should not recurse into nested files.'

    $readFixture = Invoke-JsonRpcTool -Url $mcpUrl -Id 45 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
    }
    $readFixtureJson = $readFixture.Content | ConvertFrom-Json
    $readFixtureData = $readFixtureJson.result.structuredContent
    Assert-True ($readFixtureData.path -eq 'smoke-fixture/alpha.txt') 'read_file should report the requested path.'
    Assert-True ($readFixtureData.name -eq 'alpha.txt') 'read_file should report the basename.'
    Assert-True ($readFixtureData.encoding -eq 'utf-8') 'read_file should return text files as UTF-8.'
    Assert-True ($readFixtureData.content.Contains('alpha')) 'read_file should return file content.'
    Assert-True (-not $readFixtureData.truncated) 'read_file should not mark small files as truncated.'

    $readFixtureTruncated = Invoke-JsonRpcTool -Url $mcpUrl -Id 46 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
        maxBytes = 2
    }
    $readFixtureTruncatedJson = $readFixtureTruncated.Content | ConvertFrom-Json
    Assert-True ($readFixtureTruncatedJson.result.structuredContent.content -eq 'al') 'read_file should honor maxBytes.'
    Assert-True ($readFixtureTruncatedJson.result.structuredContent.truncated) 'read_file should mark truncated reads.'

    $readInternal = Invoke-JsonRpcTool -Url $mcpUrl -Id 47 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '.cdo_secret.txt'
    }
    $readInternalJson = $readInternal.Content | ConvertFrom-Json
    Assert-True ($readInternalJson.error.code -eq -32602) 'read_file should reject internal control files.'

    $readDirectory = Invoke-JsonRpcTool -Url $mcpUrl -Id 48 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture'
    }
    $readDirectoryJson = $readDirectory.Content | ConvertFrom-Json
    Assert-True ($readDirectoryJson.error.code -eq -32602) 'read_file should reject directories.'

    $createWriteDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 51 -ToolName 'create_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write'
    }
    $createWriteDirJson = $createWriteDir.Content | ConvertFrom-Json
    Assert-True ($createWriteDirJson.result.structuredContent.created) 'create_dir should create a new directory.'
    Assert-True (-not $createWriteDirJson.result.structuredContent.alreadyExisted) 'create_dir should report newly created directories.'

    $createWriteDirAgain = Invoke-JsonRpcTool -Url $mcpUrl -Id 52 -ToolName 'create_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write'
    }
    $createWriteDirAgainJson = $createWriteDirAgain.Content | ConvertFrom-Json
    Assert-True (-not $createWriteDirAgainJson.result.structuredContent.created) 'create_dir should not recreate existing directories.'
    Assert-True ($createWriteDirAgainJson.result.structuredContent.alreadyExisted) 'create_dir should report existing directories.'

    $writeNewFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 53 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
        content = 'first'
        encoding = 'utf-8'
    }
    $writeNewFileJson = $writeNewFile.Content | ConvertFrom-Json
    Assert-True ($writeNewFileJson.result.structuredContent.path -eq 'smoke-write/new.txt') 'write_file should report the written path.'
    Assert-True ($writeNewFileJson.result.structuredContent.bytesWritten -eq 5) 'write_file should report bytes written.'
    Assert-True (-not $writeNewFileJson.result.structuredContent.overwritten) 'write_file should report new files as not overwritten.'

    $readWrittenFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 54 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
    }
    $readWrittenFileJson = $readWrittenFile.Content | ConvertFrom-Json
    Assert-True ($readWrittenFileJson.result.structuredContent.content -eq 'first') 'write_file output should be readable.'

    $writeWithoutOverwrite = Invoke-JsonRpcTool -Url $mcpUrl -Id 55 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
        content = 'blocked'
        encoding = 'utf-8'
    }
    $writeWithoutOverwriteJson = $writeWithoutOverwrite.Content | ConvertFrom-Json
    Assert-True ($writeWithoutOverwriteJson.error.code -eq -32602) 'write_file should reject overwrites unless overwrite is true.'

    $writeWithOverwrite = Invoke-JsonRpcTool -Url $mcpUrl -Id 56 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
        content = 'second'
        encoding = 'utf-8'
        overwrite = $true
    }
    $writeWithOverwriteJson = $writeWithOverwrite.Content | ConvertFrom-Json
    Assert-True ($writeWithOverwriteJson.result.structuredContent.overwritten) 'write_file should report explicit overwrites.'

    $readOverwrittenFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 57 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
    }
    $readOverwrittenFileJson = $readOverwrittenFile.Content | ConvertFrom-Json
    Assert-True ($readOverwrittenFileJson.result.structuredContent.content -eq 'second') 'overwrite:true should update file content.'

    $writeBase64File = Invoke-JsonRpcTool -Url $mcpUrl -Id 58 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/blob.bin'
        content = 'AAEC/w=='
        encoding = 'base64'
    }
    $writeBase64FileJson = $writeBase64File.Content | ConvertFrom-Json
    Assert-True ($writeBase64FileJson.result.structuredContent.bytesWritten -eq 4) 'write_file should decode base64 content before writing.'

    $readBase64File = Invoke-JsonRpcTool -Url $mcpUrl -Id 59 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/blob.bin'
    }
    $readBase64FileJson = $readBase64File.Content | ConvertFrom-Json
    Assert-True ($readBase64FileJson.result.structuredContent.encoding -eq 'base64') 'read_file should return binary content as base64.'
    Assert-True ($readBase64FileJson.result.structuredContent.content -eq 'AAEC/w==') 'base64 write/read should preserve bytes.'

    $createNestedWithoutRecursive = Invoke-JsonRpcTool -Url $mcpUrl -Id 60 -ToolName 'create_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-recursive/a/b'
    }
    $createNestedWithoutRecursiveJson = $createNestedWithoutRecursive.Content | ConvertFrom-Json
    Assert-True ($createNestedWithoutRecursiveJson.error.code -eq -32602) 'create_dir should require recursive:true for missing parents.'

    $createNestedWithRecursive = Invoke-JsonRpcTool -Url $mcpUrl -Id 61 -ToolName 'create_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-recursive/a/b'
        recursive = $true
    }
    $createNestedWithRecursiveJson = $createNestedWithRecursive.Content | ConvertFrom-Json
    Assert-True ($createNestedWithRecursiveJson.result.structuredContent.created) 'create_dir should create nested directories when recursive is true.'

    $writeMissingParent = Invoke-JsonRpcTool -Url $mcpUrl -Id 62 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'missing-parent/new.txt'
        content = 'blocked'
        encoding = 'utf-8'
    }
    $writeMissingParentJson = $writeMissingParent.Content | ConvertFrom-Json
    Assert-True ($writeMissingParentJson.error.code -eq -32602) 'write_file should reject missing parent directories.'

    $createDirFileConflict = Invoke-JsonRpcTool -Url $mcpUrl -Id 63 -ToolName 'create_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
    }
    $createDirFileConflictJson = $createDirFileConflict.Content | ConvertFrom-Json
    Assert-True ($createDirFileConflictJson.error.code -eq -32602) 'create_dir should reject existing files.'

    $writeParentTraversal = Invoke-JsonRpcTool -Url $mcpUrl -Id 64 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '../outside.txt'
        content = 'blocked'
        encoding = 'utf-8'
    }
    $writeParentTraversalJson = $writeParentTraversal.Content | ConvertFrom-Json
    Assert-True ($writeParentTraversalJson.error.code -eq -32602) 'write_file should reject parent directory paths.'

    $writeAbsolute = Invoke-JsonRpcTool -Url $mcpUrl -Id 65 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '/tmp/outside.txt'
        content = 'blocked'
        encoding = 'utf-8'
    }
    $writeAbsoluteJson = $writeAbsolute.Content | ConvertFrom-Json
    Assert-True ($writeAbsoluteJson.error.code -eq -32602) 'write_file should reject absolute paths.'

    $writeInternal = Invoke-JsonRpcTool -Url $mcpUrl -Id 66 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '.cdo_secret.txt'
        content = 'blocked'
        encoding = 'utf-8'
        overwrite = $true
    }
    $writeInternalJson = $writeInternal.Content | ConvertFrom-Json
    Assert-True ($writeInternalJson.error.code -eq -32602) 'write_file should reject internal control files.'

    $writeEntrypoint = Invoke-JsonRpcTool -Url $mcpUrl -Id 67 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'cdo.php'
        content = 'blocked'
        encoding = 'utf-8'
        overwrite = $true
    }
    $writeEntrypointJson = $writeEntrypoint.Content | ConvertFrom-Json
    Assert-True ($writeEntrypointJson.error.code -eq -32602) 'write_file should reject the current entrypoint file.'

    $createInternal = Invoke-JsonRpcTool -Url $mcpUrl -Id 68 -ToolName 'create_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '.cdo_newdir'
    }
    $createInternalJson = $createInternal.Content | ConvertFrom-Json
    Assert-True ($createInternalJson.error.code -eq -32602) 'create_dir should reject internal control file names.'

    $deleteFileWithoutConfirm = Invoke-JsonRpcTool -Url $mcpUrl -Id 72 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/delete-me.txt'
    }
    $deleteFileWithoutConfirmJson = $deleteFileWithoutConfirm.Content | ConvertFrom-Json
    Assert-True ($deleteFileWithoutConfirmJson.error.code -eq -32602) 'delete_file should require confirm:true.'

    $deleteDirWithoutConfirm = Invoke-JsonRpcTool -Url $mcpUrl -Id 73 -ToolName 'delete_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/empty-dir'
    }
    $deleteDirWithoutConfirmJson = $deleteDirWithoutConfirm.Content | ConvertFrom-Json
    Assert-True ($deleteDirWithoutConfirmJson.error.code -eq -32602) 'delete_dir should require confirm:true.'

    $renameWithoutConfirm = Invoke-JsonRpcTool -Url $mcpUrl -Id 74 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = 'smoke-rename/without-confirm.txt'
    }
    $renameWithoutConfirmJson = $renameWithoutConfirm.Content | ConvertFrom-Json
    Assert-True ($renameWithoutConfirmJson.error.code -eq -32602) 'rename_path should require confirm:true.'

    $deleteParentTraversal = Invoke-JsonRpcTool -Url $mcpUrl -Id 75 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '../outside.txt'
        confirm = $true
    }
    $deleteParentTraversalJson = $deleteParentTraversal.Content | ConvertFrom-Json
    Assert-True ($deleteParentTraversalJson.error.code -eq -32602) 'delete_file should reject parent directory paths.'

    $deleteAbsolute = Invoke-JsonRpcTool -Url $mcpUrl -Id 76 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '/tmp/outside.txt'
        confirm = $true
    }
    $deleteAbsoluteJson = $deleteAbsolute.Content | ConvertFrom-Json
    Assert-True ($deleteAbsoluteJson.error.code -eq -32602) 'delete_file should reject absolute paths.'

    $deleteInternal = Invoke-JsonRpcTool -Url $mcpUrl -Id 77 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '.cdo_secret.txt'
        confirm = $true
    }
    $deleteInternalJson = $deleteInternal.Content | ConvertFrom-Json
    Assert-True ($deleteInternalJson.error.code -eq -32602) 'delete_file should reject internal control files.'

    $deleteEntrypoint = Invoke-JsonRpcTool -Url $mcpUrl -Id 78 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'cdo.php'
        confirm = $true
    }
    $deleteEntrypointJson = $deleteEntrypoint.Content | ConvertFrom-Json
    Assert-True ($deleteEntrypointJson.error.code -eq -32602) 'delete_file should reject the current entrypoint file.'

    $deleteDirectoryAsFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 79 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/non-empty-dir'
        confirm = $true
    }
    $deleteDirectoryAsFileJson = $deleteDirectoryAsFile.Content | ConvertFrom-Json
    Assert-True ($deleteDirectoryAsFileJson.error.code -eq -32602) 'delete_file should reject directories.'

    $deleteNonEmptyDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 80 -ToolName 'delete_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/non-empty-dir'
        confirm = $true
    }
    $deleteNonEmptyDirJson = $deleteNonEmptyDir.Content | ConvertFrom-Json
    Assert-True ($deleteNonEmptyDirJson.error.code -eq -32602) 'delete_dir should reject non-empty directories.'

    $deleteFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 81 -ToolName 'delete_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/delete-me.txt'
        confirm = $true
    }
    $deleteFileJson = $deleteFile.Content | ConvertFrom-Json
    Assert-True ($deleteFileJson.result.structuredContent.deleted) 'delete_file should delete files.'
    Assert-True ($deleteFileJson.result.structuredContent.path -eq 'smoke-delete/delete-me.txt') 'delete_file should report the deleted path.'

    $readDeletedFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 82 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/delete-me.txt'
    }
    $readDeletedFileJson = $readDeletedFile.Content | ConvertFrom-Json
    Assert-True ($readDeletedFileJson.error.code -eq -32602) 'read_file should fail after delete_file succeeds.'

    $deleteEmptyDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 83 -ToolName 'delete_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-delete/empty-dir'
        confirm = $true
    }
    $deleteEmptyDirJson = $deleteEmptyDir.Content | ConvertFrom-Json
    Assert-True ($deleteEmptyDirJson.result.structuredContent.deleted) 'delete_dir should delete empty directories.'

    $renameExistingDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 84 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = 'smoke-rename/existing.txt'
        confirm = $true
    }
    $renameExistingDestinationJson = $renameExistingDestination.Content | ConvertFrom-Json
    Assert-True ($renameExistingDestinationJson.error.code -eq -32602) 'rename_path should reject existing destinations.'

    $renameAbsoluteDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 85 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = '/tmp/outside.txt'
        confirm = $true
    }
    $renameAbsoluteDestinationJson = $renameAbsoluteDestination.Content | ConvertFrom-Json
    Assert-True ($renameAbsoluteDestinationJson.error.code -eq -32602) 'rename_path should reject absolute destination paths.'

    $renameParentTraversal = Invoke-JsonRpcTool -Url $mcpUrl -Id 86 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = '../outside.txt'
        confirm = $true
    }
    $renameParentTraversalJson = $renameParentTraversal.Content | ConvertFrom-Json
    Assert-True ($renameParentTraversalJson.error.code -eq -32602) 'rename_path should reject parent directory destination paths.'

    $renameInternalSource = Invoke-JsonRpcTool -Url $mcpUrl -Id 87 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = '.cdo_secret.txt'
        to = 'smoke-rename/internal-source.txt'
        confirm = $true
    }
    $renameInternalSourceJson = $renameInternalSource.Content | ConvertFrom-Json
    Assert-True ($renameInternalSourceJson.error.code -eq -32602) 'rename_path should reject internal control files as the source.'

    $renameInternalDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 88 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = '.cdo_newname'
        confirm = $true
    }
    $renameInternalDestinationJson = $renameInternalDestination.Content | ConvertFrom-Json
    Assert-True ($renameInternalDestinationJson.error.code -eq -32602) 'rename_path should reject internal control files as the destination.'

    $renameEntrypointSource = Invoke-JsonRpcTool -Url $mcpUrl -Id 89 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'cdo.php'
        to = 'smoke-rename/entrypoint-source.php'
        confirm = $true
    }
    $renameEntrypointSourceJson = $renameEntrypointSource.Content | ConvertFrom-Json
    Assert-True ($renameEntrypointSourceJson.error.code -eq -32602) 'rename_path should reject the current entrypoint file as the source.'

    $renameEntrypointDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 90 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = 'cdo.php'
        confirm = $true
    }
    $renameEntrypointDestinationJson = $renameEntrypointDestination.Content | ConvertFrom-Json
    Assert-True ($renameEntrypointDestinationJson.error.code -eq -32602) 'rename_path should reject the current entrypoint file as the destination.'

    $renameMissingParent = Invoke-JsonRpcTool -Url $mcpUrl -Id 91 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = 'missing-parent/renamed.txt'
        confirm = $true
    }
    $renameMissingParentJson = $renameMissingParent.Content | ConvertFrom-Json
    Assert-True ($renameMissingParentJson.error.code -eq -32602) 'rename_path should reject missing destination parents.'

    $renameFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 92 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/file-source.txt'
        to = 'smoke-rename/file-destination.txt'
        confirm = $true
    }
    $renameFileJson = $renameFile.Content | ConvertFrom-Json
    Assert-True ($renameFileJson.result.structuredContent.renamed) 'rename_path should rename files.'
    Assert-True ($renameFileJson.result.structuredContent.type -eq 'file') 'rename_path should report file renames.'

    $readRenamedFileSource = Invoke-JsonRpcTool -Url $mcpUrl -Id 93 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-rename/file-source.txt'
    }
    $readRenamedFileSourceJson = $readRenamedFileSource.Content | ConvertFrom-Json
    Assert-True ($readRenamedFileSourceJson.error.code -eq -32602) 'read_file should fail on the old file path after rename_path.'

    $readRenamedFileDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 94 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-rename/file-destination.txt'
    }
    $readRenamedFileDestinationJson = $readRenamedFileDestination.Content | ConvertFrom-Json
    Assert-True ($readRenamedFileDestinationJson.result.structuredContent.content.Contains('rename-file')) 'read_file should read the new file path after rename_path.'

    $renameDirectory = Invoke-JsonRpcTool -Url $mcpUrl -Id 95 -ToolName 'rename_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-rename/dir-source'
        to = 'smoke-rename/dir-destination'
        confirm = $true
    }
    $renameDirectoryJson = $renameDirectory.Content | ConvertFrom-Json
    Assert-True ($renameDirectoryJson.result.structuredContent.renamed) 'rename_path should rename directories.'
    Assert-True ($renameDirectoryJson.result.structuredContent.type -eq 'dir') 'rename_path should report directory renames.'

    $readRenamedDirectorySource = Invoke-JsonRpcTool -Url $mcpUrl -Id 96 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-rename/dir-source/inside.txt'
    }
    $readRenamedDirectorySourceJson = $readRenamedDirectorySource.Content | ConvertFrom-Json
    Assert-True ($readRenamedDirectorySourceJson.error.code -eq -32602) 'read_file should fail on old directory contents after rename_path.'

    $readRenamedDirectoryDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 97 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-rename/dir-destination/inside.txt'
    }
    $readRenamedDirectoryDestinationJson = $readRenamedDirectoryDestination.Content | ConvertFrom-Json
    Assert-True ($readRenamedDirectoryDestinationJson.result.structuredContent.content.Contains('rename-dir-file')) 'read_file should read moved directory contents after rename_path.'

    $invalidParent = Invoke-JsonRpcTool -Url $mcpUrl -Id 11 -ToolName 'list_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '..'
    }
    $invalidParentJson = $invalidParent.Content | ConvertFrom-Json
    Assert-True ($invalidParentJson.error.code -eq -32602) 'list_dir should reject parent directory paths.'

    $invalidAbsolute = Invoke-JsonRpcTool -Url $mcpUrl -Id 12 -ToolName 'list_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '/etc'
    }
    $invalidAbsoluteJson = $invalidAbsolute.Content | ConvertFrom-Json
    Assert-True ($invalidAbsoluteJson.error.code -eq -32602) 'list_dir should reject absolute paths.'

    $missingDir = Invoke-JsonRpcTool -Url $mcpUrl -Id 13 -ToolName 'list_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'missing-dir'
    }
    $missingDirJson = $missingDir.Content | ConvertFrom-Json
    Assert-True ($missingDirJson.error.code -eq -32602) 'list_dir should reject non-existent directories.'

    $authState = Get-Content -Raw $authFile | ConvertFrom-Json
    $authState.lastUsedAt = [int][DateTimeOffset]::UtcNow.AddDays(-31).ToUnixTimeSeconds()
    $authState | ConvertTo-Json -Depth 8 | Set-Content -Path $authFile

    $expiredList = Invoke-JsonRpcTool -Url $mcpUrl -Id 14 -ToolName 'list_dir' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $expiredListJson = $expiredList.Content | ConvertFrom-Json
    Assert-True ($expiredListJson.error.code -eq -32001) 'Expired auth should reject protected tools.'

    $lockedRequestAuth = Invoke-JsonRpcTool -Url $mcpUrl -Id 15 -ToolName 'request_auth'
    $lockedRequestAuthJson = $lockedRequestAuth.Content | ConvertFrom-Json
    Assert-True ($lockedRequestAuthJson.result.structuredContent.status -eq 'locked') 'Expired auth should move request_auth into locked state.'

    Assert-True (Test-Path $debugLog) 'Auth debugging should create .cdo_debug.log.'
    $debugLogContent = Get-Content -Raw $debugLog
    Assert-True ($debugLogContent.Contains('"event":"tools_list"')) 'Debug log should record tools/list decisions.'
    Assert-True ($debugLogContent.Contains('"event":"auth_context"')) 'Debug log should record auth context decisions.'

    Write-Output 'Smoke OK'
} finally {
    if ($serverProcess -and -not $serverProcess.HasExited) {
        Stop-Process -Id $serverProcess.Id -ErrorAction SilentlyContinue
    }

    if ($null -eq $previousAuthPath -or $previousAuthPath -eq '') {
        Remove-Item Env:\CDO_AUTH_STATE_PATH -ErrorAction SilentlyContinue
    } else {
        $env:CDO_AUTH_STATE_PATH = $previousAuthPath
    }

    if ($null -eq $previousDebugPath -or $previousDebugPath -eq '') {
        Remove-Item Env:\CDO_DEBUG_LOG_PATH -ErrorAction SilentlyContinue
    } else {
        $env:CDO_DEBUG_LOG_PATH = $previousDebugPath
    }

    Remove-IfExists $authFile
    Remove-IfExists $debugLog
    Remove-IfExists $renamedEntrypoint
    Remove-IfExists $subdirEntrypointDir
    Remove-IfExists $visibleDotFile
    Remove-IfExists $internalControlFile
    Remove-IfExists $fixtureDir
    Remove-IfExists $writeDir
    Remove-IfExists $recursiveWriteDir
    Remove-IfExists $deleteDir
    Remove-IfExists $renameDir
    Remove-IfExists $tmpDir
}
