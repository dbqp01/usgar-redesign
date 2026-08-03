<#
.SYNOPSIS
    Captura el contrato actual del backend PHP (/api/*) en docs/refactoring/backend-contract.md.
    Idempotente: sobrescribe el archivo de salida en cada ejecucion.
.DESCRIPTION
    Solo toca endpoints seguros (sin efectos secundarios): GET /api/health,
    GET /api/rooms (SELECT read-only), GET /api/auth/providers y un POST invalido
    a /api/booking (body '{}' -> 400 por validacion, no crea hold).
    Compatible con Windows PowerShell 5.1 (no usa -SkipHttpErrorCheck).
.NOTES
    Uso: powershell -ExecutionPolicy Bypass -File tests/backend-contract.ps1 [-Port 4399] [-Out docs/refactoring/backend-contract.md]
#>
param(
    [int]$Port = 4399,
    [string]$Out = 'docs/refactoring/backend-contract.md'
)

$ErrorActionPreference = 'Stop'
$base = "http://localhost:$Port"

function Invoke-CaptureRequest {
    param(
        [string]$Method,
        [string]$Url,
        [string]$Body = $null
    )
    $params = @{ Uri = $Url; Method = $Method; UseBasicParsing = $true }
    if ($PSBoundParameters.ContainsKey('Body')) {
        $params['Body'] = $Body
        $params['ContentType'] = 'application/json'
    }
    try {
        $r = Invoke-WebRequest @params
        return @{ StatusCode = [int]$r.StatusCode; ContentType = [string]$r.Headers['Content-Type']; Content = [string]$r.Content }
    } catch {
        $resp = $_.Exception.Response
        if ($resp -ne $null) {
            $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
            return @{ StatusCode = [int]$resp.StatusCode; ContentType = [string]$resp.Headers['Content-Type']; Content = $reader.ReadToEnd() }
        }
        throw
    }
}

# --- Asegurar que el servidor este arriba -----------------------------------
$proc = $null
try {
    $null = Invoke-WebRequest "$base/api/health" -UseBasicParsing -TimeoutSec 5
    Write-Host "Servidor ya respondia en $base"
} catch {
    Write-Host "Servidor no respondia; levantando php -S localhost:$Port -t public public/index.php"
    $proc = Start-Process php -ArgumentList '-S', "localhost:$Port", '-t', 'public', 'public/index.php' -WindowStyle Hidden -PassThru
    Start-Sleep -Seconds 2
}

# --- Captura -----------------------------------------------------------------
try {
    $targets = @(
        @{ name = 'health';           method = 'GET';  url = "$base/api/health" },
        @{ name = 'rooms';            method = 'GET';  url = "$base/api/rooms?checkIn=2026-08-15&checkOut=2026-08-16" },
        @{ name = 'providers';        method = 'GET';  url = "$base/api/auth/providers" },
        @{ name = 'booking-invalid';  method = 'POST'; url = "$base/api/booking"; body = '{}' }
    )

    $lines = @()
    $lines += "# Contrato del backend actual (linea base)"
    $lines += ""
    $lines += "Captura realizada el $(Get-Date -Format 'yyyy-MM-dd HH:mm') (local) contra php -S localhost:$Port -t public public/index.php"
    $lines += ""

    foreach ($t in $targets) {
        Write-Host "Capturando $($t.name) [$($t.method) $($t.url)]"
        $r = if ($t.ContainsKey('body')) {
            Invoke-CaptureRequest -Method $t.method -Url $t.url -Body $t.body
        } else {
            Invoke-CaptureRequest -Method $t.method -Url $t.url
        }
        $lines += "## $($t.name)"
        $lines += ""
        $lines += "- HTTP: $($r.StatusCode)"
        $lines += "- Headers relevantes: Content-Type=$($r.ContentType)"
        $lines += "- Body:"
        $lines += '```json'
        $lines += $r.Content
        $lines += '```'
        $lines += ""
    }

    $dir = Split-Path -Parent $Out
    if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $lines | Set-Content -Path $Out -Encoding UTF8
    Write-Host "Contrato escrito en $Out"
} finally {
    if ($proc -and -not $proc.HasExited) {
        Stop-Process -Id $proc.Id -Force
        Write-Host "Servidor php detenido (PID $($proc.Id))"
    }
}
