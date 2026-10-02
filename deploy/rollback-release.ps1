# 직전 배포 이전 상태로 되돌리고 PHP/Apache를 재시작한다(멈춘 PHP 작업 정리).
param(
    [string]$Backup = '/home/bitnami/deploy-backups/htdocs-before-20261002-225859.tar.gz',
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78'
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'rollback-release.out.log'
Start-Transcript -Path $log -Force | Out-Null
$ssh = @('-n','-o','BatchMode=yes','-o','StrictHostKeyChecking=accept-new','-o','ConnectTimeout=15','-i',$KeyPath)
try {
    $cmd = "set -e; test -f $Backup; sudo -n pkill -f '/tmp/wesiper-year-leaders-[^ ]*/build-year-leaders[.]php' || true; sudo -n tar -xzf $Backup -C /opt/bitnami/apache; echo restored; (sudo -n /opt/bitnami/ctlscript.sh restart php-fpm || true); sudo -n /opt/bitnami/ctlscript.sh restart apache; echo restarted"
    & ssh.exe @ssh $RemoteHost $cmd 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw "rollback failed ($LASTEXITCODE)" }
    Write-Host 'DONE'
} catch { Write-Host "ERROR: $_" } finally { Stop-Transcript | Out-Null }
