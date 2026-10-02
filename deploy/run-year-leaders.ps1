# 연도별 리그 1위 표 계산. backend/lib와 build-year-leaders.php를 서버 임시 폴더에 올려 서버 PHP·DB로 실행한다.
# 사용법: ./deploy/run-year-leaders.ps1 [-Apply] [-Year 2026]
param(
    [switch]$Apply,
    [string]$Year = '',
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78',
    [string]$RemoteDbConfig = '/opt/bitnami/apache/conf/wesiper-db.php'
)
$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$log = Join-Path $PSScriptRoot 'run-year-leaders.out.log'
Start-Transcript -Path $log -Force | Out-Null
$remote = "/tmp/wesiper-year-leaders-$([guid]::NewGuid().ToString('N'))"
$scpOpts = @('-q','-o','BatchMode=yes','-o','StrictHostKeyChecking=accept-new','-o','ConnectTimeout=15','-i',$KeyPath)
# -n: ssh가 콘솔 입력을 서버로 넘기며 기다리지 않게 한다.
$ssh = @('-n') + $scpOpts[1..($scpOpts.Length-1)]
try {
    if (!(Test-Path -LiteralPath $KeyPath)) { throw "SSH key not found: $KeyPath" }
    $root = Split-Path $PSScriptRoot -Parent
    & ssh.exe @ssh $RemoteHost "mkdir -p $remote/lib && chmod 755 $remote $remote/lib"
    if ($LASTEXITCODE -ne 0) { throw "mkdir failed ($LASTEXITCODE)" }
    $libFiles = Get-ChildItem -LiteralPath (Join-Path $root 'backend\lib') -Filter *.php | ForEach-Object { $_.FullName }
    & scp.exe @scpOpts @libFiles "${RemoteHost}:$remote/lib/"
    if ($LASTEXITCODE -ne 0) { throw "lib upload failed ($LASTEXITCODE)" }
    & scp.exe @scpOpts (Join-Path $PSScriptRoot 'build-year-leaders.php') "${RemoteHost}:$remote/"
    if ($LASTEXITCODE -ne 0) { throw "script upload failed ($LASTEXITCODE)" }
    & ssh.exe @ssh $RemoteHost "chmod -R a+rX $remote"
    $mode = if ($Apply) { '--apply' } else { '--check' }
    $yearArg = if ($Year) { "--year=$Year" } else { '' }
    Write-Host "== $mode $yearArg =="
    # 서버 출력을 한 줄씩 화면과 로그에 남긴다(연도별 진행 상황 확인용).
    & ssh.exe @ssh $RemoteHost "PATH=`$PATH:/opt/bitnami/php/bin; sudo -n -u daemon `$(command -v php) $remote/build-year-leaders.php $mode $yearArg $RemoteDbConfig" 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw "run failed ($LASTEXITCODE)" }
    Write-Host 'DONE'
} catch {
    Write-Host "ERROR: $_"
} finally {
    & ssh.exe @ssh $RemoteHost "rm -rf $remote" 2>$null
    Stop-Transcript | Out-Null
}
