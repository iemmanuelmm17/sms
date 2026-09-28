# ============================================================
#  update-env.ps1 - helper for install.bat
#
#  Updates (or appends) KEY=value lines in the backend /
#  frontend .env files. install.bat calls it like:
#
#    powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%update-env.ps1" -Target backend -HostName "%H%"
#
#  The project root is taken from the script's own location
#  ($PSScriptRoot), so install.bat never has to pass a path with a
#  trailing backslash - that would collide with the closing quote and
#  corrupt Windows argument parsing (\" becomes a literal quote).
#
#  This REPLACES the old inline "powershell -Command <900-char
#  one-liner>" calls: pushing code through cmd's quoting rules is
#  fragile - a single mangled character (hand-edits, bad merges,
#  copy/paste) shifts the quote pairing and PowerShell dies with
#  "Unexpected token" / "The string is missing the terminator".
#  With -File, no code travels through cmd at all; only three
#  simple arguments do.
#
#  Windows PowerShell 5.1 compatible (no PS7-only syntax).
# ============================================================
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('backend', 'frontend')]
    [string]$Target,

    [Parameter(Mandatory = $true)]
    [string]$HostName,

    # Optional override; defaults to the folder containing this script
    # (= the project root, next to install.bat).
    [string]$RootDir = ''
)

$ErrorActionPreference = 'Stop'

if ($RootDir -eq '') { $RootDir = $PSScriptRoot }

# Update the KEY=... line in $File, or append it when absent.
function Set-EnvValue {
    param(
        [string]$File,
        [string]$Key,
        [string]$Value
    )
    $lines = @()
    if (Test-Path -LiteralPath $File) { $lines = @(Get-Content -LiteralPath $File) }
    $pattern = '^' + [regex]::Escape($Key) + '='
    $hit = $false
    $out = @()
    foreach ($line in $lines) {
        if (($null -ne $line) -and ($line -match $pattern)) {
            $hit = $true
            $out += ($Key + '=' + $Value)
        } else {
            $out += $line
        }
    }
    if (-not $hit) { $out += ($Key + '=' + $Value) }
    Set-Content -LiteralPath $File -Value $out -Encoding Ascii
}

# Read a key's current value ('' when the file/line/value is missing).
function Get-EnvValue {
    param(
        [string]$File,
        [string]$Key
    )
    if (-not (Test-Path -LiteralPath $File)) { return '' }
    $pattern = '^' + [regex]::Escape($Key) + '='
    $line = Get-Content -LiteralPath $File | Where-Object { $_ -match $pattern } | Select-Object -First 1
    if ($null -eq $line) { return '' }
    return ($line -replace $pattern, '').Trim()
}

# 32-char ALPHANUMERIC key. Deliberately no base64 characters
# (+ / =): the Pusher SDK does not URL-encode its auth query
# string, so those break Reverb authentication.
function New-RandomKey {
    -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 32 | ForEach-Object { [char]$_ })
}

if ($Target -eq 'backend') {
    $f = Join-Path $RootDir 'backend\.env'
    Set-EnvValue -File $f -Key 'APP_URL' -Value ('http://' + $HostName + ':8000')
    # REVERB_HOST is the address BROWSERS get from /api/realtime for the
    # WebSocket. 'localhost' only works for browsers on this machine; use the
    # LAN/public IP so other devices can connect (the server-side broadcaster
    # loops back to it fine).
    Set-EnvValue -File $f -Key 'REVERB_HOST' -Value $HostName
    if ((Get-EnvValue -File $f -Key 'REVERB_APP_KEY') -eq '') {
        Set-EnvValue -File $f -Key 'REVERB_APP_KEY' -Value (New-RandomKey)
    }
    if ((Get-EnvValue -File $f -Key 'REVERB_APP_SECRET') -eq '') {
        Set-EnvValue -File $f -Key 'REVERB_APP_SECRET' -Value (New-RandomKey)
    }
    Write-Host 'backend .env updated'
} else {
    $f = Join-Path $RootDir 'frontend\.env'
    $b = Get-EnvValue -File (Join-Path $RootDir 'backend\.env') -Key 'REVERB_APP_KEY'
    Set-EnvValue -File $f -Key 'VITE_API_URL' -Value '/'
    Set-EnvValue -File $f -Key 'VITE_API_PROXY' -Value 'http://localhost:8000'
    Set-EnvValue -File $f -Key 'VITE_ALLOWED_HOSTS' -Value $HostName
    Set-EnvValue -File $f -Key 'VITE_REVERB_APP_KEY' -Value $b
    Set-EnvValue -File $f -Key 'VITE_REVERB_HOST' -Value $HostName
    Set-EnvValue -File $f -Key 'VITE_REVERB_PORT' -Value '8080'
    Set-EnvValue -File $f -Key 'VITE_REVERB_SCHEME' -Value 'http'
    if ($b -eq '') {
        Write-Host 'WARNING: backend REVERB_APP_KEY is empty'
    } else {
        Write-Host 'frontend .env updated'
    }
}
