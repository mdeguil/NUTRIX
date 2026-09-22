# NUTRIX

**Système autonome de planification alimentaire pour vaisseau spatial / habitat isolé.**

> Un système capable de prévoir les besoins nutritionnels de l'équipage, gérer les réserves, planifier les repas, anticiper les pénuries et transmettre à l'agriculture les besoins de production nécessaires au maintien de l'autonomie du vaisseau.

NUTRIX ne se limite pas à répondre à *« combien de nourriture reste-t-il ? »*. Il répond à *« combien de besoins humains cette nourriture permet-elle encore de couvrir ? »*, et relie occupants, stocks, journal alimentaire et production agricole dans une seule chaîne décisionnelle.

```text
Occupants → besoins nutritionnels → prévision 8 semaines → repas → consommation
→ stocks → récoltes → besoins agricoles → eau / nutriments / énergie → production → stocks → occupants
```

Le système doit pouvoir fonctionner **hors ligne**, conformément à la contrainte d'autonomie du vaisseau.

---

## Sommaire

- [Concept](#concept)
- [Fonctionnalités](#fonctionnalités)
- [Architecture](#architecture)
- [Stack technique](#stack-technique)
- [Modules](#modules)
- [Démarrage rapide](#démarrage-rapide)

---

## Concept

À partir des occupants, de leurs besoins nutritionnels, des stocks, des récoltes prévues et des contraintes de conservation, NUTRIX détermine :

- ce que l'équipage doit consommer ;
- ce qu'il faut produire ;
- ce qu'il faut conserver pour les semaines à venir.

## Fonctionnalités

### 1. Base de données des occupants
Profil nutritionnel par occupant : âge, poids, taille, sexe, niveau d'activité, contraintes alimentaires, allergies, intolérances, préférences, besoins énergétiques/protéines/glucides/lipides/fibres/hydriques, micronutriments suivis.

### 2. Journal alimentaire
Historique des repas consommés par occupant, comparé aux besoins théoriques pour calculer l'écart nutritionnel et adapter les repas suivants.

### 3. Stock alimentaire intelligent
Fiche produit complète (catégorie, quantités, valeurs nutritionnelles, dates de péremption, conservation, emplacement, réserve minimale), identification par QR code, et calcul de l'**autonomie alimentaire** en jours (stock actuel, après planification, avec récoltes prévues).

### 4. Prévision sur 8 semaines
Croise population, besoins individuels, historique de consommation, stock, production agricole prévue, dates de récolte, rendement et pertes estimées pour détecter les périodes à risque.

### 5. Connexion avec la ferme
Échange bidirectionnel avec le système agricole : NUTRIX transmet les besoins de production (déficits prévus), la ferme transmet ses récoltes prévues.

### 6. Planification des plantations
Déduit des besoins alimentaires la production nécessaire, puis la surface agricole, les semences, l'eau, les nutriments et l'énergie requis.

### 7. Prévision des ressources agricoles
Remonte aux systèmes du vaisseau (agriculture, eau, énergie) les besoins projetés à plusieurs semaines.

### 8. Gestion des réserves stratégiques
Le stock total est réparti en consommation courante, stock de sécurité, stock d'urgence et réserve stratégique — jamais 100 % du stock n'est considéré comme disponible.

### 9. Simulation de crise
Scénarios interactifs (perte de récolte, arrivée de nouveaux occupants, baisse de production) avec recalcul immédiat de l'autonomie et propositions d'adaptation.

### 10. Optimisation des repas
Planificateur de recettes respectant simultanément les contraintes humaines (besoins, allergies, restrictions), logistiques (stock, péremption, emplacement), agricoles (production, récoltes) et stratégiques (réserves minimales).

### 11. Valeur réelle du stock
Affichage du stock en équivalent jours de couverture par macronutriment (calories, protéines, lipides, fibres, eau) plutôt qu'en quantités brutes, pour révéler le goulot d'étranglement nutritionnel.

---

## Architecture

```text
                    ┌──────────────────┐
                    │   Occupants      │
                    │ profils/besoins  │
                    └────────┬─────────┘
                             │
                             ▼
┌──────────────┐      ┌──────────────────┐      ┌──────────────┐
│   Stocks     │─────►│                  │◄─────│ Agriculture  │
│ QR / BDD     │      │     NUTRIX       │      │ récoltes     │
└──────────────┘      │                  │      └──────────────┘
                      │ Planificateur    │
┌──────────────┐      │ alimentaire      │      ┌──────────────┐
│ Journal      │─────►│                  │─────►│ Production   │
│ repas        │      └────────┬─────────┘      │ nécessaire   │
└──────────────┘               │                └──────────────┘
                               ▼
                      ┌──────────────────┐
                      │ Prévisions 8 sem │
                      └────────┬─────────┘
                               ▼
                      ┌──────────────────┐
                      │ Alertes / crises │
                      └──────────────────┘
```

## Stack technique

**Backend**
- Node.js / Python
- PostgreSQL
- API REST

**Frontend**
- React
- Graphiques, calendrier alimentaire, carte des stocks

**Hardware**
- Raspberry Pi
- Lecteur QR
- ESP32 (optionnel)
- Balance connectée
- Capteurs de température des réserves

**Communication**
- MQTT (interconnexion avec le système agricole / la serre)

## Modules

| Module | Rôle |
|---|---|
| Occupants | Gestion des profils et besoins nutritionnels |
| Journal alimentaire | Suivi de la consommation réelle |
| Stock | Inventaire, péremption, autonomie alimentaire |
| Prévision | Projection à 8 semaines (besoins vs. stock + récoltes) |
| Agriculture | Interface bidirectionnelle avec la production |
| Réserves stratégiques | Répartition consommation / sécurité / urgence |
| Simulation | Scénarios de crise et recalcul d'autonomie |
| Planificateur de repas | Génération de menus sous contraintes |

---

## Démarrage rapide

Le projet est découpé en parties indépendantes :

| Dossier | Rôle | Où ça tourne |
|---|---|---|
| `BDD/` | Modélisation (MCD/MLD) | — (base réelle hébergée, pas de conteneur local) |
| `API/NUTRIX-API/` | API Symfony + API Platform | En local sur la machine |
| `Interface Client/NUTRIX-InterfaceClient/` | Application React + Vite | En local sur la machine |

**Pas de base de données locale** : tout le monde se connecte à la même base MySQL mutualisée chez l'hébergeur. Demande les identifiants à l'équipe avant de commencer.

### Prérequis

- PHP ≥ 8.2 et [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- Node.js et npm

### Via les scripts (le plus rapide)

Deux scripts à la racine automatisent tout ce qui suit (Bash `.sh` pour Git Bash/WSL, PowerShell `.ps1` pour un terminal Windows natif) :

```bash
./init.sh   # premiere installation : composer install, .env.local, migrations, npm install
./start.sh  # demarrage au quotidien : serveur Symfony (arriere-plan) + front React (Ctrl+C pour tout arreter)
```

```powershell
.\init.ps1
.\start.ps1
```

`init` ne touche pas à un `.env.local` déjà existant. La première fois, il faudra renseigner le `DATABASE_URL` de la base hébergée dans `API/NUTRIX-API/.env.local` avant que le script puisse continuer. Le détail manuel des étapes est ci-dessous si besoin de dépanner.

### 1. Cloner le projet

```bash
git clone https://github.com/mdeguil/NUTRIX.git
cd NUTRIX
```

### 2. Configurer et lancer l'API Symfony

```bash
cd API/NUTRIX-API
composer install
```

Copier le fichier gabarit en `.env.local` (non versionné) et renseigner le `DATABASE_URL` fourni par l'équipe (base hébergée) :

```bash
cp .env.local.example .env.local
```

Vérifier la connexion à la base :

```bash
php bin/console doctrine:query:sql "SELECT 1"
```

Appliquer les migrations existantes :

```bash
php bin/console doctrine:migrations:migrate
```

Démarrer le serveur :

```bash
symfony server:start -d --no-tls
```

L'API est alors disponible sur http://127.0.0.1:8000/api.

### 3. Lancer le front React

```bash
cd "Interface Client/NUTRIX-InterfaceClient"
npm install
npm run dev
```

Le front lit l'URL de l'API depuis la variable `VITE_API_URL` (fichier `.env`, déjà configurée sur `http://127.0.0.1:8000`). Il est alors disponible sur http://localhost:5173.

### Authentification (JWT)

L'API est protégée par JWT (`lexik/jwt-authentication-bundle`). Trois rôles existent : `ROLE_ADMIN`, `ROLE_OCCUPANT`, `ROLE_FERME`.

Comptes de démo (créés par les fixtures, mot de passe `password123` pour les trois) :

| Username | Rôle |
|---|---|
| `admin` | ROLE_ADMIN |
| `occupant` | ROLE_OCCUPANT |
| `ferme` | ROLE_FERME |

**Se connecter** (retourne un token) :

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"occupant","password":"password123"}'
```

**Appeler l'API avec le token** :

```bash
curl http://127.0.0.1:8000/api/me \
  -H "Authorization: Bearer <token>"
```

**S'inscrire** (auto-inscription limitée à `ROLE_OCCUPANT` et `ROLE_FERME` — `ROLE_ADMIN` s'attribue manuellement, ex. via les fixtures) :

```bash
curl -X POST http://127.0.0.1:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"username":"nouveau","password":"password123","role":"ROLE_OCCUPANT"}'
```

### Arrêter l'environnement

```bash
symfony server:stop
```

(`Ctrl+C` suffit si tout a été lancé via `./start.sh` / `.\start.ps1`, qui arrête aussi le serveur Symfony automatiquement.)

## CI

Une CI GitHub Actions (`.github/workflows/ci.yml`) tourne sur chaque push/PR vers `main` :

- **API Symfony** : install des dépendances, lint PHP et YAML, migrations sur une base MySQL de test, exécution des tests (`php bin/phpunit`)
- **Front React** : install, `eslint`, `npm run build`

La base MySQL utilisée par la CI est un conteneur **jetable, propre à GitHub Actions** (`services:` dans `ci.yml`) — elle n'a aucun rapport avec la base hébergée utilisée en dev, et disparaît à la fin de chaque run. Aucune installation locale n'est requise pour ça.

Pour lancer les tests de l'API en local, il faut une base `nutrix_test` séparée de la base de dev partagée (car les tests suppriment/recréent des données). Selon ce que permet l'hébergeur, ça peut être une base supplémentaire sur le même compte, ou à défaut s'appuyer uniquement sur la CI pour la suite de tests complète :

```bash
cd API/NUTRIX-API
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit
```

### Secrets requis pour la CI

`APP_SECRET` et `JWT_PASSPHRASE` ne sont jamais commités (voir section Sécurité ci-dessous). La CI a donc besoin de ses propres valeurs, à définir une fois dans **Settings → Secrets and variables → Actions** du repo GitHub :

- `APP_SECRET` : une chaîne aléatoire (ex. générée avec `php -r "echo bin2hex(random_bytes(16));"`)
- `JWT_PASSPHRASE` : idem, une autre chaîne aléatoire

Sans ces secrets, le job `api` échoue avec une erreur `Environment variable not found`.

## Sécurité

- `API/NUTRIX-API/.env` (committé) ne contient **aucun secret réel** — `APP_SECRET` et `JWT_PASSPHRASE` doivent être définis dans `.env.local` (non versionné).
- `./init.sh` / `.\init.ps1` génèrent automatiquement ces secrets et les clés JWT (`config/jwt/*.pem`, non versionnées) lors de la première initialisation.
- `.env.test.local` (non versionné) contient la même `JWT_PASSPHRASE` que `.env.local`, car Symfony ignore volontairement `.env.local` en environnement de test — sans ce fichier, `php bin/phpunit` ne peut pas déchiffrer la clé JWT locale.
