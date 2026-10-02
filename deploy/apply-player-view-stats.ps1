# 인기 선수 집계 테이블 생성. 서버에 SSH로 접속해 서버의 PHP와 DB 설정으로 실행한다.
# 사용법: ./deploy/apply-player-view-stats.ps1 [-CheckOnly] [-KeyPath <pem>]
param(
    [switch]$CheckOnly,
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78',
    [string]$RemoteDbConfig = '/opt/bitnami/apache/conf/wesiper-db.php'
)
$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$log = Join-Path $PSScriptRoot 'apply-player-view-stats.log'
Start-Transcript -Path $log -Force | Out-Null
try {
    if (!(Test-Path -LiteralPath $KeyPath)) { throw "SSH key not found: $KeyPath" }
    $script = Get-Content -Raw -LiteralPath (Join-Path $PSScriptRoot 'add-player-view-stats.php')
    $modes = if ($CheckOnly) { @('--check') } else { @('--check', '--apply') }
    foreach ($mode in $modes) {
        Write-Host "== $mode =="
        # 스크립트를 표준 입력으로 보내 서버에 파일을 남기지 않는다.
        $script | & ssh.exe -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -i $KeyPath $RemoteHost "PATH=`$PATH:/opt/bitnami/php/bin; sudo -n -u daemon `$(command -v php) -- $mode $RemoteDbConfig"
        if ($LASTEXITCODE -ne 0) { throw "$mode failed (exit $LASTEXITCODE)" }
    }
    Write-Host 'DONE'
} catch {
    Write-Host "ERROR: $_"
} finally {
    Stop-Transcript | Out-Null
}
