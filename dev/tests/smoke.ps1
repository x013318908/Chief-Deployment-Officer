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

function ConvertFrom-Utf8Base64 {
    param(
        [string] $Value
    )

    return [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($Value))
}

function Get-Sha256FileHash {
    param(
        [string] $Path
    )

    $sha256 = [System.Security.Cryptography.SHA256]::Create()
    $stream = [System.IO.File]::OpenRead($Path)

    try {
        return ([System.BitConverter]::ToString($sha256.ComputeHash($stream)) -replace '-', '').ToLowerInvariant()
    } finally {
        $stream.Dispose()
        $sha256.Dispose()
    }
}

function Test-PosixFileModes {
    return [System.IO.Path]::DirectorySeparatorChar -eq '/'
}

function Get-PosixFileMode {
    param(
        [string] $Path
    )

    if (-not (Test-PosixFileModes)) {
        return $null
    }

    $mode = & php -r 'clearstatcache(true, $argv[1]); $mode = fileperms($argv[1]); if ($mode === false) { exit(1); } echo sprintf("%04o", $mode & 07777);' $Path

    if ($LASTEXITCODE -ne 0) {
        throw "Could not read POSIX mode for $Path."
    }

    return [string] $mode
}

function Get-ExpectedNewFileMode {
    if (-not (Test-PosixFileModes)) {
        return $null
    }

    $mode = & php -r '$mode = 0666 & ~umask(); echo sprintf("%04o", $mode);'

    if ($LASTEXITCODE -ne 0) {
        throw 'Could not read PHP umask.'
    }

    return [string] $mode
}

