# 올해 시즌 리그 1위 일일 갱신 예약 작업을 서버에 설치한다.
param(
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78'
)
$ErrorActionPreference = 'Stop'
$log = Join-Path $PSScriptRoot 'install-year-leaders-daily.out.log'
Start-Transcript -Path $log -Force | Out-Null
$remote = "/tmp/wesiper-year-leaders-install-$([guid]::NewGuid().ToString('N'))"
$scpOpts = @('-q','-o','BatchMode=yes','-o','StrictHostKeyChecking=accept-new','-o','ConnectTimeout=15','-i',$KeyPath)
$ssh = @('-n') + $scpOpts[1..($scpOpts.Length-1)]
try {
    if (!(Test-Path -LiteralPath $KeyPath)) { throw "SSH key not found: $KeyPath" }
    $root = Split-Path $PSScriptRoot -Parent
    & ssh.exe @ssh $RemoteHost "mkdir -p $remote/lib"
    if ($LASTEXITCODE -ne 0) { throw "mkdir failed ($LASTEXITCODE)" }
    $libFiles = Get-ChildItem -LiteralPath (Join-Path $root 'backend\lib') -Filter *.php | ForEach-Object { $_.FullName }
    & scp.exe @scpOpts @libFiles "${RemoteHost}:$remote/lib/"
    if ($LASTEXITCODE -ne 0) { throw "lib upload failed ($LASTEXITCODE)" }
    & scp.exe @scpOpts (Join-Path $PSScriptRoot 'build-year-leaders.php') (Join-Path $PSScriptRoot 'install-year-leaders-daily.sh') "${RemoteHost}:$remote/"
    if ($LASTEXITCODE -ne 0) { throw "script upload failed ($LASTEXITCODE)" }
    & ssh.exe @ssh $RemoteHost "sed -i 's/\r$//' $remote/install-year-leaders-daily.sh && bash $remote/install-year-leaders-daily.sh $remote" 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw "install failed ($LASTEXITCODE)" }
    Write-Host 'DONE'
} catch {
    Write-Host "ERROR: $_"
} finally {
    & ssh.exe @ssh $RemoteHost "rm -rf $remote" 2>$null
    Stop-Transcript | Out-Null
}
