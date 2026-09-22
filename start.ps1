$ErrorActionPreference = "Stop"

$RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiDir = Join-Path $RootDir "API\NUTRIX-API"
$FrontDir = Join-Path $RootDir "Interface Client\NUTRIX-InterfaceClient"

Write-Host "==> Demarrage du serveur Symfony (arriere-plan)"
Set-Location $ApiDir
symfony server:start -d --no-tls

try {
    Write-Host "==> Demarrage du front React (Ctrl+C pour arreter)"
    Set-Location $FrontDir
    npm run dev
}
finally {
    Write-Host ""
    Write-Host "==> Arret du serveur Symfony"
    Set-Location $ApiDir
    symfony server:stop
}