function Set-PosixFileMode {
    param(
        [string] $Path,
        [string] $Mode
    )

    if (-not (Test-PosixFileModes)) {
        return
    }

    & php -r 'if (!chmod($argv[1], octdec($argv[2]))) { exit(1); }' $Path $Mode

    if ($LASTEXITCODE -ne 0) {
        throw "Could not set POSIX mode $Mode for $Path."
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

function Invoke-MultipartFileUpload {
    param(
        [string] $Url,
        [string] $FilePath,
        [string] $FieldName = 'envFile'
    )

    Add-Type -AssemblyName System.Net.Http

    $client = [System.Net.Http.HttpClient]::new()
    $content = [System.Net.Http.MultipartFormDataContent]::new()

    try {
        $bytes = [System.IO.File]::ReadAllBytes($FilePath)
        $fileContent = [System.Net.Http.ByteArrayContent]::new($bytes)
        $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('text/plain')
        $content.Add($fileContent, $FieldName, [System.IO.Path]::GetFileName($FilePath))
        $response = $client.PostAsync($Url, $content).GetAwaiter().GetResult()
        $responseContent = $response.Content.ReadAsStringAsync().GetAwaiter().GetResult()

        return [pscustomobject]@{
            StatusCode = [int] $response.StatusCode
            Content = [string] $responseContent
            Headers = $response.Headers
        }
    } finally {
        $content.Dispose()
        $client.Dispose()
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
$envStateFile = Join-Path $tmpDir 'smoke-env.json'
$debugLog = Join-Path $tmpDir 'smoke-debug.log'
$nestedAuthFile = Join-Path $tmpDir 'smoke-nested-auth.json'
$nestedEnvStateFile = Join-Path $tmpDir 'smoke-nested-env.json'
$nestedDebugLog = Join-Path $tmpDir 'smoke-nested-debug.log'
$envSecretsRoot = Join-Path $repoRoot '.cdo-secrets'
$envUploadFile = Join-Path $tmpDir 'smoke-production-env.txt'
$renamedEntrypoint = Join-Path $repoRoot 'public_html\chief-smoke.php'
$defaultAuthFile = Join-Path $repoRoot 'public_html\.cdo_auth.json'
$defaultEnvStateFile = Join-Path $repoRoot 'public_html\.cdo_env.json'
$defaultDebugLog = Join-Path $repoRoot 'public_html\.cdo_debug.log'
$renamedAuthFile = Join-Path $repoRoot 'public_html\.chief-smoke_auth.json'
$renamedEnvStateFile = Join-Path $repoRoot 'public_html\.chief-smoke_env.json'
$renamedDebugLog = Join-Path $repoRoot 'public_html\.chief-smoke_debug.log'
$subdirEntrypointDir = Join-Path $repoRoot 'public_html\agent-smoke'
$subdirEntrypoint = Join-Path $subdirEntrypointDir 'cdo.php'
$nestedDocrootDir = Join-Path $repoRoot 'public_html\nested-docroot'
$nestedDocrootEntrypoint = Join-Path $nestedDocrootDir 'cdo.php'
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
Remove-IfExists $envStateFile
Remove-IfExists $debugLog
Remove-IfExists $nestedAuthFile
Remove-IfExists $nestedEnvStateFile
Remove-IfExists $nestedDebugLog
Remove-IfExists $envSecretsRoot
Remove-IfExists $renamedEntrypoint
Remove-IfExists $defaultAuthFile
Remove-IfExists $defaultEnvStateFile
Remove-IfExists $defaultDebugLog
Remove-IfExists $renamedAuthFile
Remove-IfExists $renamedEnvStateFile
Remove-IfExists $renamedDebugLog
Remove-IfExists $subdirEntrypointDir
Remove-IfExists $nestedDocrootDir
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
New-Item -ItemType Directory -Path $nestedDocrootDir | Out-Null
Copy-Item -Path $entrypoint -Destination $nestedDocrootEntrypoint -Force
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
$previousEnvPath = $env:CDO_ENV_STATE_PATH
$previousDebugPath = $env:CDO_DEBUG_LOG_PATH
$env:CDO_AUTH_STATE_PATH = $authFile
$env:CDO_ENV_STATE_PATH = $envStateFile
$env:CDO_DEBUG_LOG_PATH = $debugLog

$port = Get-FreePort
$serverProcess = Start-Process -FilePath 'php' `
    -ArgumentList '-S', ("127.0.0.1:" + $port), '-t', 'public_html', 'dev/server/router.php' `
    -WorkingDirectory $repoRoot `
    -PassThru
$nestedServerProcess = $null
$renamedNoOverrideServerProcess = $null

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
    Assert-True ($html.Content.Contains((ConvertFrom-Utf8Base64 '44GT44Gu44OX44Ot44Oz44OX44OI44KSQUnjgqjjg7zjgrjjgqfjg7Pjg4jjgavmuKHjgZfjgabjgY/jgaDjgZXjgYQ='))) 'HTML response should include the user CTA.'
    $expectedCopyPrompt = (ConvertFrom-Utf8Base64 '44GT44KM44KS5L2/44GE44Gf44GEIGA=') + $fileUrl + (ConvertFrom-Utf8Base64 'YA==')
    Assert-True ($html.Content.Contains('<input id="cdo-agent-prompt" class="copy-input" type="text" readonly value="')) 'HTML response should include a readonly prompt input.'
    Assert-True ($html.Content.Contains($expectedCopyPrompt)) 'HTML response should copy only the short agent prompt.'
    Assert-True ($html.Content.Contains('data-copy-target="cdo-agent-prompt"')) 'HTML response should include a copy button for the prompt.'
    Assert-True ($html.Content.Contains('<svg aria-hidden="true"')) 'HTML response should render the copy button as an icon button.'
    Assert-True ($html.Content.Contains('navigator.clipboard.writeText')) 'HTML response should include clipboard copy JavaScript.'
    Assert-True ($html.Content.Contains((ConvertFrom-Utf8Base64 '54++5Zyo44Gu54q25oWLOiDjgb7jgaDmib/oqo3jgZXjgozjgabjgYTjgb7jgZvjgpPjgII='))) 'HTML response should show the initial user-readable auth state.'
    Assert-True ($html.Content.Contains((ConvertFrom-Utf8Base64 '6Kmz57Sw5oOF5aCx'))) 'HTML response should include collapsible detailed auth information.'
    Assert-True ($html.Content.Contains((ConvertFrom-Utf8Base64 '5pyq6Kit5a6a'))) 'HTML response should display unset values in Japanese.'
    Assert-True (-not $html.Content.Contains('not set')) 'HTML response should not display unset values as not set.'
    $userGuidanceIndex = $html.Content.IndexOf((ConvertFrom-Utf8Base64 '5L2/44GE5pa5'))
    $aiGuidanceIndex = $html.Content.IndexOf((ConvertFrom-Utf8Base64 'QUnjgqjjg7zjgrjjgqfjg7Pjg4jlkJHjgZHjga7mjqXntprmiYvpoIY='))
    Assert-True ($userGuidanceIndex -ge 0) 'HTML response should include a user-facing section.'
    Assert-True ($aiGuidanceIndex -gt $userGuidanceIndex) 'HTML response should place AI-agent guidance after the user-facing section.'
    Assert-True ($html.Content.Contains('MCP endpoint')) 'HTML response should include the MCP endpoint.'
    Assert-True ($html.Content.Contains('Suggested prompt:')) 'HTML response should include the suggested prompt for agents.'
    Assert-True ($html.Content.Contains('Use this exact URL as the MCP endpoint:')) 'HTML response should include exact endpoint instructions for agents.'
    Assert-True ($html.Content.Contains('tools/list')) 'HTML response should tell agents to call tools/list first.'
    Assert-True ($html.Content.Contains('server_status')) 'HTML response should tell agents to call server_status.'
    Assert-True ($html.Content.Contains('contextHint')) 'HTML response should explain contextHint usage.'
    Assert-True ($html.Content.Contains('this exact URL')) 'HTML response should tell agents to keep the provided endpoint URL.'
    Assert-True ($html.Content.Contains('Do not inspect a local repository first')) 'HTML response should prevent unnecessary local repo inspection.'
    Assert-True ($html.Content.Contains('approvalUrl')) 'HTML response should tell agents to pass the approval URL to the user.'
    Assert-True ($html.Content.Contains('Do not ask the user to paste it back')) 'HTML response should tell agents not to ask users to paste tokens.'
    Assert-True ($html.Content.Contains('timeout')) 'HTML response should include timeout handling guidance.'
    Assert-True ($html.Content.Contains('Do not retry')) 'HTML response should tell agents not to retry timed-out operations immediately.'
    Assert-True ($html.Content.Contains('list_dir and read_file')) 'HTML response should tell agents to verify target state after a timeout.'
    Assert-True ($html.Content.Contains('X-CDO-Bearer-Token')) 'HTML response should mention the preferred bearer header.'
    Assert-True ($html.Content.Contains('SSE transport is not implemented')) 'HTML response should explain that SSE transport is not implemented.'
    Assert-True ($html.Content.Contains('OAuth/OIDC discovery endpoints are not implemented')) 'HTML response should explain that OAuth discovery is not implemented.'
    Assert-True ($html.Content.Contains('MCP Inspector')) 'HTML response should include MCP Inspector guidance.'
    Assert-True ($html.Content.Contains('agent-a.php')) 'HTML response should explain the separate filename model for multiple agents.'
    Assert-True ($html.Content.Contains('recursive delete is not implemented')) 'HTML response should document the recursive delete limitation.'
    Assert-True ($html.Content.Contains('Rename overwrite/replace is not implemented')) 'HTML response should document the rename replacement limitation.'
    Assert-True ($html.Content.Contains('not_configured')) 'HTML response should report missing auth state before auth.'
    Assert-True (-not $html.Content.Contains('tokenHashPrefix')) 'HTML response should not expose token hash prefixes.'
    Assert-True (-not $html.Content.Contains('storedTokenHashPrefix')) 'HTML response should not expose stored token hash prefixes.'
    Assert-True (-not $html.Content.Contains('approvalSecret')) 'HTML response should not expose approval secrets.'
    Assert-True (-not $html.Content.Contains('pendingBearerToken')) 'HTML response should not expose pending bearer tokens.'
    Assert-True (-not $html.Content.Contains('bearerTokenHash')) 'HTML response should not expose bearer token hashes.'

    $directFile = Invoke-SmokeRequest -Url $fileUrl -Method 'GET'
    Assert-True ($directFile.StatusCode -eq 200) 'GET /cdo.php should return 200.'
    Assert-True ($directFile.Content.Contains('Chief-Deployment-Officer')) 'Direct entrypoint response should contain app name.'

    $invalidApprovalNoState = Invoke-SmokeRequest -Url ($fileUrl + '?cdo_approve=invalid') -Method 'GET'
    Assert-True ($invalidApprovalNoState.StatusCode -eq 404) 'Invalid approval links without auth state should return 404.'
    Assert-True ($invalidApprovalNoState.Content.Contains('.cdo_auth.json')) 'Invalid approval links should explain how to reset auth.'
    Assert-True (-not $invalidApprovalNoState.Content.Contains('Fatal error')) 'Invalid approval links without auth state should not cause PHP fatal errors.'

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

    $renamedNoOverridePort = Get-FreePort
    $currentAuthPath = $env:CDO_AUTH_STATE_PATH
    $currentEnvPath = $env:CDO_ENV_STATE_PATH
    $currentDebugPath = $env:CDO_DEBUG_LOG_PATH
    Remove-Item Env:\CDO_AUTH_STATE_PATH -ErrorAction SilentlyContinue
    Remove-Item Env:\CDO_ENV_STATE_PATH -ErrorAction SilentlyContinue
    Remove-Item Env:\CDO_DEBUG_LOG_PATH -ErrorAction SilentlyContinue
    $renamedNoOverrideServerProcess = Start-Process -FilePath 'php' `
        -ArgumentList '-S', ("127.0.0.1:" + $renamedNoOverridePort), '-t', 'public_html', 'dev/server/router.php' `
        -WorkingDirectory $repoRoot `
        -PassThru
    $env:CDO_AUTH_STATE_PATH = $currentAuthPath
    $env:CDO_ENV_STATE_PATH = $currentEnvPath
    $env:CDO_DEBUG_LOG_PATH = $currentDebugPath

    try {
        Start-Sleep -Seconds 2
        $renamedNoOverrideUrl = "http://127.0.0.1:$renamedNoOverridePort/chief-smoke.php"
        $defaultNoOverrideUrl = "http://127.0.0.1:$renamedNoOverridePort/cdo.php"

        $renamedNoOverrideRequestAuth = Invoke-JsonRpcTool -Url $renamedNoOverrideUrl -Id 18 -ToolName 'request_auth' -Arguments @{
            agentName = 'renamed-no-override-agent'
        }
        $renamedNoOverrideRequestAuthJson = $renamedNoOverrideRequestAuth.Content | ConvertFrom-Json
        $renamedNoOverrideApprovalUrl = [string] $renamedNoOverrideRequestAuthJson.result.structuredContent.approvalUrl
        $renamedNoOverrideBearerToken = [string] $renamedNoOverrideRequestAuthJson.result.structuredContent.bearerToken
        Assert-True ($renamedNoOverrideApprovalUrl.Contains('/chief-smoke.php?cdo_approve=')) 'Renamed entrypoint without overrides should build approval URLs with the current filename.'
        Assert-True (Test-Path $renamedAuthFile) 'Renamed entrypoint without overrides should create .chief-smoke_auth.json.'
        Assert-True (Test-Path $renamedDebugLog) 'Renamed entrypoint without overrides should create .chief-smoke_debug.log.'
        Assert-True (-not (Test-Path $defaultAuthFile)) 'Renamed entrypoint without overrides should not create .cdo_auth.json.'

        $renamedNoOverrideApprovalPost = Invoke-SmokeRequest -Url $renamedNoOverrideApprovalUrl -Method 'POST' -Headers @{
            'Content-Type' = 'application/x-www-form-urlencoded'
        } -Body 'approve=yes'
        Assert-True ($renamedNoOverrideApprovalPost.StatusCode -eq 200) 'Renamed entrypoint approval without overrides should return 200.'

        $defaultStatusWithRenamedToken = Invoke-JsonRpcTool -Url $defaultNoOverrideUrl -Id 19 -ToolName 'server_status' -BearerToken $renamedNoOverrideBearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
        $defaultStatusWithRenamedTokenJson = $defaultStatusWithRenamedToken.Content | ConvertFrom-Json
        Assert-True (-not $defaultStatusWithRenamedTokenJson.result.structuredContent.authorized) 'Default entrypoint should not accept renamed entrypoint token.'
        Assert-True (-not $defaultStatusWithRenamedTokenJson.result.structuredContent.authConfigured) 'Default entrypoint should not share renamed entrypoint auth state.'

        $renamedEnvPathResponse = Invoke-JsonRpcTool -Url $renamedNoOverrideUrl -Id 20 -ToolName 'get_env_path' -BearerToken $renamedNoOverrideBearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
        $renamedEnvPathJson = $renamedEnvPathResponse.Content | ConvertFrom-Json
        $renamedEnvPath = [string] $renamedEnvPathJson.result.structuredContent.envPath
        $renamedRequestEnvUpload = Invoke-JsonRpcTool -Url $renamedNoOverrideUrl -Id 21 -ToolName 'request_env_upload' -BearerToken $renamedNoOverrideBearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
        $renamedRequestEnvUploadJson = $renamedRequestEnvUpload.Content | ConvertFrom-Json
        Assert-True ($renamedRequestEnvUploadJson.result.structuredContent.status -eq 'pending_upload') 'Renamed entrypoint should request env upload without overrides.'
        Assert-True (Test-Path $renamedEnvStateFile) 'Renamed entrypoint should create .chief-smoke_env.json.'
        Assert-True (-not (Test-Path $defaultEnvStateFile)) 'Renamed entrypoint should not create .cdo_env.json.'

        $defaultNoOverrideRequestAuth = Invoke-JsonRpcTool -Url $defaultNoOverrideUrl -Id 22 -ToolName 'request_auth' -Arguments @{
            agentName = 'default-no-override-agent'
        }
        $defaultNoOverrideRequestAuthJson = $defaultNoOverrideRequestAuth.Content | ConvertFrom-Json
        $defaultNoOverrideApprovalUrl = [string] $defaultNoOverrideRequestAuthJson.result.structuredContent.approvalUrl
        $defaultNoOverrideBearerToken = [string] $defaultNoOverrideRequestAuthJson.result.structuredContent.bearerToken
        $defaultNoOverrideApprovalPost = Invoke-SmokeRequest -Url $defaultNoOverrideApprovalUrl -Method 'POST' -Headers @{
            'Content-Type' = 'application/x-www-form-urlencoded'
        } -Body 'approve=yes'
        Assert-True ($defaultNoOverrideApprovalPost.StatusCode -eq 200) 'Default entrypoint approval without overrides should return 200.'

        $defaultEnvPathResponse = Invoke-JsonRpcTool -Url $defaultNoOverrideUrl -Id 23 -ToolName 'get_env_path' -BearerToken $defaultNoOverrideBearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
        $defaultEnvPathJson = $defaultEnvPathResponse.Content | ConvertFrom-Json
        $defaultEnvPath = [string] $defaultEnvPathJson.result.structuredContent.envPath
        Assert-True ($renamedEnvPath -ne $defaultEnvPath) 'Renamed and default entrypoints should use different env storage hashes in the same directory.'

        $renamedListRoot = Invoke-JsonRpcTool -Url $renamedNoOverrideUrl -Id 24 -ToolName 'list_dir' -BearerToken $renamedNoOverrideBearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
        $renamedListRootJson = $renamedListRoot.Content | ConvertFrom-Json
        $renamedRootEntryNames = @($renamedListRootJson.result.structuredContent.entries | ForEach-Object { [string] $_.name })
        Assert-True (-not ($renamedRootEntryNames -contains '.chief-smoke_auth.json')) 'list_dir should hide renamed auth state files.'
        Assert-True (-not ($renamedRootEntryNames -contains '.chief-smoke_env.json')) 'list_dir should hide renamed env state files.'
        Assert-True (-not ($renamedRootEntryNames -contains '.chief-smoke_debug.log')) 'list_dir should hide renamed debug logs.'
        Assert-True (-not ($renamedRootEntryNames -contains '.cdo_auth.json')) 'list_dir should hide default auth state files.'
    } finally {
        if ($renamedNoOverrideServerProcess -and -not $renamedNoOverrideServerProcess.HasExited) {
            Stop-Process -Id $renamedNoOverrideServerProcess.Id -ErrorAction SilentlyContinue
        }

        $renamedNoOverrideServerProcess = $null
        Remove-IfExists $defaultAuthFile
        Remove-IfExists $defaultEnvStateFile
        Remove-IfExists $defaultDebugLog
        Remove-IfExists $renamedAuthFile
        Remove-IfExists $renamedEnvStateFile
        Remove-IfExists $renamedDebugLog
    }

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

    $nestedPort = Get-FreePort
    $env:CDO_AUTH_STATE_PATH = $nestedAuthFile
    $env:CDO_ENV_STATE_PATH = $nestedEnvStateFile
    $env:CDO_DEBUG_LOG_PATH = $nestedDebugLog
    $nestedServerProcess = Start-Process -FilePath 'php' `
        -ArgumentList '-S', ("127.0.0.1:" + $nestedPort), '-t', 'public_html/nested-docroot' `
        -WorkingDirectory $repoRoot `
        -PassThru
    $env:CDO_AUTH_STATE_PATH = $authFile
    $env:CDO_ENV_STATE_PATH = $envStateFile
    $env:CDO_DEBUG_LOG_PATH = $debugLog

    try {
        Start-Sleep -Seconds 2
        $nestedDocrootUrl = "http://127.0.0.1:$nestedPort/cdo.php"
        $nestedProbe = Invoke-SmokeRequest -Url $nestedDocrootUrl -Method 'GET'
        Assert-True ($nestedProbe.StatusCode -eq 200) 'Nested document root entrypoint should return 200.'

        $nestedRequestAuth = Invoke-JsonRpcTool -Url $nestedDocrootUrl -Id 18 -ToolName 'request_auth' -Arguments @{
            agentName = 'nested-docroot-smoke-agent'
        }
        $nestedRequestAuthJson = $nestedRequestAuth.Content | ConvertFrom-Json
        $nestedApprovalUrl = [string] $nestedRequestAuthJson.result.structuredContent.approvalUrl
        $nestedBearerToken = [string] $nestedRequestAuthJson.result.structuredContent.bearerToken
        $nestedApprovalPost = Invoke-SmokeRequest -Url $nestedApprovalUrl -Method 'POST' -Headers @{
            'Content-Type' = 'application/x-www-form-urlencoded'
        } -Body 'approve=yes'
        Assert-True ($nestedApprovalPost.StatusCode -eq 200) 'Nested document root approval should return 200.'

        $nestedGetEnvPath = Invoke-JsonRpcTool -Url $nestedDocrootUrl -Id 19 -ToolName 'get_env_path' -BearerToken $nestedBearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
        $nestedGetEnvPathJson = $nestedGetEnvPath.Content | ConvertFrom-Json
        $nestedGetEnvPathData = $nestedGetEnvPathJson.result.structuredContent
        $nestedEnvPath = [string] $nestedGetEnvPathData.envPath
        Assert-True ($nestedGetEnvPathData.available) 'Nested document root get_env_path should be available.'
        Assert-True ($nestedEnvPath.EndsWith('production.env')) 'Nested document root get_env_path should return production.env.'

        $publicHtmlFullPath = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'public_html'))
        $publicHtmlPrefix = $publicHtmlFullPath.TrimEnd([char[]]@([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)) + [System.IO.Path]::DirectorySeparatorChar
        $envSecretsPrefix = [System.IO.Path]::GetFullPath($envSecretsRoot).TrimEnd([char[]]@([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)) + [System.IO.Path]::DirectorySeparatorChar
        $nestedEnvFullPath = [System.IO.Path]::GetFullPath($nestedEnvPath)
        $nestedEnvComparePath = $nestedEnvFullPath
        $publicHtmlComparePrefix = $publicHtmlPrefix
        $envSecretsComparePrefix = $envSecretsPrefix

        if (-not (Test-PosixFileModes)) {
            $nestedEnvComparePath = $nestedEnvComparePath.ToLowerInvariant()
            $publicHtmlComparePrefix = $publicHtmlComparePrefix.ToLowerInvariant()
            $envSecretsComparePrefix = $envSecretsComparePrefix.ToLowerInvariant()
        }

        Assert-True (-not $nestedEnvComparePath.StartsWith($publicHtmlComparePrefix)) 'Nested document root get_env_path should not return a path under public_html.'
        Assert-True ($nestedEnvComparePath.StartsWith($envSecretsComparePrefix)) 'Nested document root get_env_path should return the repo-level .cdo-secrets path.'
    } finally {
        if ($nestedServerProcess -and -not $nestedServerProcess.HasExited) {
            Stop-Process -Id $nestedServerProcess.Id -ErrorAction SilentlyContinue
        }

        $nestedServerProcess = $null
        Remove-IfExists $nestedAuthFile
        Remove-IfExists $nestedEnvStateFile
        Remove-IfExists $nestedDebugLog
    }

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
    Assert-True (-not ($toolNames -contains 'get_env_path')) 'tools/list should not expose get_env_path before auth.'
    Assert-True (-not ($toolNames -contains 'request_env_upload')) 'tools/list should not expose request_env_upload before auth.'
    Assert-True (-not ($toolNames -contains 'get_runtime_info')) 'tools/list should not expose get_runtime_info before auth.'
    Assert-True (-not ($toolNames -contains 'stat_path')) 'tools/list should not expose stat_path before auth.'
    Assert-True (-not ($toolNames -contains 'hash_file')) 'tools/list should not expose hash_file before auth.'
    Assert-True (-not ($toolNames -contains 'copy_path')) 'tools/list should not expose copy_path before auth.'

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

    $unauthorizedGetEnvPath = Invoke-JsonRpcTool -Url $mcpUrl -Id 118 -ToolName 'get_env_path'
    Assert-True ($unauthorizedGetEnvPath.StatusCode -eq 200) 'Unauthorized get_env_path should still return JSON-RPC.'
    $unauthorizedGetEnvPathJson = $unauthorizedGetEnvPath.Content | ConvertFrom-Json
    Assert-True ($unauthorizedGetEnvPathJson.error.code -eq -32001) 'Unauthorized get_env_path should return an auth error.'

    $unauthorizedRequestEnvUpload = Invoke-JsonRpcTool -Url $mcpUrl -Id 119 -ToolName 'request_env_upload'
    Assert-True ($unauthorizedRequestEnvUpload.StatusCode -eq 200) 'Unauthorized request_env_upload should still return JSON-RPC.'
    $unauthorizedRequestEnvUploadJson = $unauthorizedRequestEnvUpload.Content | ConvertFrom-Json
    Assert-True ($unauthorizedRequestEnvUploadJson.error.code -eq -32001) 'Unauthorized request_env_upload should return an auth error.'

    $unauthorizedRuntimeInfo = Invoke-JsonRpcTool -Url $mcpUrl -Id 120 -ToolName 'get_runtime_info'
    Assert-True ($unauthorizedRuntimeInfo.StatusCode -eq 200) 'Unauthorized get_runtime_info should still return JSON-RPC.'
    $unauthorizedRuntimeInfoJson = $unauthorizedRuntimeInfo.Content | ConvertFrom-Json
    Assert-True ($unauthorizedRuntimeInfoJson.error.code -eq -32001) 'Unauthorized get_runtime_info should return an auth error.'

    $unauthorizedStatPath = Invoke-JsonRpcTool -Url $mcpUrl -Id 127 -ToolName 'stat_path' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
    }
    Assert-True ($unauthorizedStatPath.StatusCode -eq 200) 'Unauthorized stat_path should still return JSON-RPC.'
    $unauthorizedStatPathJson = $unauthorizedStatPath.Content | ConvertFrom-Json
    Assert-True ($unauthorizedStatPathJson.error.code -eq -32001) 'Unauthorized stat_path should return an auth error.'

    $unauthorizedHashFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 128 -ToolName 'hash_file' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
    }
    Assert-True ($unauthorizedHashFile.StatusCode -eq 200) 'Unauthorized hash_file should still return JSON-RPC.'
    $unauthorizedHashFileJson = $unauthorizedHashFile.Content | ConvertFrom-Json
    Assert-True ($unauthorizedHashFileJson.error.code -eq -32001) 'Unauthorized hash_file should return an auth error.'

    $unauthorizedCopyPath = Invoke-JsonRpcTool -Url $mcpUrl -Id 129 -ToolName 'copy_path' -Arguments @{
        from = 'smoke-fixture/alpha.txt'
        to = 'smoke-fixture/alpha-copy.txt'
    }
    Assert-True ($unauthorizedCopyPath.StatusCode -eq 200) 'Unauthorized copy_path should still return JSON-RPC.'
    $unauthorizedCopyPathJson = $unauthorizedCopyPath.Content | ConvertFrom-Json
    Assert-True ($unauthorizedCopyPathJson.error.code -eq -32001) 'Unauthorized copy_path should return an auth error.'

    $serverStatusBeforeAuth = Invoke-JsonRpcTool -Url $mcpUrl -Id 40 -ToolName 'server_status'
    $serverStatusBeforeAuthJson = $serverStatusBeforeAuth.Content | ConvertFrom-Json
    $serverStatusBeforeAuthData = $serverStatusBeforeAuthJson.result.structuredContent
    Assert-True (-not $serverStatusBeforeAuthData.authorized) 'server_status should report unauthorized before auth.'
    Assert-True (-not $serverStatusBeforeAuthData.authorizationHeaderPresent) 'server_status should report missing Authorization before auth.'
    Assert-True (-not $serverStatusBeforeAuthData.authConfigured) 'server_status should report auth not configured before request_auth.'
    Assert-True ($serverStatusBeforeAuthData.authState -eq 'not_configured') 'server_status should report not_configured before request_auth.'
    Assert-True ($serverStatusBeforeAuthData.authReason -eq 'missing_state') 'server_status should explain that auth state is missing before request_auth.'
    Assert-True ($serverStatusBeforeAuthData.endpoint -eq $fileUrl) 'server_status should expose the exact endpoint URL.'
    Assert-True ($serverStatusBeforeAuthData.agentGuide.endpoint -eq $fileUrl) 'server_status agentGuide should use the exact endpoint URL.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('"agentGuide"')) 'server_status should include an AI-agent guide.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('SSE transport is not implemented')) 'server_status agentGuide should explain that SSE transport is not implemented.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('OAuth/OIDC discovery endpoints are not implemented')) 'server_status agentGuide should explain that OAuth discovery is not implemented.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('agent-a.php')) 'server_status agentGuide should explain the multi-agent copy model.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('recursive delete is not implemented')) 'server_status agentGuide should document recursive delete limitations.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('Rename overwrite/replace is not implemented')) 'server_status agentGuide should document rename replacement limitations.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('get_env_path')) 'server_status agentGuide should mention env path tools.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('request_env_upload')) 'server_status agentGuide should mention env upload tools.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('get_runtime_info')) 'server_status agentGuide should mention runtime info tools.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('phpinfo()')) 'server_status agentGuide should explain runtime info is not raw phpinfo output.'
    Assert-True ($serverStatusBeforeAuth.Content.Contains('operating system environment variables')) 'server_status agentGuide should explain that CDO does not set OS environment variables.'

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
    Assert-True ($approvalPost.Content.Contains((ConvertFrom-Utf8Base64 '5om/6KqN5a6M5LqG'))) 'Approval POST should render the success page.'
    Assert-True ($approvalPost.Content.Contains('smoke-agent')) 'Approval success should show the approved agent name.'
    Assert-True ($approvalPost.Content.Contains((ConvertFrom-Utf8Base64 '44Gn44GN44Gf'))) 'Approval success should tell the user what to report to the agent.'
    Assert-True ($approvalPost.Content.Contains($contextHint)) 'Approval success should show the context hint.'
    Assert-True ($approvalPost.Content.Contains('UTC')) 'Approval success should show the approval timestamp.'
    Assert-True (-not $approvalPost.Content.Contains($bearerToken)) 'Approval success should not expose the bearer token.'
    Assert-True (-not $approvalPost.Content.Contains($approvalSecret)) 'Approval success should not expose the approval secret.'

    $htmlAfterApproval = Invoke-SmokeRequest -Url $rootUrl -Method 'GET'
    Assert-True ($htmlAfterApproval.StatusCode -eq 200) 'GET / after approval should return 200.'
    Assert-True ($htmlAfterApproval.Content.Contains('approved')) 'HTML response should report approved auth state after approval.'
    Assert-True ($htmlAfterApproval.Content.Contains('smoke-agent')) 'HTML response should show the approved agent name.'
    Assert-True ($htmlAfterApproval.Content.Contains($contextHint)) 'HTML response should show the context hint after approval.'
    Assert-True ($htmlAfterApproval.Content.Contains((ConvertFrom-Utf8Base64 '5om/6KqN5pel5pmC'))) 'HTML response should show the approval timestamp after approval.'
    Assert-True ($htmlAfterApproval.Content.Contains((ConvertFrom-Utf8Base64 '5pyA57WC5Yip55So5pel5pmC'))) 'HTML response should show the last-used timestamp after approval.'
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
    Assert-True ($toolNamesAuthorized -contains 'get_env_path') 'Authorized tools/list should expose get_env_path.'
    Assert-True ($toolNamesAuthorized -contains 'request_env_upload') 'Authorized tools/list should expose request_env_upload.'
    Assert-True ($toolNamesAuthorized -contains 'get_runtime_info') 'Authorized tools/list should expose get_runtime_info.'
    Assert-True ($toolNamesAuthorized -contains 'stat_path') 'Authorized tools/list should expose stat_path.'
    Assert-True ($toolNamesAuthorized -contains 'hash_file') 'Authorized tools/list should expose hash_file.'
    Assert-True ($toolNamesAuthorized -contains 'copy_path') 'Authorized tools/list should expose copy_path.'
    $envToolNamesAuthorized = @($toolNamesAuthorized | Where-Object { $_ -like '*env*' })
    Assert-True ($envToolNamesAuthorized.Count -eq 2) 'Authorized tools/list should expose exactly two env tools.'

    $serverStatusAfterAuth = Invoke-JsonRpcTool -Url $mcpUrl -Id 41 -ToolName 'server_status' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $serverStatusAfterAuthJson = $serverStatusAfterAuth.Content | ConvertFrom-Json
    $serverStatusAfterAuthData = $serverStatusAfterAuthJson.result.structuredContent
    Assert-True ($serverStatusAfterAuthData.authorized) 'server_status should report authorized with the correct bearer token.'
    Assert-True ($serverStatusAfterAuthData.inspectorBearerHeaderPresent) 'server_status should report X-CDO-Bearer-Token header presence after auth.'
    Assert-True ($serverStatusAfterAuthData.bearerHeaderSource -eq 'x-cdo-bearer-token') 'server_status should report the inspector header source.'
    Assert-True ($serverStatusAfterAuthData.authReason -eq 'authorized') 'server_status should explain why auth succeeded.'
    Assert-True ($serverStatusAfterAuthData.agentName -eq 'smoke-agent') 'Authenticated server_status should expose safe agent name metadata.'
    Assert-True ($serverStatusAfterAuthData.contextHint -eq $contextHint) 'Authenticated server_status should expose safe context hint metadata.'

    $envSecretText = 'CDO_SMOKE_SECRET=super-secret-env-value'
    $runtimeInfo = Invoke-JsonRpcTool -Url $mcpUrl -Id 126 -ToolName 'get_runtime_info' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $runtimeInfoJson = $runtimeInfo.Content | ConvertFrom-Json
    $runtimeInfoData = $runtimeInfoJson.result.structuredContent
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $runtimeInfoData.php.version)) 'get_runtime_info should return PHP version.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $runtimeInfoData.php.sapi)) 'get_runtime_info should return PHP SAPI.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $runtimeInfoData.php.osFamily)) 'get_runtime_info should return PHP OS family.'
    Assert-True ($null -ne $runtimeInfoData.configurationFiles) 'get_runtime_info should return configuration file metadata.'
    Assert-True ($null -ne $runtimeInfoData.userIniSupport) 'get_runtime_info should return user.ini support metadata.'
    Assert-True ($null -ne $runtimeInfoData.htaccessDirectiveSupport) 'get_runtime_info should return .htaccess directive support metadata.'
    Assert-True ($null -ne $runtimeInfoData.directives) 'get_runtime_info should return PHP directives.'
    Assert-True ($null -ne $runtimeInfoData.extensions) 'get_runtime_info should return extension metadata.'
    Assert-True ($runtimeInfoData.extensions.loaded.Count -gt 0) 'get_runtime_info should return loaded extension names.'
    $runtimeDirectiveNames = @($runtimeInfoData.directives.PSObject.Properties | ForEach-Object { [string] $_.Name })
    foreach ($runtimeDirectiveName in @('memory_limit', 'upload_max_filesize', 'post_max_size', 'open_basedir', 'disable_functions')) {
        if ($runtimeDirectiveNames -contains $runtimeDirectiveName) {
            $runtimeDirective = $runtimeInfoData.directives.$runtimeDirectiveName
            $runtimeDirectiveProps = @($runtimeDirective.PSObject.Properties | ForEach-Object { [string] $_.Name })
            Assert-True ($runtimeDirectiveProps -contains 'effectiveValue') "get_runtime_info should return effectiveValue for $runtimeDirectiveName."
            Assert-True ($runtimeDirectiveProps -contains 'globalValue') "get_runtime_info should return globalValue for $runtimeDirectiveName."
            Assert-True ($runtimeDirectiveProps -contains 'overridden') "get_runtime_info should return overridden for $runtimeDirectiveName."
            Assert-True ($runtimeDirectiveProps -contains 'accessRaw') "get_runtime_info should return accessRaw for $runtimeDirectiveName."
            Assert-True ($runtimeDirectiveProps -contains 'accessLabels') "get_runtime_info should return accessLabels for $runtimeDirectiveName."
            Assert-True ($runtimeDirectiveProps -contains 'settableVia') "get_runtime_info should return settableVia for $runtimeDirectiveName."
        }
    }
    $runtimeCapabilityNames = @($runtimeInfoData.extensions.capabilities.PSObject.Properties | ForEach-Object { [string] $_.Name })
    foreach ($runtimeCapabilityName in @('curl', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'zip')) {
        Assert-True ($runtimeCapabilityNames -contains $runtimeCapabilityName) "get_runtime_info should return the $runtimeCapabilityName capability."
        Assert-True (($runtimeInfoData.extensions.capabilities.$runtimeCapabilityName -eq $true) -or ($runtimeInfoData.extensions.capabilities.$runtimeCapabilityName -eq $false)) "get_runtime_info should return a boolean $runtimeCapabilityName capability."
    }
    Assert-True (-not $runtimeInfo.Content.Contains('$_ENV')) 'get_runtime_info should not expose $_ENV.'
    Assert-True (-not $runtimeInfo.Content.Contains('$_SERVER')) 'get_runtime_info should not expose $_SERVER.'
    Assert-True (-not $runtimeInfo.Content.Contains('HTTP_COOKIE')) 'get_runtime_info should not expose cookie headers.'
    Assert-True (-not $runtimeInfo.Content.Contains('Authorization')) 'get_runtime_info should not expose Authorization headers.'
    Assert-True (-not $runtimeInfo.Content.Contains($bearerToken)) 'get_runtime_info should not expose the bearer token.'
    Assert-True (-not $runtimeInfo.Content.Contains($approvalSecret)) 'get_runtime_info should not expose the approval secret.'
    Assert-True (-not $runtimeInfo.Content.Contains($envSecretText)) 'get_runtime_info should not expose env contents.'

    $getEnvPath = Invoke-JsonRpcTool -Url $mcpUrl -Id 121 -ToolName 'get_env_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $getEnvPathJson = $getEnvPath.Content | ConvertFrom-Json
    $getEnvPathData = $getEnvPathJson.result.structuredContent
    $envPath = [string] $getEnvPathData.envPath
    Assert-True ($getEnvPathData.available) 'get_env_path should report an available env path in smoke.'
    Assert-True (-not $getEnvPathData.uploaded) 'get_env_path should report not uploaded before browser upload.'
    Assert-True ([string]::IsNullOrWhiteSpace([string] $getEnvPathData.uploadedAt)) 'get_env_path should not report uploadedAt before upload.'
    Assert-True ($getEnvPathData.outsideDocumentRoot) 'get_env_path should place env files outside the document root.'
    Assert-True ($getEnvPathData.readableByPhp) 'get_env_path should report the env path as readable by PHP.'
    Assert-True ($getEnvPathData.writable) 'get_env_path should report the env path as writable.'
    Assert-True ($envPath.EndsWith('production.env')) 'get_env_path should return a production.env target.'
    Assert-True ($envPath.Contains('.cdo-secrets')) 'get_env_path should return the CDO secrets directory.'
    $documentRootFullPath = [System.IO.Path]::GetFullPath((Join-Path $repoRoot 'public_html'))
    $envFullPath = [System.IO.Path]::GetFullPath($envPath)
    $documentRootPrefix = $documentRootFullPath.TrimEnd([char[]]@([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)) + [System.IO.Path]::DirectorySeparatorChar
    $envComparePath = $envFullPath
    $documentRootComparePrefix = $documentRootPrefix

    if (-not (Test-PosixFileModes)) {
        $envComparePath = $envComparePath.ToLowerInvariant()
        $documentRootComparePrefix = $documentRootComparePrefix.ToLowerInvariant()
    }

    Assert-True (-not $envComparePath.StartsWith($documentRootComparePrefix)) 'get_env_path should not return a path under public_html.'
    Assert-True (-not $getEnvPath.Content.Contains($envSecretText)) 'get_env_path should not return env contents.'
    Assert-True (-not $getEnvPath.Content.Contains('OPENAI_API_KEY')) 'get_env_path should not return env key names.'

    $requestEnvUpload = Invoke-JsonRpcTool -Url $mcpUrl -Id 122 -ToolName 'request_env_upload' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $requestEnvUploadJson = $requestEnvUpload.Content | ConvertFrom-Json
    $requestEnvUploadData = $requestEnvUploadJson.result.structuredContent
    $envUploadUrl = [string] $requestEnvUploadData.uploadUrl
    Assert-True ($requestEnvUploadData.status -eq 'pending_upload') 'request_env_upload should create a pending upload.'
    Assert-True ($requestEnvUploadData.envPath -eq $envPath) 'request_env_upload should use the same env path as get_env_path.'
    Assert-True ($requestEnvUploadData.available) 'request_env_upload should report availability.'
    Assert-True (-not [string]::IsNullOrWhiteSpace($envUploadUrl)) 'request_env_upload should return an upload URL.'
    Assert-True ($envUploadUrl.Contains('cdo_env_upload=')) 'request_env_upload should return a browser upload token URL.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $requestEnvUploadData.expiresAt)) 'request_env_upload should return expiresAt.'
    Assert-True (-not $requestEnvUpload.Content.Contains($envSecretText)) 'request_env_upload should not return env contents.'
    $envUploadToken = ($envUploadUrl -split 'cdo_env_upload=')[1]

    $uploadGet = Invoke-SmokeRequest -Url $envUploadUrl -Method 'GET'
    Assert-True ($uploadGet.StatusCode -eq 200) 'Env upload GET should return 200.'
    Assert-True ($uploadGet.Content.Contains('production.env')) 'Env upload GET should explain the fixed production.env destination.'
    Assert-True ($uploadGet.Content.Contains('envFile')) 'Env upload GET should render the file upload field.'
    Assert-True ($uploadGet.Content.Contains($envPath)) 'Env upload GET should show the chosen env path.'
    Assert-True (-not $uploadGet.Content.Contains($envSecretText)) 'Env upload GET should not expose env contents.'

    Set-Content -Path $envUploadFile -Value $envSecretText -NoNewline
    $uploadPost = Invoke-MultipartFileUpload -Url $envUploadUrl -FilePath $envUploadFile
    Assert-True ($uploadPost.StatusCode -eq 200) 'Env upload POST should return 200.'
    Assert-True ($uploadPost.Content.Contains('production.env')) 'Env upload POST should confirm the fixed production.env destination.'
    Assert-True ($uploadPost.Content.Contains($envPath)) 'Env upload POST should show the chosen env path.'
    Assert-True (-not $uploadPost.Content.Contains($envSecretText)) 'Env upload POST should not echo env contents.'
    Assert-True (Test-Path $envPath) 'Env upload should create the production.env file.'
    Assert-True ((Get-Content -Raw $envPath) -eq $envSecretText) 'Env upload should save the uploaded content to production.env.'
    $envFileMode = Get-PosixFileMode $envPath

    if ($envFileMode -ne $null) {
        Assert-True ($envFileMode -eq '0600') 'Env upload should set production.env mode to 0600 on POSIX.'
    }

    Set-Content -Path $envUploadFile -Value 'CDO_SMOKE_SECRET=second-value' -NoNewline
    $reuseUploadPost = Invoke-MultipartFileUpload -Url $envUploadUrl -FilePath $envUploadFile
    Assert-True ($reuseUploadPost.StatusCode -eq 403) 'Env upload token should be one-time and reject reuse.'
    Assert-True ((Get-Content -Raw $envPath) -eq $envSecretText) 'Reusing an env upload token should not overwrite production.env.'

    $getEnvPathAfterUpload = Invoke-JsonRpcTool -Url $mcpUrl -Id 124 -ToolName 'get_env_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token'
    $getEnvPathAfterUploadJson = $getEnvPathAfterUpload.Content | ConvertFrom-Json
    Assert-True ($getEnvPathAfterUploadJson.result.structuredContent.uploaded) 'get_env_path should report uploaded after browser upload.'
    Assert-True ($getEnvPathAfterUploadJson.result.structuredContent.envPath -eq $envPath) 'get_env_path should continue returning the uploaded path.'
    Assert-True (-not [string]::IsNullOrWhiteSpace([string] $getEnvPathAfterUploadJson.result.structuredContent.uploadedAt)) 'get_env_path should report uploadedAt after browser upload.'
    Assert-True (-not $getEnvPathAfterUpload.Content.Contains($envSecretText)) 'get_env_path after upload should not return env contents.'
    Assert-True (-not $getEnvPathAfterUpload.Content.Contains('OPENAI_API_KEY')) 'get_env_path after upload should not return env key names.'

    $readEnvAbsolute = Invoke-JsonRpcTool -Url $mcpUrl -Id 125 -ToolName 'read_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = $envPath
    }
    $readEnvAbsoluteJson = $readEnvAbsolute.Content | ConvertFrom-Json
    Assert-True ($readEnvAbsoluteJson.error.code -eq -32602) 'read_file should reject the external env path.'

    $envStateContent = Get-Content -Raw $envStateFile
    $envStateJson = $envStateContent | ConvertFrom-Json
    Assert-True ($envStateJson.envPath -eq $envPath) 'Env state should store the env path metadata.'
    Assert-True (-not $envStateContent.Contains($envSecretText)) 'Env state should not store env contents.'
    Assert-True (-not $envStateContent.Contains($envUploadToken)) 'Env state should not store the plain env upload token.'

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

    $statFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 130 -ToolName 'stat_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
    }
    $statFileJson = $statFile.Content | ConvertFrom-Json
    $statFileData = $statFileJson.result.structuredContent
    Assert-True ($statFileData.exists) 'stat_path should report existing files.'
    Assert-True ($statFileData.type -eq 'file') 'stat_path should report file type.'
    Assert-True ($statFileData.size -eq (Get-Item $fixtureFile).Length) 'stat_path should report file size.'
    Assert-True ($statFileData.mtime -gt 0) 'stat_path should report file mtime.'
    Assert-True ($statFileData.readable) 'stat_path should report readable files.'

    $statDirectory = Invoke-JsonRpcTool -Url $mcpUrl -Id 131 -ToolName 'stat_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/nested'
    }
    $statDirectoryJson = $statDirectory.Content | ConvertFrom-Json
    Assert-True ($statDirectoryJson.result.structuredContent.exists) 'stat_path should report existing directories.'
    Assert-True ($statDirectoryJson.result.structuredContent.type -eq 'dir') 'stat_path should report directory type.'

    $statMissing = Invoke-JsonRpcTool -Url $mcpUrl -Id 132 -ToolName 'stat_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/missing.txt'
    }
    $statMissingJson = $statMissing.Content | ConvertFrom-Json
    Assert-True (-not $statMissingJson.result.structuredContent.exists) 'stat_path should report missing paths without failing.'
    Assert-True ($statMissingJson.result.structuredContent.type -eq 'missing') 'stat_path should report missing type.'

    $statInternal = Invoke-JsonRpcTool -Url $mcpUrl -Id 133 -ToolName 'stat_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = '.cdo_secret.txt'
    }
    $statInternalJson = $statInternal.Content | ConvertFrom-Json
    Assert-True ($statInternalJson.error.code -eq -32602) 'stat_path should reject internal control files.'

    $hashFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 134 -ToolName 'hash_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/alpha.txt'
    }
    $hashFileJson = $hashFile.Content | ConvertFrom-Json
    $hashFileData = $hashFileJson.result.structuredContent
    $expectedAlphaHash = Get-Sha256FileHash -Path $fixtureFile
    $alphaFileContent = Get-Content -Raw $fixtureFile
    Assert-True ($hashFileData.algorithm -eq 'sha256') 'hash_file should report sha256.'
    Assert-True ($hashFileData.hash -eq $expectedAlphaHash) 'hash_file should report the expected SHA-256 hash.'
    Assert-True (-not $hashFile.Content.Contains($alphaFileContent)) 'hash_file should not return file contents.'

    $hashDirectory = Invoke-JsonRpcTool -Url $mcpUrl -Id 135 -ToolName 'hash_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/nested'
    }
    $hashDirectoryJson = $hashDirectory.Content | ConvertFrom-Json
    Assert-True ($hashDirectoryJson.error.code -eq -32602) 'hash_file should reject directories.'

    $copyPath = Invoke-JsonRpcTool -Url $mcpUrl -Id 136 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-fixture/alpha.txt'
        to = 'smoke-fixture/alpha-copy.txt'
    }
    $copyPathJson = $copyPath.Content | ConvertFrom-Json
    $copyPathData = $copyPathJson.result.structuredContent
    $copyDestination = Join-Path $fixtureDir 'alpha-copy.txt'
    Assert-True ($copyPathData.from -eq 'smoke-fixture/alpha.txt') 'copy_path should report the source path.'
    Assert-True ($copyPathData.to -eq 'smoke-fixture/alpha-copy.txt') 'copy_path should report the destination path.'
    Assert-True ($copyPathData.bytesCopied -eq (Get-Item $fixtureFile).Length) 'copy_path should report copied bytes.'
    Assert-True (-not $copyPathData.overwritten) 'copy_path should report new destinations as not overwritten.'
    Assert-True ((Get-Content -Raw $copyDestination) -eq $alphaFileContent) 'copy_path should copy file contents server-side.'
    Assert-True (-not $copyPath.Content.Contains($alphaFileContent)) 'copy_path should not return file contents.'

    $hashCopiedFile = Invoke-JsonRpcTool -Url $mcpUrl -Id 137 -ToolName 'hash_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-fixture/alpha-copy.txt'
    }
    $hashCopiedFileJson = $hashCopiedFile.Content | ConvertFrom-Json
    Assert-True ($hashCopiedFileJson.result.structuredContent.hash -eq $expectedAlphaHash) 'hash_file should verify copied file integrity.'

    $copyExistingDestination = Invoke-JsonRpcTool -Url $mcpUrl -Id 138 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-fixture/alpha.txt'
        to = 'smoke-fixture/alpha-copy.txt'
    }
    $copyExistingDestinationJson = $copyExistingDestination.Content | ConvertFrom-Json
    Assert-True ($copyExistingDestinationJson.error.code -eq -32602) 'copy_path should reject existing destinations without overwrite.'

    $copyOverwrite = Invoke-JsonRpcTool -Url $mcpUrl -Id 139 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-fixture/.beta.txt'
        to = 'smoke-fixture/alpha-copy.txt'
        overwrite = $true
    }
    $copyOverwriteJson = $copyOverwrite.Content | ConvertFrom-Json
    Assert-True ($copyOverwriteJson.result.structuredContent.overwritten) 'copy_path should support explicit overwrite.'
    Assert-True ((Get-Content -Raw $copyDestination) -eq (Get-Content -Raw $fixtureDotFile)) 'copy_path overwrite should replace destination contents.'

    $copyDirectorySource = Invoke-JsonRpcTool -Url $mcpUrl -Id 140 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-fixture/nested'
        to = 'smoke-fixture/nested-copy'
    }
    $copyDirectorySourceJson = $copyDirectorySource.Content | ConvertFrom-Json
    Assert-True ($copyDirectorySourceJson.error.code -eq -32602) 'copy_path should reject directory sources.'

    $copyInternalSource = Invoke-JsonRpcTool -Url $mcpUrl -Id 141 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = '.cdo_secret.txt'
        to = 'smoke-fixture/internal-copy.txt'
    }
    $copyInternalSourceJson = $copyInternalSource.Content | ConvertFrom-Json
    Assert-True ($copyInternalSourceJson.error.code -eq -32602) 'copy_path should reject internal control files as sources.'

    $copyEntrypointSource = Invoke-JsonRpcTool -Url $mcpUrl -Id 142 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'cdo.php'
        to = 'smoke-fixture/cdo-copy.php'
    }
    $copyEntrypointSourceJson = $copyEntrypointSource.Content | ConvertFrom-Json
    Assert-True ($copyEntrypointSourceJson.error.code -eq -32602) 'copy_path should reject the current entrypoint file as the source.'

    $copyMissingParent = Invoke-JsonRpcTool -Url $mcpUrl -Id 143 -ToolName 'copy_path' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        from = 'smoke-fixture/alpha.txt'
        to = 'smoke-copy-missing/alpha.txt'
    }
    $copyMissingParentJson = $copyMissingParent.Content | ConvertFrom-Json
    Assert-True ($copyMissingParentJson.error.code -eq -32602) 'copy_path should reject missing destination parents.'

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
    $writtenFilePath = Join-Path $repoRoot 'public_html\smoke-write\new.txt'
    $expectedNewFileMode = Get-ExpectedNewFileMode
    $newFileMode = Get-PosixFileMode $writtenFilePath

    if ($newFileMode -ne $null) {
        Assert-True ($newFileMode -eq $expectedNewFileMode) 'write_file should create new files using the server umask.'
    }

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
    Set-PosixFileMode $writtenFilePath '0640'
    $modeBeforeOverwrite = Get-PosixFileMode $writtenFilePath

    $writeWithOverwrite = Invoke-JsonRpcTool -Url $mcpUrl -Id 56 -ToolName 'write_file' -BearerToken $bearerToken -BearerHeaderName 'X-CDO-Bearer-Token' -Arguments @{
        path = 'smoke-write/new.txt'
        content = 'second'
        encoding = 'utf-8'
        overwrite = $true
    }
    $writeWithOverwriteJson = $writeWithOverwrite.Content | ConvertFrom-Json
    Assert-True ($writeWithOverwriteJson.result.structuredContent.overwritten) 'write_file should report explicit overwrites.'
    $modeAfterOverwrite = Get-PosixFileMode $writtenFilePath

    if ($modeBeforeOverwrite -ne $null) {
        Assert-True ($modeAfterOverwrite -eq $modeBeforeOverwrite) 'write_file should preserve existing POSIX mode when overwriting.'
    }

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
    Assert-True (-not $debugLogContent.Contains('CDO_SMOKE_SECRET=super-secret-env-value')) 'Debug log should not contain uploaded env contents.'

    Write-Output 'Smoke OK'
} finally {
    if ($renamedNoOverrideServerProcess -and -not $renamedNoOverrideServerProcess.HasExited) {
        Stop-Process -Id $renamedNoOverrideServerProcess.Id -ErrorAction SilentlyContinue
    }

    if ($nestedServerProcess -and -not $nestedServerProcess.HasExited) {
        Stop-Process -Id $nestedServerProcess.Id -ErrorAction SilentlyContinue
    }

    if ($serverProcess -and -not $serverProcess.HasExited) {
        Stop-Process -Id $serverProcess.Id -ErrorAction SilentlyContinue
    }

    if ($null -eq $previousAuthPath -or $previousAuthPath -eq '') {
        Remove-Item Env:\CDO_AUTH_STATE_PATH -ErrorAction SilentlyContinue
    } else {
        $env:CDO_AUTH_STATE_PATH = $previousAuthPath
    }

    if ($null -eq $previousEnvPath -or $previousEnvPath -eq '') {
        Remove-Item Env:\CDO_ENV_STATE_PATH -ErrorAction SilentlyContinue
    } else {
        $env:CDO_ENV_STATE_PATH = $previousEnvPath
    }

    if ($null -eq $previousDebugPath -or $previousDebugPath -eq '') {
        Remove-Item Env:\CDO_DEBUG_LOG_PATH -ErrorAction SilentlyContinue
    } else {
        $env:CDO_DEBUG_LOG_PATH = $previousDebugPath
    }

    Remove-IfExists $authFile
    Remove-IfExists $envStateFile
    Remove-IfExists $debugLog
    Remove-IfExists $nestedAuthFile
    Remove-IfExists $nestedEnvStateFile
    Remove-IfExists $nestedDebugLog
    Remove-IfExists $envSecretsRoot
    Remove-IfExists $renamedEntrypoint
    Remove-IfExists $defaultAuthFile
    Remove-IfExists $defaultEnvStateFile
    Remove-IfExists $defaultDebugLog
    Remove-IfExists $renamedAuthFile
    Remove-IfExists $renamedEnvStateFile
    Remove-IfExists $renamedDebugLog
    Remove-IfExists $subdirEntrypointDir
    Remove-IfExists $nestedDocrootDir
    Remove-IfExists $visibleDotFile
    Remove-IfExists $internalControlFile
    Remove-IfExists $fixtureDir
    Remove-IfExists $writeDir
    Remove-IfExists $recursiveWriteDir
    Remove-IfExists $deleteDir
    Remove-IfExists $renameDir
    Remove-IfExists $tmpDir
}
