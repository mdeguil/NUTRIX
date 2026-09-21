$ErrorActionPreference = "Stop"

$RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiDir = Join-Path $RootDir "API\NUTRIX-API"

Write-Host "==> Demarrage de la base de donnees (Docker)"
Set-Location $RootDir
docker compose up -d

Write-Host "==> Attente que MySQL soit pret..."
$status = ""
while ($status -ne "healthy") {
    Start-Sleep -Seconds 2
    $status = docker inspect --format='{{.State.Health.Status}}' nutrix_mysql 2>$null
    Write-Host "    ... statut: $status"
}
Write-Host "==> MySQL est pret"

Write-Host "==> Installation des dependances Symfony"
Set-Location $ApiDir
composer install

if (-not (Test-Path ".env.local")) {
    Write-Host "==> Creation de .env.local depuis le gabarit"
    Copy-Item ".env.local.example" ".env.local"
} else {
    Write-Host "==> .env.local existe deja, on ne le touche pas"
}

Write-Host "==> Verification de la connexion a la base"
php bin/console doctrine:query:sql "SELECT 1" | Out-Null

if (Test-Path "migrations\Version*.php") {
    Write-Host "==> Application des migrations"
    php bin/console doctrine:migrations:migrate --no-interaction
} else {
    Write-Host "==> Aucune migration pour le moment (pas encore d'entite creee)"
}

Write-Host ""
Write-Host "Projet initialise. Lance .\start.ps1 pour demarrer le serveur."
