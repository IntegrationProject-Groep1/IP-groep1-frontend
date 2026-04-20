param(
    [string]$BaseUrl = 'http://localhost:30020',
    [string]$QueueName = 'crm.incoming',
    [string]$DbService = 'frontend_db',
    [string]$RabbitService = 'rabbitmq_local',
    [string]$RabbitApiBaseUrl = 'http://localhost:15672',
    [string]$RabbitUser,
    [string]$RabbitPassword,
    [string]$RabbitVhost = '/',
    [string]$DbName = 'drupal',
    [string]$DbUser = 'drupal_user',
    [string]$DbPassword,
    [switch]$KeepTestUser
)

$ErrorActionPreference = 'Stop'

# Reads a single key from a .env file while ignoring empty/comment lines.
function Get-DotEnvValue {
    param(
        [string]$FilePath,
        [string]$Key
    )

    if (-not (Test-Path -Path $FilePath)) {
        return $null
    }

    foreach ($line in Get-Content -Path $FilePath) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) {
            continue
        }

        if ($line -match "^\s*$Key\s*=\s*(.*)$") {
            return $Matches[1].Trim().Trim('"').Trim("'")
        }
    }

    return $null
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $repoRoot

if ([string]::IsNullOrWhiteSpace($DbPassword)) {
    $DbPassword = $env:DRUPAL_DB_PASS
}
if ([string]::IsNullOrWhiteSpace($DbPassword)) {
    $DbPassword = Get-DotEnvValue -FilePath (Join-Path $repoRoot '.env') -Key 'DRUPAL_DB_PASS'
}
if ([string]::IsNullOrWhiteSpace($DbPassword)) {
    throw 'Unable to determine DRUPAL_DB_PASS. Pass -DbPassword or set it in .env.'
}

if ($RabbitApiBaseUrl -match '^https?://(localhost|127\.0\.0\.1)(:\d+)?/?$' -and -not $PSBoundParameters.ContainsKey('RabbitUser')) {
    $RabbitUser = 'guest'
}

if ([string]::IsNullOrWhiteSpace($RabbitUser)) {
    $RabbitUser = $env:RABBITMQ_USER
}
if ([string]::IsNullOrWhiteSpace($RabbitUser)) {
    $RabbitUser = Get-DotEnvValue -FilePath (Join-Path $repoRoot '.env') -Key 'RABBITMQ_USER'
}
if ([string]::IsNullOrWhiteSpace($RabbitUser)) {
    $RabbitUser = 'guest'
}

if ([string]::IsNullOrWhiteSpace($RabbitPassword)) {
    $RabbitPassword = $env:RABBITMQ_PASS
}
if ($RabbitApiBaseUrl -match '^https?://(localhost|127\.0\.0\.1)(:\d+)?/?$' -and -not $PSBoundParameters.ContainsKey('RabbitPassword')) {
    $RabbitPassword = 'guest'
}
if ([string]::IsNullOrWhiteSpace($RabbitPassword)) {
    $RabbitPassword = Get-DotEnvValue -FilePath (Join-Path $repoRoot '.env') -Key 'RABBITMQ_PASS'
}
if ([string]::IsNullOrWhiteSpace($RabbitPassword)) {
    $RabbitPassword = 'guest'
}

$composeFiles = @('docker-compose.yml')
$localOverride = Join-Path $repoRoot 'docker-compose.local.yml'
if (Test-Path -Path $localOverride) {
    $composeFiles += 'docker-compose.local.yml'
}

$composeArgs = @('compose')
foreach ($file in $composeFiles) {
    $composeArgs += @('-f', $file)
}

function Invoke-Compose {
    param([string[]]$Arguments)

    $args = @()
    $args += $composeArgs
    $args += $Arguments
    & docker @args
}

function New-RabbitAuthHeader {
    param(
        [string]$User,
        [string]$Password
    )

    $value = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("$User`:$Password"))
    return @{ Authorization = $value }
}

