param(
    [string]$BaseUrl = 'http://localhost:30020',
    [string]$QueueName = 'crm.incoming',
    [string]$RabbitApiBaseUrl = 'http://localhost:15672',
    [string]$RabbitUser,
    [string]$RabbitPassword,
    [string]$RabbitVhost = '/',
    [int]$Count = 5,
    [switch]$ValidateXmlPeek
)

$ErrorActionPreference = 'Stop'

if ($Count -lt 1) {
    throw 'Count moet minimaal 1 zijn.'
}

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

function Wait-QueueCountIncrease {
    param(
        [string]$Name,
        [int]$Baseline,
        [int]$ExpectedIncrease,
        [int]$TimeoutSeconds = 25
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $current = Get-QueueCount -Name $Name
        if ($current -ge ($Baseline + $ExpectedIncrease)) {
            return $current
        }

        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    return Get-QueueCount -Name $Name
}

function Get-FormBuildId {
    param(
        [string]$RegisterUrl,
        [Microsoft.PowerShell.Commands.WebRequestSession]$WebSession
    )

    $formPage = Invoke-WebRequest -Uri $RegisterUrl -WebSession $WebSession
    $match = [regex]::Match($formPage.Content, 'name="form_build_id" value="([^"]+)"')

    if (-not $match.Success -or [string]::IsNullOrWhiteSpace($match.Groups[1].Value)) {
        throw 'form_build_id niet gevonden op /register.'
    }

    return $match.Groups[1].Value
}

function Invoke-DrupalRegistration {
    param(
        [string]$RegisterUrl,
        [hashtable]$RegistrationData
    )

    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $formBuildId = Get-FormBuildId -RegisterUrl $RegisterUrl -WebSession $session

    $payload = @{
        first_name = $RegistrationData.first_name
        last_name = $RegistrationData.last_name
        email = $RegistrationData.email
        password = $RegistrationData.password
        password_confirm = $RegistrationData.password
        date_of_birth = $RegistrationData.date_of_birth
        session_id = $RegistrationData.session_id
        company_name = $RegistrationData.company_name
        vat_number = $RegistrationData.vat_number
        form_build_id = $formBuildId
        form_id = 'registration_form'
        op = 'Register'
    }

    if ($RegistrationData.is_company) {
        $payload['is_company'] = '1'
    }

    $redirectStatus = 0
    $redirectLocation = ''

    try {
        $response = Invoke-WebRequest -Uri $RegisterUrl -Method Post -WebSession $session -Body $payload -MaximumRedirection 0 -ErrorAction Stop
        $redirectStatus = [int]$response.StatusCode
        $redirectLocation = [string]($response.Headers['Location'] ?? $response.Headers.Location)
    } catch {
        if ($_.Exception.Response) {
            $redirectStatus = $_.Exception.Response.StatusCode.value__
            $redirectLocation = [string]($_.Exception.Response.Headers['Location'] ?? $_.Exception.Response.Headers.Location)
        } else {
            throw
        }
    }

    if ($redirectStatus -ne 303) {
        throw "Registratie voor '$($RegistrationData.email)' faalde. Verwachtte 303 redirect, kreeg '$redirectStatus'."
    }

    if ([string]::IsNullOrWhiteSpace($redirectLocation) -or $redirectLocation -notmatch '/register/confirmation$') {
        throw "Onverwachte redirect voor '$($RegistrationData.email)': '$redirectLocation'."
    }
}

function Get-QueueMessages {
    param(
        [string]$Name,
        [int]$MessageCount
    )

    $vhostEncoded = [System.Uri]::EscapeDataString($RabbitVhost)
    $queueEncoded = [System.Uri]::EscapeDataString($Name)
    $uri = "$RabbitApiBaseUrl/api/queues/$vhostEncoded/$queueEncoded/get"

    $body = @{
        count = $MessageCount
        ackmode = 'ack_requeue_true'
        encoding = 'auto'
        truncate = 50000
    } | ConvertTo-Json

    $response = Invoke-RabbitApiRequest -Uri $uri -Method 'Post' -Body $body
    return ($response.Content | ConvertFrom-Json)
}

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$envPath = Join-Path $repoRoot '.env'

if ([string]::IsNullOrWhiteSpace($RabbitUser)) {
    $RabbitUser = $env:RABBITMQ_USER
}
if ([string]::IsNullOrWhiteSpace($RabbitUser)) {
    $RabbitUser = Get-DotEnvValue -FilePath $envPath -Key 'RABBITMQ_USER'
}
if ([string]::IsNullOrWhiteSpace($RabbitUser)) {
    $RabbitUser = 'guest'
}

if ([string]::IsNullOrWhiteSpace($RabbitPassword)) {
    $RabbitPassword = $env:RABBITMQ_PASS
}
if ([string]::IsNullOrWhiteSpace($RabbitPassword)) {
    $RabbitPassword = Get-DotEnvValue -FilePath $envPath -Key 'RABBITMQ_PASS'
}
if ([string]::IsNullOrWhiteSpace($RabbitPassword)) {
    $RabbitPassword = 'guest'
}

$firstNames = @('Sofie', 'Lotte', 'Emma', 'Noah', 'Lucas', 'Milan', 'Nora', 'Jules', 'Elias', 'Hanne')
$lastNames = @('Peeters', 'Janssens', 'Maes', 'Willems', 'Claes', 'Vermeulen', 'Goossens', 'Jacobs', 'Lenaerts', 'Van den Broeck')
$sessions = @(
    '550e8400-e29b-41d4-a716-446655440001',
    '550e8400-e29b-41d4-a716-446655440002',
    '550e8400-e29b-41d4-a716-446655440003'
)

$registerUrl = "$BaseUrl/register"
$runId = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$registrations = @()

for ($i = 1; $i -le $Count; $i++) {
    $firstName = $firstNames[($i - 1) % $firstNames.Count]
    $lastName = $lastNames[($i - 1) % $lastNames.Count]
    $isCompany = ($i % 3 -eq 0)

    $registrations += @{
        first_name = $firstName
        last_name = $lastName
        email = ("{0}.{1}.{2}.{3}@example.test" -f $firstName.ToLower(), $lastName.ToLower().Replace(' ', ''), $runId, $i)
        password = "Reg!$([Guid]::NewGuid().ToString('N').Substring(0, 12))"
        date_of_birth = (Get-Date '1990-01-01').AddDays($i * 37).ToString('yyyy-MM-dd')
        session_id = $sessions[($i - 1) % $sessions.Count]
        is_company = $isCompany
        company_name = if ($isCompany) { "$lastName Consulting BV" } else { '' }
        vat_number = if ($isCompany) { ('BE0' + (100000000 + $i).ToString()) } else { '' }
    }
}

Write-Host "Start bulk-registratie run: $Count registraties" -ForegroundColor Cyan
Write-Host "BaseUrl: $BaseUrl" -ForegroundColor DarkCyan
Write-Host "Queue: $QueueName" -ForegroundColor DarkCyan

Ensure-QueueExists -Name $QueueName
$before = Get-QueueCount -Name $QueueName
Write-Host "Queue '$QueueName' before: $before" -ForegroundColor Cyan

$index = 0
foreach ($registration in $registrations) {
    $index++
    Write-Host ("[{0}/{1}] Registratie versturen voor {2}" -f $index, $Count, $registration.email) -ForegroundColor Yellow
    Invoke-DrupalRegistration -RegisterUrl $registerUrl -RegistrationData $registration
}

$after = Wait-QueueCountIncrease -Name $QueueName -Baseline $before -ExpectedIncrease $Count -TimeoutSeconds 35
Write-Host "Queue '$QueueName' after:  $after" -ForegroundColor Cyan

$delta = $after - $before
if ($delta -lt $Count) {
    throw "Verwachtte queue-toename van minstens +$Count, maar kreeg +$delta."
}

if ($ValidateXmlPeek) {
    $peekCount = [Math]::Min($Count, 20)
    $messages = Get-QueueMessages -Name $QueueName -MessageCount $peekCount

    if (-not $messages -or $messages.Count -lt 1) {
        throw 'Queue-validate faalde: geen berichten teruggekregen via RabbitMQ API.'
    }

    $xmlMatches = 0
    foreach ($message in $messages) {
        $payload = [string]($message.payload ?? '')
        if ($payload -like '*<message>*' -and $payload -like '*<type>new_registration</type>*') {
            $xmlMatches++
        }
    }

    if ($xmlMatches -lt 1) {
        throw 'Queue-validate faalde: geen new_registration XML payload gevonden in gepeekte berichten.'
    }

    Write-Host "XML validatie geslaagd: $xmlMatches gepeekte berichten bevatten new_registration XML." -ForegroundColor Green
}

Write-Host "BULK TEST GESLAAGD: $Count registraties ingestuurd en RabbitMQ queue steeg met +$delta." -ForegroundColor Green
Write-Host 'Gebruikte e-mails:' -ForegroundColor Cyan
foreach ($registration in $registrations) {
    Write-Host ("- " + $registration.email)
}
