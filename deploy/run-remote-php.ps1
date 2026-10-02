# deploy 폴더의 PHP CLI 스크립트를 서버에서 실행한다(서버 DB 설정 사용). 결과는 <스크립트명>.out.log에 남는다.
param(
    [Parameter(Mandatory=$true)][string]$Script,
    [string[]]$Arguments = @(),
    [string]$KeyPath = (Join-Path $env:USERPROFILE 'Desktop\WESIPER\wesiperxyz.pem'),
    [string]$RemoteHost = 'bitnami@3.35.252.78',
    [string]$RemoteDbConfig = '/opt/bitnami/apache/conf/wesiper-db.php'
)
$ErrorActionPreference = 'Stop'
$OutputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$path = Join-Path $PSScriptRoot $Script
$log = Join-Path $PSScriptRoot ([IO.Path]::GetFileNameWithoutExtension($Script) + '.out.log')
$args = ($Arguments + $RemoteDbConfig) -join ' '
$output = Get-Content -Raw -Encoding UTF8 -LiteralPath $path | & ssh.exe -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -i $KeyPath $RemoteHost "PATH=`$PATH:/opt/bitnami/php/bin; sudo -n -u daemon `$(command -v php) -- $args" 2>&1
[IO.File]::WriteAllText($log, (($output | Out-String) + "`nEXIT=$LASTEXITCODE`n"), [System.Text.UTF8Encoding]::new($false))
