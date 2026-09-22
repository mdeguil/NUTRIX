$ErrorActionPreference = "Stop"

$RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiDir = Join-Path $RootDir "API\NUTRIX-API"
$FrontDir = Join-Path $RootDir "Interface Client\NUTRIX-InterfaceClient"

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
    Write-Host "==> Creation de .env.local depuis le gabarit (+ secrets locaux generes)"
    Copy-Item ".env.local.example" ".env.local"
    $appSecret = -join ((1..32) | ForEach-Object { "{0:x}" -f (Get-Random -Maximum 16) })
    $jwtPassphrase = -join ((1..32) | ForEach-Object { "{0:x}" -f (Get-Random -Maximum 16) })
    Add-Content ".env.local" "APP_SECRET=$appSecret"
    Add-Content ".env.local" "JWT_PASSPHRASE=$jwtPassphrase"
    Set-Content ".env.test.local" "JWT_PASSPHRASE=$jwtPassphrase"
} else {
    Write-Host "==> .env.local existe deja, on ne le touche pas"
}

if (-not (Test-Path ".env.test.local")) {
    Write-Host "==> Creation de .env.test.local (JWT_PASSPHRASE synchronisee avec .env.local)"
    $line = Select-String -Path ".env.local" -Pattern "^JWT_PASSPHRASE=" | Select-Object -First 1
    Set-Content ".env.test.local" $line.Line
}

Write-Host "==> Generation des cles JWT (si absentes)"
php bin/console lexik:jwt:generate-keypair --skip-if-exists

Write-Host "==> Verification de la connexion a la base"
php bin/console doctrine:query:sql "SELECT 1" | Out-Null

if (Test-Path "migrations\Version*.php") {
    Write-Host "==> Application des migrations"
    php bin/console doctrine:migrations:migrate --no-interaction
} else {
    Write-Host "==> Aucune migration pour le moment (pas encore d'entite creee)"
}

Write-Host "==> Chargement des fixtures (donnees de demonstration)"
php bin/console doctrine:fixtures:load --no-interaction

Write-Host "==> Installation des dependances du front React"
Set-Location $FrontDir
npm install

Write-Host ""
Write-Host "Projet initialise. Lance .\start.ps1 pour demarrer le serveur."
