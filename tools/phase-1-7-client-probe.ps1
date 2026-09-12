[CmdletBinding()]
param(
	[Parameter(Mandatory = $true)]
	[uri] $Endpoint,

	[switch] $Probe,
	[switch] $RequireDocker,
	[switch] $RequireClients
)

$ErrorActionPreference = 'Stop'

$ExpectedTools = @(
	'wp-auto-site-health',
	'wp-auto-site-info',
	'wp-auto-posts-search',
	'wp-auto-post-get',
	'wp-auto-pages-search',
	'wp-auto-page-get',
	'wp-auto-categories-list',
	'wp-auto-tags-list',
	'wp-auto-post-create-draft',
	'wp-auto-page-create-draft',
	'wp-auto-post-update',
	'wp-auto-page-update',
	'wp-auto-media-search',
	'wp-auto-media-get',
	'wp-auto-media-upload',
	'wp-auto-media-update',
	'wp-auto-media-set-featured',
	'wp-auto-media-import-url',
	'wp-auto-category-create',
	'wp-auto-tag-create',
	'wp-auto-taxonomy-assign',
	'wp-auto-seo-get',
	'wp-auto-seo-update'
)

function Get-CommandPresence {
	param(
		[string] $Name
	)

	return $null -ne (Get-Command $Name -ErrorAction SilentlyContinue)
}

function Assert-Endpoint {
	param(
		[uri] $Value
	)

	if ($Value.Scheme -notin @('http', 'https')) {
		throw 'Endpoint must use HTTP or HTTPS.'
	}
	if (-not [string]::IsNullOrWhiteSpace($Value.UserInfo)) {
		throw 'Endpoint must not embed credentials.'
	}
	if (-not [string]::IsNullOrWhiteSpace($Value.Query) -or -not [string]::IsNullOrWhiteSpace($Value.Fragment)) {
		throw 'Endpoint must not include a query string or fragment.'
	}

	$localHosts = @('localhost', '127.0.0.1', '::1')
	if ($Value.Scheme -eq 'http' -and $localHosts -notcontains $Value.Host) {
		throw 'Plain HTTP is allowed only for local development hosts.'
	}
}

function Test-DockerDesktop {
	if (-not (Get-CommandPresence -Name 'docker')) {
		if ($RequireDocker) {
			throw 'Docker CLI is not available.'
		}

		return 'MISSING'
	}

	try {
		$serverVersion = docker info --format '{{.ServerVersion}}' 2>$null
		if ([string]::IsNullOrWhiteSpace([string] $serverVersion)) {
			throw 'Docker server did not return a version.'
		}

		return ('PASS (' + ([string] $serverVersion).Trim() + ')')
	} catch {
		if ($RequireDocker) {
			throw 'Docker Desktop is not reachable.'
		}

		return 'UNREACHABLE'
	}
}

function Test-ClientCommands {
	$commands = @('codex', 'codebuddy', 'mcp-inspector', 'npx')
	$missing  = @($commands | Where-Object { -not (Get-CommandPresence -Name $_) })

	if ($RequireClients -and $missing.Count -gt 0) {
		throw ('Required client command(s) missing: ' + ($missing -join ', '))
	}

	if ($missing.Count -eq 0) {
		return 'PASS'
	}

	return ('PENDING (missing ' + ($missing -join ', ') + ')')
}

function Get-AuthHeaders {
	$authorization = [Environment]::GetEnvironmentVariable('WP_AUTO_MCP_AUTHORIZATION')
	if ([string]::IsNullOrWhiteSpace($authorization)) {
		throw 'WP_AUTO_MCP_AUTHORIZATION is not set for the probe process.'
	}

	return @{
		'Accept'                 = 'application/json, text/event-stream'
		'Content-Type'           = 'application/json'
		'Authorization'          = $authorization
	}
}

function Convert-McpResponse {
	param(
		[string] $Content
	)

	$trimmed = $Content.Trim()
	if ([string]::IsNullOrWhiteSpace($trimmed)) {
		return $null
	}

	if ($trimmed.StartsWith('{')) {
		return $trimmed | ConvertFrom-Json
	}

	$data = @(
		$trimmed -split "`r?`n" |
			Where-Object { $_ -like 'data:*' } |
			ForEach-Object { $_.Substring(5).Trim() } |
			Where-Object { $_ -and $_ -ne '[DONE]' }
	)

	if ($data.Count -eq 0) {
		throw 'MCP response did not contain a JSON or SSE data payload.'
	}

	return $data[-1] | ConvertFrom-Json
}

