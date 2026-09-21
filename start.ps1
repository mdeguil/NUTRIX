$ErrorActionPreference = "Stop"

$RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiDir = Join-Path $RootDir "API\NUTRIX-API"

Write-Host "==> Demarrage de la base de donnees (Docker)"
Set-Location $RootDir
docker compose up -d

Write-Host "==> Demarrage du serveur Symfony (Ctrl+C pour arreter)"
Set-Location $ApiDir
symfony server:start --no-tls
