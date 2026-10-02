# 긴급 복구(가벼운 버전): 타이브레이커 수정 직전 커밋(99b174b)의 api/lib만 올리고 PHP-FPM·Apache 재시작.
param(
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78'
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'quick-restore-backend.out.log'
Start-Transcript -Path $log -Force | Out-Null
$scpOpts = @('-q','-o','BatchMode=yes','-o','StrictHostKeyChecking=accept-new','-o','ConnectTimeout=15','-i',$KeyPath)
$ssh = @('-n') + $scpOpts[1..($scpOpts.Length-1)]
try {
    & scp.exe @scpOpts (Join-Path $PSScriptRoot 'backend-99b174b.tar.gz') "${RemoteHost}:/tmp/backend-99b174b.tar.gz"
    if ($LASTEXITCODE -ne 0) { throw "upload failed ($LASTEXITCODE)" }
    $cmd = "set -e; sudo -n pkill -f '/tmp/wesiper-year-leaders-[^ ]*/build-year-leaders[.]php' || true; mkdir -p /tmp/restore-99b174b; tar -xzf /tmp/backend-99b174b.tar.gz -C /tmp/restore-99b174b; sudo -n cp -R /tmp/restore-99b174b/api/. /opt/bitnami/apache/htdocs/api/; sudo -n cp -R /tmp/restore-99b174b/lib/. /opt/bitnami/apache/htdocs/lib/; echo restored-backend; (sudo -n /opt/bitnami/ctlscript.sh restart php-fpm || true); echo restarted-php; rm -rf /tmp/restore-99b174b /tmp/backend-99b174b.tar.gz"
    & ssh.exe @ssh $RemoteHost $cmd 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw "restore failed ($LASTEXITCODE)" }
    Write-Host 'DONE'
} catch { Write-Host "ERROR: $_" } finally { Stop-Transcript | Out-Null }