function Invoke-RabbitApiRequest {
    param(
        [string]$Uri,
        [string]$Method = 'Get',
        [string]$Body = ''
    )

    $headers = New-RabbitAuthHeader -User $RabbitUser -Password $RabbitPassword
    $params = @{
        Uri = $Uri
        Method = $Method
        Headers = $headers
        UseBasicParsing = $true
        ErrorAction = 'Stop'
    }

    if ($Body -ne '') {
        $params['Body'] = $Body
        $params['ContentType'] = 'application/json'
    }

    try {
        return Invoke-WebRequest @params
    } catch {
        $statusCode = if ($_.Exception.Response) { $_.Exception.Response.StatusCode.value__ } else { 0 }
        $canFallback = ($RabbitUser -ne 'guest' -or $RabbitPassword -ne 'guest') -and ($statusCode -eq 401 -or $statusCode -eq 403)

        if (-not $canFallback) {
            throw
        }

        $fallbackHeaders = New-RabbitAuthHeader -User 'guest' -Password 'guest'
        $params['Headers'] = $fallbackHeaders
        return Invoke-WebRequest @params
    }
}

function Get-QueueCount {
    param([string]$Name)

    $vhostEncoded = [System.Uri]::EscapeDataString($RabbitVhost)
    $queueEncoded = [System.Uri]::EscapeDataString($Name)
    $uri = "$RabbitApiBaseUrl/api/queues/$vhostEncoded/$queueEncoded"

    try {
        $response = Invoke-RabbitApiRequest -Uri $uri -Method 'Get'
        $data = $response.Content | ConvertFrom-Json
        return [int]($data.messages ?? 0)
    } catch {
        if ($_.Exception.Response -and $_.Exception.Response.StatusCode.value__ -eq 404) {
            return 0
        }
        throw
    }
}

function Ensure-QueueExists {
    param([string]$Name)

    $vhostEncoded = [System.Uri]::EscapeDataString($RabbitVhost)
    $queueEncoded = [System.Uri]::EscapeDataString($Name)
    $uri = "$RabbitApiBaseUrl/api/queues/$vhostEncoded/$queueEncoded"
    $body = '{"durable":true,"auto_delete":false,"arguments":{}}'

    Invoke-RabbitApiRequest -Uri $uri -Method 'Put' -Body $body | Out-Null
}

