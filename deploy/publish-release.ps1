# 프런트(빌드 결과)와 백엔드(api, lib)를 운영 서버 htdocs에 배포한다.
# 순서: 로컬 빌드 → 묶어서 업로드 → 서버에서 PHP 문법 검사 → htdocs 백업 → 교체 → Apache graceful
param(
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78'
)
$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$log = Join-Path $PSScriptRoot 'publish-release.out.log'
Start-Transcript -Path $log -Force | Out-Null
$root = Split-Path $PSScriptRoot -Parent
$id = Get-Date -Format 'yyyyMMdd-HHmmss'
$work = Join-Path $env:TEMP "wesiper-release-$id"
$remote = "/home/bitnami/wesiper-deploy-$id"
$scpOpts = @('-q','-o','BatchMode=yes','-o','StrictHostKeyChecking=accept-new','-o','ConnectTimeout=15','-i',$KeyPath)
$ssh = @('-n') + $scpOpts[1..($scpOpts.Length-1)]
function Remote([string]$command) {
    & ssh.exe @ssh $RemoteHost $command 2>&1 | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw "remote command failed ($LASTEXITCODE): $command" }
}
try {
    if (!(Test-Path -LiteralPath $KeyPath)) { throw "SSH key not found: $KeyPath" }
    # node.exe 찾기: PATH → 실행 중인 node(개발 서버) → Codex 런타임 → 일반 설치 경로
    $candidates = @(
        (Get-Command node -ErrorAction SilentlyContinue).Source,
        (Get-Process node -ErrorAction SilentlyContinue | Where-Object { $_.Path } | Select-Object -First 1 -ExpandProperty Path)
    ) + @(Get-ChildItem -Path (Join-Path $env:USERPROFILE '.cache\codex-runtimes') -Filter node.exe -Recurse -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName) + @(
        (Join-Path $env:ProgramFiles 'nodejs\node.exe'),
        (Join-Path $env:LOCALAPPDATA 'Programs\nodejs\node.exe'),
        $(if ($env:NVM_SYMLINK) { Join-Path $env:NVM_SYMLINK 'node.exe' })
    )
    $node = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
    if (!$node) { throw 'node.exe not found (PATH, running node, Codex runtime, Program Files)' }
    Write-Host "== 1. frontend build ($node) =="
    Push-Location (Join-Path $root 'frontend')
    try { & $node 'node_modules/vite/bin/vite.js' build; if ($LASTEXITCODE -ne 0) { throw "vite build failed ($LASTEXITCODE)" } } finally { Pop-Location }

    Write-Host '== 2. package =='
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    $frontendTar = Join-Path $work 'frontend.tar.gz'
    $backendTar = Join-Path $work 'backend.tar.gz'
    tar.exe -czf $frontendTar -C (Join-Path $root 'frontend\dist') .
    if ($LASTEXITCODE -ne 0) { throw 'frontend archive failed' }
    # config(접속 정보)는 서버 것을 그대로 두고 api, lib만 올린다.
    tar.exe -czf $backendTar -C (Join-Path $root 'backend') api lib
    if ($LASTEXITCODE -ne 0) { throw 'backend archive failed' }

    Write-Host '== 3. upload =='
    Remote "mkdir -p $remote/stage"
    & scp.exe @scpOpts $frontendTar $backendTar "${RemoteHost}:$remote/"
    if ($LASTEXITCODE -ne 0) { throw "upload failed ($LASTEXITCODE)" }

    Write-Host '== 4. check on server =='
    $php = '/opt/bitnami/php/bin/php'
    Remote "set -e; cd $remote/stage; tar -xzf ../backend.tar.gz; test -f /opt/bitnami/apache/htdocs/api/playerProfile.php; test -d /opt/bitnami/apache/htdocs/lib; find api lib -name '*.php' -print0 | while IFS= read -r -d '' f; do out=`$($php -l `"`$f`" 2>&1) || echo `"`$f :: `$out`"; done > ../lint.txt; if grep -q 'Parse error\|Errors parsing\|Fatal error' ../lint.txt; then cat ../lint.txt; exit 1; fi; if [ -s ../lint.txt ]; then echo 'lint warnings (not syntax errors):'; cat ../lint.txt; fi; echo php-lint-ok"

    Write-Host '== 5. backup and deploy =='
    Remote "set -e; mkdir -p /home/bitnami/deploy-backups; tar -czf /home/bitnami/deploy-backups/htdocs-before-$id.tar.gz -C /opt/bitnami/apache/htdocs api lib index.html; sudo -n cp -R $remote/stage/api/. /opt/bitnami/apache/htdocs/api/; sudo -n cp -R $remote/stage/lib/. /opt/bitnami/apache/htdocs/lib/; mkdir -p $remote/front; tar -xzf $remote/frontend.tar.gz -C $remote/front; mv $remote/front/index.html $remote/index.html.new; sudo -n cp -R $remote/front/. /opt/bitnami/apache/htdocs/; sudo -n cp $remote/index.html.new /opt/bitnami/apache/htdocs/index.html; sudo -n /opt/bitnami/apache/bin/apachectl -k graceful; echo deployed-$id; echo backup=/home/bitnami/deploy-backups/htdocs-before-$id.tar.gz"
    Remote "rm -rf $remote"
    Write-Host 'DONE'
} catch {
    Write-Host "ERROR: $_"
} finally {
    if (Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force }
    Stop-Transcript | Out-Null
}
