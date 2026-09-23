# NUTRIX — API

**Backend du système de planification alimentaire NUTRIX, pour un vaisseau spatial / habitat isolé.**

NUTRIX relie l'équipage, ses besoins nutritionnels, les stocks, les repas et la production agricole. Il répond à une question : *combien de besoins humains la nourriture disponible permet-elle encore de couvrir, et que faut-il produire pour tenir ?*

Ce dépôt contient l'**API REST** du projet, en Symfony + API Platform. Elle fait trois choses :

- elle expose les données du vaisseau en CRUD : équipage, aliments, stocks, récoltes, recettes, repas ;
- elle calcule, avec son **moteur de calcul**, les besoins nutritionnels, l'autonomie du stock, les prévisions sur 8 semaines, les besoins agricoles et un planning de repas qui évite la lassitude ;
- elle gère l'authentification par JWT.

---

## Sommaire

- [Fonctionnalités](#fonctionnalités)
- [Stack technique](#stack-technique)
- [Architecture](#architecture)
- [Modèle de données](#modèle-de-données)
- [Moteur de calcul](#moteur-de-calcul)
- [Endpoints](#endpoints)
- [Authentification](#authentification)
- [Prise en main](#prise-en-main)
- [Tests et CI](#tests-et-ci)
- [Sécurité et secrets](#sécurité-et-secrets)
- [Documentation de référence](#documentation-de-référence)
- [Commandes utiles](#commandes-utiles)

---

## Fonctionnalités

| Domaine | Ce que fait l'API |
|---|---|
| Besoins nutritionnels | Calcule les besoins de chaque équipier à partir des valeurs de référence de l'EFSA : énergie, macronutriments, fibres, eau, 12 minéraux et 14 vitamines. Calcule aussi les besoins de toute l'équipe et par créneau de repas. |
| Recettes | Calcule le profil nutritionnel d'une recette et vérifie les valeurs stockées en les recalculant depuis les ingrédients. |
| Planning des repas | **Simule** la couverture nutritionnelle d'un ensemble de repas choisis. **Génère** automatiquement un planning qui tient compte des besoins, des allergies, du stock, des dates de péremption et de la rotation des recettes. |
| Journal alimentaire | Enregistre les repas réellement consommés. Calcule l'écart entre apports réels et besoins, et corrige les plannings suivants en conséquence. |
| Stock | Sort les produits en FEFO (le premier à périmer sort en premier). Répartit le stock en réserves (courante, sécurité, urgence, stratégique). Exprime le stock en **jours d'autonomie** pour chaque nutriment. |
| Prévisions | Projette sur 8 semaines les besoins, les récoltes, la consommation, le stock et les pertes. Détecte les périodes à risque. |
| Agriculture | Traduit les déficits en besoins de production : quantités, surface, semences, eau, énergie. |

## Stack technique

| Élément | Technologie |
|---|---|
| Langage | PHP ≥ 8.2 |
| Framework | Symfony 7.4 |
| API REST | API Platform 5 (JSON-LD / Hydra, OpenAPI) |
| ORM / migrations | Doctrine ORM 3, Doctrine Migrations |
| Base de données | MySQL 8, hébergée, partagée par l'équipe et déjà remplie |
| Référentiel nutritionnel | Valeurs de référence EFSA (version 4, 2017) dans [`efsa_drv_reference.json`](./efsa_drv_reference.json) |
| Authentification | JWT via `lexik/jwt-authentication-bundle` |
| CORS | `nelmio/cors-bundle` |
| Tests | PHPUnit 11, `doctrine-fixtures-bundle` |
| CI | GitHub Actions |
| Serveur de dev | Symfony CLI |

## Architecture

```text
Client (front, curl, Postman…)
        │  HTTP + JSON, header Authorization: Bearer <JWT>
        ▼
┌──────────────────────────────────────────────────────────┐
│ Symfony (public/index.php)                               │
│                                                          │
│  Security : firewall "login" (json_login → JWT)          │
│             firewall "api"   (vérifie le JWT)            │
│                                                          │
│  ┌───────────────────────┐   ┌────────────────────────┐  │
│  │ API Platform          │   │ Contrôleurs métier     │  │
│  │ CRUD généré depuis    │   │ besoins, planning,     │  │
│  │ src/Entity/*          │   │ stock, prévisions,     │  │
│  │ (#[ApiResource])      │   │ agriculture, auth      │  │
│  └──────────┬────────────┘   │                        │  │
│             │                └──────────┬─────────────┘  │
│             │                           ▼                │
│             │                ┌────────────────────────┐  │
│             │                │ Moteur de calcul       │◄─┼── efsa_drv_reference.json
│             │                │ (services, sans état)  │  │
│             │                └──────────┬─────────────┘  │
│             ▼                           ▼                │
│        Doctrine ORM  +  migrations (migrations/)         │
└─────────────────────────────┬────────────────────────────┘
                              ▼
                  MySQL (base hébergée et remplie)
```

Il y a deux types de routes :

- **Le CRUD** : chaque entité porte l'attribut `#[ApiResource]`, et API Platform génère à partir de lui les routes, la sérialisation, la validation et la documentation OpenAPI.
- **Les routes métier** : elles appellent le moteur de calcul. Les résultats calculés (besoins, autonomie, prévisions, scores) ne sont **jamais enregistrés en base**. Ils sont recalculés à chaque requête à partir des données et du référentiel EFSA. Seules la génération de planning, l'enregistrement d'un repas et les sorties de stock écrivent en base.

### Arborescence

```text
API/NUTRIX-API/
├── config/
│   ├── packages/          # config des bundles (security, api_platform, doctrine, jwt, cors…)
│   └── jwt/               # clés JWT générées localement (non versionnées)
├── migrations/            # migrations Doctrine (schéma de la base)
├── src/
│   ├── Controller/        # auth + routes métier (besoins, planning, stock…)
│   ├── Service/           # moteur de calcul
│   ├── DataFixtures/      # données de référence + comptes de démo
│   ├── Entity/            # entités Doctrine = ressources API
│   └── Repository/
├── tests/                 # tests fonctionnels PHPUnit
├── .env                   # valeurs par défaut, sans secret (versionné)
├── .env.local.example     # gabarit pour .env.local
└── .env.test              # config de l'environnement de test
```

## Modèle de données

La base hébergée contient déjà le catalogue d'aliments et de recettes, l'équipage, les stocks, les récoltes et l'historique des repas.

| Domaine | Tables / entités | Rôle |
|---|---|---|
| Équipage | `Equipage`, `ActivityLabel` | Profil d'un occupant : sexe, âge, poids, taille, IMC, PAL (niveau d'activité). Peut être lié en 1-1 à un compte `User`. |
| Allergies | `Allergene`, `OccupantAllergie`, `AlimentAllergene` | Qui est allergique à quoi, et quel aliment contient quel allergène |
| Comptes | `User` | Username, rôles, mot de passe hashé. Lecture seule via l'API. |
| Aliments | `Aliment`, `CategorieIngredient`, `UniteStock` | Valeurs nutritionnelles pour 100 g, cycle de culture, rendement (g/m²/jour). La clé est un code texte (ex. `RIZ`). |
| Recettes | `Recette`, `CategorieRecette`, `RecetteIngredient`, `RecetteTypeRepas` | Profil nutritionnel, composition en grammes, créneaux où la recette peut être servie |
| Stock | `LotStock`, `MouvementStock`, `Asso11` | Lots (QR code, quantités, péremption, emplacement, type de réserve, statut), mouvements d'entrée et de sortie, lien entre un mouvement et une recette |
| Agriculture | `Recolte` | Cultures : module, dates de semis et de récolte (prévue et réelle), quantités, taux de perte, statut |
| Repas | `TypeRepas`, `PlanningRepas`, `PlanningRepasOccupant`, `JournalRepas` | Repas prévus, part de chaque occupant (`portion_ratio`), repas réellement consommés |

Valeurs imposées par validation :

- `LotStock.typeReserve` : `courante`, `securite`, `urgence`, `strategique`
- `LotStock.statut` : `frais`, `transforme`, `congele`, `epuise`, `perime`
- `Recolte.statut` : `semis`, `croissance`, `recolte`, `perdue`, `replantee`

Conventions à connaître :

- `Equipage.sexe` est un booléen : `true` pour un homme, `false` pour une femme. Le moteur le convertit en `M` ou `F` pour lire les tables EFSA.
- Pour lier un profil `Equipage` à un compte, on envoie l'IRI (`{"user": "/api/users/5"}`). En lecture, seul `userId` est renvoyé.
- Un repas hors catalogue s'enregistre avec une recette générique « Repas libre ».
- Les ingrédients d'une recette se lisent via `GET /api/recettes/{id}/ingredients`.

## Moteur de calcul

Les formules détaillées sont dans [`MOTEUR_CALCUL.md`](./MOTEUR_CALCUL.md). En voici l'enchaînement :

```text
Equipage ──(EFSA)──► besoins individuels ──► besoins équipe / par créneau ─┐
Recette + ingrédients + Aliment ──► profil nutritionnel recette ───────────┤
LOT_STOCK + MOUVEMENT_STOCK ──(FEFO, réserves)──► stock utilisable ────────┼──► score recette × créneau
JOURNAL_REPAS + PLANNING_REPAS ──► historique (rotation) ──────────────────┘            │
        ▲                                                             tirage pondéré (top-K)
        │                                                                                ▼
        └──── écart réel / théorique ◄──── journal ◄──── PLANNING_REPAS généré ──► autonomie / prévisions 8 sem.
                                                                                          │
                                                          besoins agricoles ◄─────────────┤
                                                          alertes (ruptures, semis) ◄─────┘
```

1. **Besoins individuels.** Le moteur trouve la tranche d'âge de l'équipier dans le référentiel EFSA, puis :
   - énergie = besoin moyen × PAL ;
   - protéines = valeur de référence × poids ;
   - glucides et lipides = fourchettes exprimées en % de l'énergie (la fourchette des lipides dépend de l'âge) ;
   - fibres, eau, minéraux et vitamines = lus directement dans le référentiel ;
   - niacine et thiamine = calculées à partir de l'énergie (en MJ).

   Deux hypothèses par défaut, signalées dans le champ `meta` de la réponse : le zinc suppose un apport en phytates de 600 mg/jour, et le fer suppose un statut ménopausique déduit de l'âge (55 ans ou plus).
2. **Agrégation.** Le besoin de l'équipe est la somme des besoins individuels. Il est réparti entre les créneaux : petit-déjeuner 20 %, déjeuner 35 %, dîner 35 %, collation 10 %. Les équipiers sont regroupés selon leurs allergies.
3. **Profil d'une recette.** Il est lu sur la `Recette`, puis vérifié en recalculant la somme pondérée de ses ingrédients.
4. **Filtrage des recettes.** Une recette est retenue si elle ne contient aucun allergène du groupe, si elle convient au créneau et si le stock suffit pour le nombre de portions.
5. **Stock.** Seule la réserve `courante` est utilisable en temps normal : lots non périmés, au statut `frais`, `transforme` ou `congele`. Les sorties se font en FEFO. Un score favorise les produits proches de la péremption, un autre protège les seuils de réserve.
6. **Rotation des recettes.** Trois scores évitent de servir trop souvent la même chose : délai depuis le dernier service (avec un délai minimal obligatoire), équité de fréquence sur 14 jours, diversité par rapport au repas précédent.
7. **Sélection.** Score final = 0,4 × nutrition + 0,3 × rotation + 0,2 × péremption + 0,1 × réserve. La recette est tirée au sort parmi les 3 meilleures, avec une probabilité proportionnelle au score. Si aucune recette ne convient, le moteur assouplit les contraintes (délai minimal, puis réserve de sécurité), puis lève une alerte « rupture de menu ».
8. **Autonomie.** Pour chaque nutriment, autonomie = stock disponible / besoin journalier, en jours. L'autonomie globale est la plus petite de ces valeurs (le nutriment limitant). Une variante inclut les récoltes prévues.
9. **Prévision sur 8 semaines.** Le moteur part du stock actuel, ajoute les récoltes prévues (moins les pertes), retire la consommation planifiée et les pertes par péremption. Il en déduit le déficit et les semaines à risque.
10. **Besoins agricoles.** Chaque déficit est réparti entre les aliments selon leur contribution, puis converti en quantité, surface, semences, eau et énergie à produire.
11. **Rétroaction.** Si un nutriment reste en déficit plusieurs jours de suite dans le journal, son poids augmente dans le score nutrition des prochains plannings.

Les paramètres par défaut (poids des scores, fenêtre de 14 jours, K = 3, seuil de péremption à 7 jours, horizon de 7 à 14 jours) sont listés en §14 de `MOTEUR_CALCUL.md`.

## Endpoints

La liste complète des routes et des schémas est dans la documentation interactive : **http://127.0.0.1:8000/api/docs**, une fois le serveur lancé. Les formats d'entrée et de sortie des routes métier sont détaillés dans [`API_BESOINS_PLANNING.md`](./API_BESOINS_PLANNING.md).

### Routes métier

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/equipages/{id}/besoins` | Besoins d'un équipier. |
| GET | `/api/equipages/besoins?date=` | Besoins de l'équipe : total et détail par équipier |
| GET | `/api/equipages/besoins/creneau?date=&type_repas=` | Besoins d'un créneau, par groupe d'allergies |
| GET | `/api/equipages/{id}/ecart-nutritionnel?periode_jours=7` | Écart entre apports réels et besoins |
| GET | `/api/recettes/{id}/profil-nutritionnel` | Profil stocké comparé au profil recalculé depuis les ingrédients |
| POST | `/api/planning-repas/simuler` | Couverture nutritionnelle d'un ensemble de repas. N'écrit rien en base. |
| POST | `/api/planning-repas/generer` | Génère le planning sur un horizon donné et l'enregistre |
| GET | `/api/planning-repas?date_debut=&date_fin=&equipage_id=` | Lit le planning, pour toute l'équipe ou pour un occupant |
| POST | `/api/journal-repas` | Enregistre un repas consommé et renvoie son apport |
| GET | `/api/stock/autonomie` | Autonomie globale et par nutriment, en jours |
| GET | `/api/stock/previsions?semaines=8` | Prévision semaine par semaine et périodes à risque |
| GET | `/api/stock/besoins-agricoles?semaine=` | Production nécessaire pour chaque aliment |

Toutes les routes qui renvoient un besoin ou un apport utilisent le même format de sortie, `BesoinNutritionnel`. Il contient l'énergie, les macronutriments, les fibres, l'eau, les minéraux, les vitamines, et un champ `meta` qui liste les hypothèses utilisées.

Exemple de simulation :

```bash
curl -X POST http://127.0.0.1:8000/api/planning-repas/simuler \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{
        "equipage_ids": [1, 2, 3],
        "repas": [
          { "date": "2026-09-23", "type_repas": "Dejeuner", "recette_id": 9, "portions": 3 },
          { "date": "2026-09-23", "type_repas": "Diner",    "recette_id": 32, "portions": 3 }
        ]
      }'
```

### Routes CRUD (API Platform)

Chaque entité du [modèle de données](#modèle-de-données) a ses routes `GET` (liste et détail), `POST`, `PUT`, `PATCH` et `DELETE` sous `/api/...`. Exception : `User` est en lecture seule.

## Authentification

L'API est *stateless*. Toutes les routes sous `/api` exigent un JWT valide, sauf `/api/login`, `/api/register` et `/api/docs`.

| Route | Méthode | Accès | Rôle |
|---|---|---|---|
| `/api/login` | POST | public | Renvoie un token JWT |
| `/api/register` | POST | public | Crée un compte `ROLE_OCCUPANT` ou `ROLE_FERME` |
| `/api/me` | GET | authentifié | Renvoie l'utilisateur courant |

Il existe trois rôles : `ROLE_ADMIN`, `ROLE_OCCUPANT` et `ROLE_FERME`. `ROLE_ADMIN` ne peut pas être obtenu par inscription. Il s'attribue à la main, par exemple via les fixtures.

Comptes de démo (mot de passe `password123`) : `admin`, `occupant`, `ferme`.

```bash
# Se connecter
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"occupant","password":"password123"}'

# Appeler l'API avec le token
curl http://127.0.0.1:8000/api/me -H "Authorization: Bearer <token>"

# S'inscrire
curl -X POST http://127.0.0.1:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"username":"nouveau","password":"password123","role":"ROLE_OCCUPANT"}'
```

## Prise en main

### Prérequis

- PHP ≥ 8.2 avec les extensions `intl`, `pdo_mysql`, `mbstring` et `openssl`
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- Les identifiants de la base MySQL hébergée. **Il n'y a pas de base locale** : toute l'équipe travaille sur la même base, déjà remplie. Demandez le `DATABASE_URL` à l'équipe.

### Installation manuelle

```bash
git clone https://github.com/mdeguil/NUTRIX.git
cd NUTRIX/API/NUTRIX-API

# 1. Dépendances
composer install

# 2. Configuration locale (non versionnée)
cp .env.local.example .env.local
```

Complétez ensuite `.env.local` :

```dotenv
DATABASE_URL="mysql://USER:PASSWORD@HOTE:3306/NOM_BASE?serverVersion=8.0.32&charset=utf8mb4"
APP_SECRET=<chaîne aléatoire>
JWT_PASSPHRASE=<chaîne aléatoire>
```

Pour générer une valeur aléatoire : `php -r "echo bin2hex(random_bytes(16));"`

Créez aussi `.env.test.local` avec la **même** valeur `JWT_PASSPHRASE`. Symfony ignore `.env.local` en environnement de test.

```bash
# 3. Clés JWT (config/jwt/*.pem)
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# 4. Vérifier la connexion à la base
php bin/console doctrine:query:sql "SELECT 1"

# 5. Vérifier que le schéma est à jour (la base partagée est normalement déjà migrée)
php bin/console doctrine:migrations:status

# 6. Lancer le serveur
symfony server:start -d --no-tls
```

L'API répond alors sur http://127.0.0.1:8000/api, et sa documentation est sur http://127.0.0.1:8000/api/docs.

Pour vérifier que tout fonctionne, connectez-vous avec un compte de démo, puis appelez `GET /api/stock/autonomie` ou `GET /api/equipages/1/besoins`.

### Via les scripts

Les scripts à la racine du dépôt font les étapes ci-dessus automatiquement : `.sh` pour Git Bash ou WSL, `.ps1` pour PowerShell.

```bash
./init.sh     # première installation : composer, .env.local + secrets, clés JWT, migrations
./start.sh    # démarre le serveur Symfony (Ctrl+C pour tout arrêter)
```

À la première exécution, `init` crée `.env.local` avec des secrets générés. Il faut ensuite y renseigner le `DATABASE_URL` puis relancer le script. Un `.env.local` existant n'est jamais modifié.

> Les scripts installent et lancent aussi le client React présent dans le dépôt. Il faut donc Node.js et npm pour les utiliser. Si vous ne travaillez que sur l'API, suivez l'installation manuelle.

### Modifier le schéma

La base est partagée par toute l'équipe. Tout changement de schéma passe donc par une migration Doctrine, versionnée dans `migrations/` :

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

⚠️ `php bin/console doctrine:fixtures:load` **vide la base avant de la remplir à nouveau**. Ne lancez jamais cette commande sur la base partagée : elle effacerait les données réelles. Elle ne sert que sur une base de test.

## Tests et CI

Les tests suppriment et recréent des données. Ils doivent donc tourner sur une base `nutrix_test` distincte de la base partagée. Configurez son `DATABASE_URL` dans `.env.test.local`.

```bash
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit
```

La CI (`.github/workflows/ci.yml`) se lance à chaque push et à chaque PR vers `main`. Le job API :

1. installe PHP 8.2 et les dépendances Composer ;
2. vérifie la syntaxe PHP de `src/` et le YAML de `config/` ;
3. génère les clés JWT ;
4. applique les migrations sur un conteneur MySQL 8 jetable, propre au run ;
5. exécute `php bin/phpunit`.

La CI a besoin de deux secrets GitHub, à définir dans **Settings → Secrets and variables → Actions** : `APP_SECRET` et `JWT_PASSPHRASE`. S'ils manquent, le job échoue avec `Environment variable not found`.

## Sécurité et secrets

- `.env` est versionné et ne contient **aucun secret réel**. Son `DATABASE_URL` PostgreSQL n'est qu'un exemple, remplacé par celui de `.env.local`.
- `APP_SECRET`, `JWT_PASSPHRASE` et `DATABASE_URL` se définissent dans `.env.local`, qui n'est pas versionné.
- Les clés `config/jwt/*.pem` sont générées en local et ne sont pas versionnées.
- Le CORS n'autorise par défaut que `localhost` et `127.0.0.1`, quel que soit le port (`CORS_ALLOW_ORIGIN`).

## Documentation de référence

| Fichier | Contenu |
|---|---|
| [`MOTEUR_CALCUL.md`](./MOTEUR_CALCUL.md) | Toutes les formules du moteur : besoins, stock, rotation, scoring, prévisions, agriculture, rétroaction. Liste aussi les paramètres. |
| [`API_BESOINS_PLANNING.md`](./API_BESOINS_PLANNING.md) | Spécification des routes métier : entrée, sortie, codes d'erreur, format `BesoinNutritionnel` |
| [`efsa_drv_reference.json`](./efsa_drv_reference.json) | Valeurs de référence EFSA par tranche d'âge et par sexe : énergie par PAL, protéines, glucides et lipides, fibres, eau, minéraux, vitamines, ajustements grossesse et allaitement |

## Commandes utiles

```bash
php bin/console debug:router                 # lister les routes
php bin/console make:entity                  # créer ou modifier une entité
php bin/console make:migration               # générer une migration depuis les entités
php bin/console doctrine:migrations:migrate  # appliquer les migrations
php bin/console doctrine:migrations:status   # état des migrations
php bin/console cache:clear
symfony server:log                           # logs du serveur
symfony server:stop                          # arrêter le serveur
```