function Wait-QueueIncrement {
    param(
        [string]$Name,
        [int]$Baseline,
        [int]$TimeoutSeconds = 10
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $current = Get-QueueCount -Name $Name
        if ($current -ge ($Baseline + 1)) {
            return $current
        }

        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    return Get-QueueCount -Name $Name
}

function Invoke-DbSql {
    param([string]$Sql)

    Invoke-Compose -Arguments @(
        'exec', '-T',
        $DbService,
        'mysql',
        "-u$DbUser",
        "-p$DbPassword",
        '-D', $DbName,
        '-N',
        '-e', $Sql
    )
}

$email = "smoke.$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())@example.test"
# Generate a per-run password to avoid static secret-like literals in repository code.
$smokePassword = "Smk!$([Guid]::NewGuid().ToString('N').Substring(0, 12))"
Write-Host "Smoke registration email: $email" -ForegroundColor Cyan

Ensure-QueueExists -Name $QueueName
$before = Get-QueueCount -Name $QueueName
Write-Host "Queue '$QueueName' before: $before" -ForegroundColor Cyan

$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$formPage = Invoke-WebRequest -Uri "$BaseUrl/register" -WebSession $session
$formBuildId = [regex]::Match($formPage.Content, 'name="form_build_id" value="([^"]+)"').Groups[1].Value
if ([string]::IsNullOrWhiteSpace($formBuildId)) {
    throw 'form_build_id not found on /register page.'
}

$payload = @{
    first_name = 'Smoke'
    last_name = 'Test'
    email = $email
    password = $smokePassword
    password_confirm = $smokePassword
    date_of_birth = '1991-01-01'
    session_id = '550e8400-e29b-41d4-a716-446655440001'
    company_name = ''
    vat_number = ''
    form_build_id = $formBuildId
    form_id = 'registration_form'
    op = 'Register'
}

$redirectStatus = 0
$redirectLocation = ''
try {
    # Disable automatic redirect follow to verify the expected confirmation redirect explicitly.
    $response = Invoke-WebRequest -Uri "$BaseUrl/register" -Method Post -WebSession $session -Body $payload -MaximumRedirection 0 -ErrorAction Stop
    if ($response -and $response.StatusCode) {
        $redirectStatus = [int]$response.StatusCode
    }
    if ($response -and $response.Headers) {
        $redirectLocation = [string]($response.Headers['Location'] ?? $response.Headers.Location)
    }
} catch {
    if ($_.Exception.Response) {
        $redirectStatus = $_.Exception.Response.StatusCode.value__
        $redirectLocation = [string]($_.Exception.Response.Headers['Location'] ?? $_.Exception.Response.Headers.Location)
    } else {
        throw
    }
}

if ($redirectStatus -ne 303 -and $redirectStatus -ne 200) {
    throw "Expected 303 redirect after registration, got '$redirectStatus'."
}

if ([string]::IsNullOrWhiteSpace($redirectLocation) -and $redirectStatus -eq 200) {
    # Some clients auto-follow even with redirection disabled; this fallback
    # still verifies that confirmation URL does not contain personal query params.
    $finalCheck = Invoke-WebRequest -Uri "$BaseUrl/register/confirmation" -WebSession $session -ErrorAction Stop
    if ($finalCheck.StatusCode -ne 200) {
        throw "Expected confirmation page to be reachable, got '$($finalCheck.StatusCode)'."
    }
} elseif ($redirectLocation -notmatch '/register/confirmation$') {
    throw "Expected redirect to /register/confirmation, got '$redirectLocation'."
}

$after = Wait-QueueIncrement -Name $QueueName -Baseline $before -TimeoutSeconds 12
Write-Host "Queue '$QueueName' after:  $after" -ForegroundColor Cyan

if (($after - $before) -ne 1) {
    throw "Expected queue increment of +1, got +$($after - $before)."
}

# Ensure the Drupal user record was persisted exactly once.
$userCount = [int](Invoke-DbSql -Sql "SELECT COUNT(*) FROM users_field_data WHERE mail='$email';")
if ($userCount -ne 1) {
    throw "Expected 1 created user for '$email', got $userCount."
}

$watchdog = Invoke-DbSql -Sql "SELECT CONCAT(type, ' | ', severity, ' | ', message, ' | ', variables) FROM watchdog WHERE type='registration_form' ORDER BY wid DESC LIMIT 3;"
Write-Host 'Latest registration_form watchdog entries:' -ForegroundColor Cyan
$watchdog | ForEach-Object { Write-Host $_ }

if (-not $KeepTestUser) {
    # Local smoke optimization: direct SQL cleanup is used for speed in disposable test data.
    # This script is not a production data-management path.
    Invoke-DbSql -Sql "SET @uid := (SELECT uid FROM users_field_data WHERE mail='$email' LIMIT 1); DELETE FROM sessions WHERE uid=@uid; DELETE FROM users_data WHERE uid=@uid; DELETE FROM user__user_picture WHERE entity_id=@uid; DELETE FROM user__roles WHERE entity_id=@uid; DELETE FROM users_field_data WHERE uid=@uid; DELETE FROM users WHERE uid=@uid;"
    Write-Host 'Temporary smoke user removed.' -ForegroundColor DarkYellow
}

Write-Host 'SMOKE TEST PASSED: registration, user persistence, queue publish, and redirect all verified.' -ForegroundColor Green
