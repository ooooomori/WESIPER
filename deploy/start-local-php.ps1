param(
    [string]$RuntimeDirectory = (Join-Path $env:TEMP 'wesiper-local-dev'),
    [string]$RemoteHost = 'bitnami@3.35.252.78'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$php = Join-Path $RuntimeDirectory 'php/php.exe'
foreach ($file in @($php, (Join-Path $RuntimeDirectory 'php.ini'), (Join-Path $RuntimeDirectory 'local-db.php'), (Join-Path $RuntimeDirectory 'ssh-key.pem'))) {
    if (!(Test-Path -LiteralPath $file)) { throw "Local PHP setup file missing: $file" }
}
if (!(Get-NetTCPConnection -State Listen -LocalPort 13306 -ErrorAction SilentlyContinue)) {
    $key = Join-Path $RuntimeDirectory 'ssh-key.pem'
    Start-Process -FilePath 'ssh.exe' -WindowStyle Hidden -ArgumentList @('-N', '-o', 'BatchMode=yes', '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=30', '-i', "`"$key`"", '-L', '127.0.0.1:13306:127.0.0.1:3306', $RemoteHost)
    Start-Sleep -Seconds 2
    if (!(Get-NetTCPConnection -State Listen -LocalPort 13306 -ErrorAction SilentlyContinue)) { throw 'DB tunnel failed to start.' }
}
$env:WESIPER_DB_CONFIG = Join-Path $RuntimeDirectory 'local-db.php'
$env:WESIPER_WEATHER_CONFIG = Join-Path $RuntimeDirectory 'weather.php'
& $php -c (Join-Path $RuntimeDirectory 'php.ini') -S 127.0.0.1:18766 -t (Join-Path $projectRoot 'backend') (Join-Path $PSScriptRoot 'local-router.php')
