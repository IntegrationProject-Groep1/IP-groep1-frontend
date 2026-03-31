param(
    [switch]$DryRun,
    [switch]$ConfirmLocal,
    [string]$DestructiveApproval,
    [string]$DbService = 'frontend_db',
    [string]$DbName = 'drupal',
    [string]$DbUser = 'drupal_user',
    [string]$DbPassword
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

$composeFiles = @('docker-compose.yml')
$localOverride = Join-Path $repoRoot 'docker-compose.local.yml'
if (Test-Path -Path $localOverride) {
    $composeFiles += 'docker-compose.local.yml'
} else {
    # Hard local-only guarantee: never run this script without local compose override.
    throw 'Safety guard: docker-compose.local.yml is required. This script is local-only and will not run without the local override file.'
}

$composeArgs = @('compose')
foreach ($file in $composeFiles) {
    $composeArgs += @('-f', $file)
}

if ($DbService -ne 'frontend_db') {
    throw "Safety guard: DbService must remain 'frontend_db'. Refusing custom service '$DbService'."
}

if ($DbName -ne 'drupal') {
    throw "Safety guard: DbName must remain 'drupal'. Refusing custom database '$DbName'."
}

$dbHostFromEnv = Get-DotEnvValue -FilePath (Join-Path $repoRoot '.env') -Key 'DRUPAL_DB_HOST'
if ([string]::IsNullOrWhiteSpace($dbHostFromEnv)) {
    $dbHostFromEnv = 'frontend_db'
}

$allowedLocalHosts = @('frontend_db', 'localhost', '127.0.0.1', '::1')
if ($allowedLocalHosts -notcontains $dbHostFromEnv) {
    throw "Safety guard: DRUPAL_DB_HOST='$dbHostFromEnv' is not a local host. Refusing to run reset on non-local configuration."
}

$psOutput = & docker @($composeArgs + @('ps', '--services', '--status', 'running'))
$requiredLocalServices = @('frontend_db')
foreach ($service in $requiredLocalServices) {
    if (-not ($psOutput -contains $service)) {
        throw "Safety guard: required local service '$service' is not running. Start the local stack before running this script."
    }
}

if (-not $DryRun -and -not $ConfirmLocal) {
    throw 'Safety guard: destructive mode requires -ConfirmLocal. Example: pwsh ./scripts/reset_test_users.ps1 -ConfirmLocal'
}

# Require an explicit approval phrase to prevent accidental destructive execution.
$requiredApproval = 'DELETE-LOCAL-TEST-USERS'
if (-not $DryRun -and $DestructiveApproval -ne $requiredApproval) {
    throw "Safety guard: destructive mode requires -DestructiveApproval '$requiredApproval'."
}

function Invoke-DbSql {
    param([string]$Sql)

    $args = @()
    $args += $composeArgs
    $args += @(
        'exec', '-T',
        $DbService,
        'mysql',
        "-u$DbUser",
        "-p$DbPassword",
        '-D', $DbName,
        '-e', $Sql
    )

    & docker @args
}

$previewSql = @"
-- Build the keep-list first: system users and administrators are always preserved.
CREATE TEMPORARY TABLE keep_users (uid INT PRIMARY KEY);
INSERT IGNORE INTO keep_users (uid) VALUES (0), (1);
INSERT IGNORE INTO keep_users (uid)
SELECT DISTINCT ur.entity_id
FROM user__roles ur
WHERE ur.roles_target_id = 'administrator';

SELECT ufd.uid, ufd.name, ufd.mail,
       COALESCE(GROUP_CONCAT(ur.roles_target_id), '-') AS roles
FROM users_field_data ufd
LEFT JOIN user__roles ur ON ur.entity_id = ufd.uid
GROUP BY ufd.uid, ufd.name, ufd.mail
ORDER BY ufd.uid;

SELECT 'users_to_delete' AS metric, COUNT(*) AS total
FROM users_field_data ufd
WHERE ufd.uid NOT IN (SELECT uid FROM keep_users);
"@

Write-Host 'Current users:' -ForegroundColor Cyan
Invoke-DbSql -Sql $previewSql

if ($DryRun) {
    Write-Host 'Dry run complete. No users were deleted.' -ForegroundColor Yellow
    exit 0
}

$deleteSql = @"
START TRANSACTION;

-- Rebuild the keep-list in the same transaction before any deletion.
CREATE TEMPORARY TABLE keep_users (uid INT PRIMARY KEY);
INSERT IGNORE INTO keep_users (uid) VALUES (0), (1);
INSERT IGNORE INTO keep_users (uid)
SELECT DISTINCT ur.entity_id
FROM user__roles ur
WHERE ur.roles_target_id = 'administrator';

DELETE FROM sessions WHERE uid NOT IN (SELECT uid FROM keep_users);
DELETE FROM users_data WHERE uid NOT IN (SELECT uid FROM keep_users);
DELETE FROM user__user_picture WHERE entity_id NOT IN (SELECT uid FROM keep_users);
DELETE FROM user__roles WHERE entity_id NOT IN (SELECT uid FROM keep_users);
DELETE FROM users_field_data WHERE uid NOT IN (SELECT uid FROM keep_users);
DELETE FROM users WHERE uid NOT IN (SELECT uid FROM keep_users);

COMMIT;

SELECT 'remaining_users' AS metric, COUNT(*) AS total
FROM users_field_data;
"@

Invoke-DbSql -Sql $deleteSql
Write-Host 'Done. Non-admin users have been removed.' -ForegroundColor Green