function Invoke-McpRequest {
	param(
		[string] $Method,
		[int] $Id,
		[hashtable] $Params,
		[string] $SessionId,
		[string] $ProtocolVersion
	)

	$headers = Get-AuthHeaders
	if (-not [string]::IsNullOrWhiteSpace($SessionId)) {
		$headers['Mcp-Session-Id'] = $SessionId
	}
	if (-not [string]::IsNullOrWhiteSpace($ProtocolVersion)) {
		$headers['Mcp-Protocol-Version'] = $ProtocolVersion
	}

	$payload = @{
		jsonrpc = '2.0'
		id      = $Id
		method  = $Method
		params  = $Params
	} | ConvertTo-Json -Depth 20 -Compress

	try {
		$response = Invoke-WebRequest -Uri $Endpoint -Method Post -Headers $headers -Body $payload -UseBasicParsing
	} catch {
		throw 'MCP request failed; response details were intentionally suppressed.'
	}

	$session = $response.Headers['Mcp-Session-Id']
	if ($session -is [array]) {
		$session = $session[0]
	}

	return [pscustomobject] @{
		Message    = Convert-McpResponse -Content ([string] $response.Content)
		SessionId  = [string] $session
		StatusCode = [int] $response.StatusCode
	}
}

Assert-Endpoint -Value $Endpoint
$dockerStatus = Test-DockerDesktop
$clientStatus = Test-ClientCommands
$endpointClass = if ($Endpoint.Scheme -eq 'http') { 'local-http' } else { 'https' }

if (-not $Probe) {
	[pscustomobject] @{
		EndpointClass = $endpointClass
		Docker        = $dockerStatus
		Clients       = $clientStatus
		Probe         = 'SKIPPED (use -Probe with WP_AUTO_MCP_AUTHORIZATION)'
	} | Format-List
	exit 0
}

$initialize = Invoke-McpRequest -Method 'initialize' -Id 1 -Params @{
	protocolVersion = '2025-06-18'
	capabilities    = @{}
	clientInfo      = @{
		name    = 'wepuu-phase-1-7-client-probe'
		version = '0.1.0'
	}
} -SessionId '' -ProtocolVersion ''

if ($initialize.StatusCode -ne 200 -or $null -eq $initialize.Message.result) {
	throw 'MCP initialize did not return a successful result.'
}

$negotiatedVersion = [string] $initialize.Message.result.protocolVersion
if ([string]::IsNullOrWhiteSpace($negotiatedVersion)) {
	throw 'MCP initialize did not return a protocol version.'
}

$sessionId = $initialize.SessionId
$initializedHeaders = Get-AuthHeaders
$initializedHeaders['Mcp-Protocol-Version'] = $negotiatedVersion
if (-not [string]::IsNullOrWhiteSpace($sessionId)) {
	$initializedHeaders['Mcp-Session-Id'] = $sessionId
}
$initializedPayload = @{
	jsonrpc = '2.0'
	method  = 'notifications/initialized'
	params  = @{}
} | ConvertTo-Json -Depth 10 -Compress
try {
	Invoke-WebRequest -Uri $Endpoint -Method Post -Headers $initializedHeaders -Body $initializedPayload -UseBasicParsing | Out-Null
} catch {
	throw 'MCP initialized notification failed; response details were intentionally suppressed.'
}

$toolsResponse = Invoke-McpRequest -Method 'tools/list' -Id 2 -Params @{} -SessionId $sessionId -ProtocolVersion $negotiatedVersion
if ($toolsResponse.StatusCode -ne 200 -or $null -eq $toolsResponse.Message.result.tools) {
	throw 'MCP tools/list did not return a successful result.'
}

$actualTools = @($toolsResponse.Message.result.tools | ForEach-Object { [string] $_.name })
if ($actualTools.Count -ne $ExpectedTools.Count) {
	throw ('Expected exactly ' + $ExpectedTools.Count + ' tools; received ' + $actualTools.Count + '.')
}

for ($index = 0; $index -lt $ExpectedTools.Count; $index++) {
	if ($actualTools[$index] -ne $ExpectedTools[$index]) {
		throw ('Tool order mismatch at index ' + $index + '.')
	}
}

[pscustomobject] @{
	EndpointClass   = $endpointClass
	Docker          = $dockerStatus
	Clients         = $clientStatus
	ProtocolVersion = $negotiatedVersion
	ToolCount       = $actualTools.Count
	ToolOrder       = 'PASS'
	Authentication  = 'PASS (header present; value not displayed)'
} | Format-List
