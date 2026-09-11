param(
    [Parameter(Mandatory = $true)]
    [string]$ReleaseId,
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,
    [string]$NodePath = 'C:\Users\Lenovo\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe',
    [string]$PlinkPath = 'C:\Program Files\PuTTY\plink.exe',
    [string]$PscpPath = 'C:\Program Files\PuTTY\pscp.exe',
    [string]$KeyPath = 'C:\Users\Lenovo\Desktop\코딩\WESIPER\tuloPuttyKey.ppk',
    [string]$RemoteHost = 'bitnami@3.35.252.78'
)

$ErrorActionPreference = 'Stop'
$archiveName = "wesiper-deploy-$ReleaseId-frontend.tar.gz"
$archivePath = Join-Path $ProjectRoot $archiveName
$frontendPath = Join-Path $ProjectRoot 'frontend'

Push-Location $frontendPath
try {
    & $NodePath 'node_modules/vite/bin/vite.js' build
    if ($LASTEXITCODE -ne 0) { throw "Frontend build failed ($LASTEXITCODE)." }
} finally {
    Pop-Location
}

if (Test-Path -LiteralPath $archivePath) { Remove-Item -LiteralPath $archivePath -Force }
tar -czf $archivePath -C (Join-Path $frontendPath 'dist') .
if ($LASTEXITCODE -ne 0) { throw "Frontend archive failed ($LASTEXITCODE)." }

$remoteDir = "/home/bitnami/wesiper-deploy-$ReleaseId"
& $PlinkPath -batch -ssh -i $KeyPath $RemoteHost "mkdir -p $remoteDir"
if ($LASTEXITCODE -ne 0) { throw "Remote release directory creation failed ($LASTEXITCODE)." }

& $PscpPath -batch -i $KeyPath $archivePath "${RemoteHost}:$remoteDir/frontend.tar.gz"
if ($LASTEXITCODE -ne 0) { throw "Archive upload failed ($LASTEXITCODE)." }

$remoteCommand = "set -e; mkdir -p /home/bitnami/deploy-backups; tar -czf /home/bitnami/deploy-backups/htdocs-before-$ReleaseId.tar.gz -C /opt/bitnami/apache htdocs; tar -xzf $remoteDir/frontend.tar.gz -C /opt/bitnami/apache/htdocs; echo deployed-$ReleaseId"
& $PlinkPath -batch -ssh -i $KeyPath $RemoteHost $remoteCommand
if ($LASTEXITCODE -ne 0) { throw "Remote deployment failed ($LASTEXITCODE)." }

Remove-Item -LiteralPath $archivePath -Force
Write-Output "Deployment completed: $ReleaseId"
