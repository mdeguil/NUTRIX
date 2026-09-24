# NUTRIX

**Système autonome de planification alimentaire pour vaisseau spatial / habitat isolé.**

> Un système capable de prévoir les besoins nutritionnels de l'équipage, gérer les réserves, planifier les repas, anticiper les pénuries et transmettre à l'agriculture les besoins de production nécessaires au maintien de l'autonomie du vaisseau.

NUTRIX ne se limite pas à répondre à *« combien de nourriture reste-t-il ? »*. Il répond à *« combien de besoins humains cette nourriture permet-elle encore de couvrir ? »*, et relie occupants, stocks, journal alimentaire et production agricole dans une seule chaîne décisionnelle.

<<<<<<< Updated upstream:README.md
```text
Occupants → besoins nutritionnels → prévision 8 semaines → repas → consommation
→ stocks → récoltes → besoins agricoles → eau / nutriments / énergie → production → stocks → occupants
```

Le système doit pouvoir fonctionner **hors ligne**, conformément à la contrainte d'autonomie du vaisseau.
=======
- elle expose les données du vaisseau en CRUD : équipage, aliments, stocks, récoltes, recettes, repas ;
- elle calcule, avec son **moteur de calcul**, les besoins nutritionnels, l'autonomie du stock, les prévisions sur 8 semaines, les besoins agricoles et un planning de repas qui évite la lassitude ;
- elle gère l'authentification par JWT et les droits par rôle.

Le front React (interface client) est dans un dépôt séparé : [Own-667AI/NUTRIX](https://github.com/Own-667AI/NUTRIX). Il appelle cette API via sa variable `VITE_API_URL`.
>>>>>>> Stashed changes:README Back.md

---

## Sommaire

- [Concept](#concept)
- [Fonctionnalités](#fonctionnalités)
- [Architecture](#architecture)
<<<<<<< Updated upstream:README.md
- [Stack technique](#stack-technique)
- [Modules](#modules)
- [Démarrage rapide](#démarrage-rapide)
=======
- [Modèle de données](#modèle-de-données)
- [Moteur de calcul](#moteur-de-calcul)
- [Endpoints](#endpoints)
- [Authentification et droits](#authentification-et-droits)
- [Prise en main](#prise-en-main)
- [Tests et CI](#tests-et-ci)
- [Déploiement (Vercel)](#déploiement-vercel)
- [Sécurité et secrets](#sécurité-et-secrets)
- [Documentation de référence](#documentation-de-référence)
- [Commandes utiles](#commandes-utiles)
>>>>>>> Stashed changes:README Back.md

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

<<<<<<< Updated upstream:README.md
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
=======
| Élément | Technologie |
|---|---|
| Langage | PHP ≥ 8.2 (8.2 en CI, 8.4 dans l'image de production) |
| Framework | Symfony 7.4 |
| API REST | API Platform 5 (JSON-LD / Hydra, OpenAPI) |
| ORM / migrations | Doctrine ORM 3, Doctrine Migrations |
| Base de données | PostgreSQL 16 hébergé sur Supabase, partagé par l'équipe et déjà rempli |
| Référentiel nutritionnel | Valeurs de référence EFSA (version 4, 2017) dans [`config/nutrix/efsa_drv_reference.json`](./NUTRIX-API/config/nutrix/efsa_drv_reference.json) |
| Authentification | JWT via `lexik/jwt-authentication-bundle` |
| Limitation de débit | `symfony/rate-limiter` + `login_throttling` |
| CORS | `nelmio/cors-bundle` |
| Tests | PHPUnit 11 |
| CI | GitHub Actions |
| Serveur de dev | Symfony CLI |
| Production | Vercel (runtime *container*), image FrankenPHP |
>>>>>>> Stashed changes:README Back.md

## Architecture

```text
<<<<<<< Updated upstream:README.md
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
=======
Client (front React, curl, Postman…)
        │  HTTP + JSON, header Authorization: Bearer <JWT>
        ▼
┌──────────────────────────────────────────────────────────┐
│ Symfony (public/index.php)                               │
│                                                          │
│  Security : firewall "login" (json_login → JWT, throttle)│
│             firewall "api"   (vérifie le JWT)            │
│                                                          │
│  ┌───────────────────────┐   ┌────────────────────────┐  │
│  │ API Platform          │   │ Contrôleurs            │  │
│  │ CRUD généré depuis    │   │ AuthController         │  │
│  │ src/Entity/*          │   │ MoteurController       │  │
│  │ (#[ApiResource])      │   │ (routes métier)        │  │
│  └──────────┬────────────┘   └──────────┬─────────────┘  │
│             │                           ▼                │
│             │                ┌────────────────────────┐  │
│             │                │ Moteur de calcul       │◄─┼── config/nutrix/efsa_drv_reference.json
│             │                │ src/Service/Moteur     │  │
│             │                └──────────┬─────────────┘  │
│             ▼                           ▼                │
│        Doctrine ORM / DBAL  +  migrations (migrations/)  │
└─────────────────────────────┬────────────────────────────┘
                              ▼
                PostgreSQL 16 / Supabase (base partagée)
>>>>>>> Stashed changes:README Back.md
```

## Stack technique

<<<<<<< Updated upstream:README.md
**Backend**
- Node.js / Python
- PostgreSQL
- API REST
=======
- **Le CRUD** : chaque entité porte l'attribut `#[ApiResource]`, et API Platform génère à partir de lui les routes, la sérialisation et la validation. Ces routes sont volontairement **masquées de la doc OpenAPI** (`openapi: false`), mais elles restent appelables.
- **Les routes métier** (`MoteurController`) : elles appellent le moteur de calcul. Les résultats calculés (besoins, autonomie, prévisions, scores) ne sont **jamais enregistrés en base**. Ils sont recalculés à chaque requête à partir des données et du référentiel EFSA. Seules la génération de planning, l'enregistrement d'un repas et les sorties de stock écrivent en base. Comme ce ne sont pas des ressources API Platform, elles sont ajoutées à la doc par `src/OpenApi/MoteurOpenApiDecorator.php`.
>>>>>>> Stashed changes:README Back.md

**Frontend**
- React
- Graphiques, calendrier alimentaire, carte des stocks

<<<<<<< Updated upstream:README.md
**Hardware**
- Raspberry Pi
- Lecteur QR
- ESP32 (optionnel)
- Balance connectée
- Capteurs de température des réserves
=======
```text
NUTRIX-BACK/
├── .github/workflows/ci.yml   # CI GitHub Actions
└── NUTRIX-API/                # application Symfony
    ├── config/
    │   ├── packages/          # config des bundles (security, api_platform, doctrine, jwt, cors…)
    │   ├── nutrix/            # référentiel EFSA utilisé par le moteur
    │   └── jwt/               # clés JWT générées localement (non versionnées)
    ├── migrations/            # migration Doctrine (schéma complet de la base)
    ├── public/index.php
    ├── src/
    │   ├── Controller/        # AuthController (register, me) + MoteurController (routes métier)
    │   ├── DataFixtures/      # données de référence + comptes de démo
    │   ├── Entity/            # 19 entités Doctrine = ressources API
    │   ├── OpenApi/           # documentation OpenAPI des routes métier
    │   ├── Repository/
    │   └── Service/Moteur/    # moteur de calcul
    │       ├── MoteurCalcul.php          # façade appelée par le contrôleur
    │       ├── *Calculator.php           # besoins, recettes, planning, stock, prévisions, écart
    │       ├── Donnees/                  # lecture des données du vaisseau en base
    │       ├── Support/                  # référentiel EFSA, nutriments, paramètres
    │       └── Exception/
    ├── tests/                 # tests PHPUnit (auth + moteur)
    ├── Dockerfile.vercel      # image de production (FrankenPHP)
    ├── Caddyfile              # config du serveur web dans l'image
    ├── vercel.json            # déploiement Vercel
    ├── .env                   # valeurs par défaut, sans secret (versionné)
    ├── .env.local.example     # gabarit pour .env.local
    └── .env.test              # config de l'environnement de test
```
>>>>>>> Stashed changes:README Back.md

**Communication**
- MQTT (interconnexion avec le système agricole / la serre)

<<<<<<< Updated upstream:README.md
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
| `BDD/` (racine, `docker-compose.yml`) | Base de données MySQL + Adminer | Docker |
| `API/NUTRIX-API/` | API Symfony + API Platform | En local sur la machine |
| `Interface Client/NUTRIX-InterfaceClient/` | Application React + Vite | En local sur la machine |

### Prérequis
=======
La base hébergée contient déjà le catalogue d'aliments et de recettes, l'équipage, les stocks, les récoltes et l'historique des repas. Le schéma complet est dans `migrations/Version20260923155035.php`.

| Domaine | Entités (table) | Rôle |
|---|---|---|
| Équipage | `Equipage` (`Equipage`), `ActivityLabel` (`Activity_label`) | Profil d'un occupant : sexe, âge, poids, taille, IMC, PAL (niveau d'activité). Peut être lié en 1-1 à un compte `User`. |
| Allergies | `Allergene` (`ALLERGENE`), `OccupantAllergie` (`OCCUPANT_ALLERGIE`) | Qui est allergique à quoi. Le lien aliment ↔ allergène est lu par le moteur dans la table `ALIMENT_ALLERGENE` (pas d'entité). |
| Comptes | `User` (`app_user`) | Username, rôles, mot de passe hashé. Lecture seule, réservée à `ROLE_ADMIN`. |
| Aliments | `Aliment` (`Aliment`), `CategorieIngredient`, `UniteStock` | Valeurs nutritionnelles pour 100 g, cycle de culture, rendement (g/m²/jour). La clé est un code texte (ex. `RIZ`). |
| Recettes | `Recette`, `CategorieRecette`, `RecetteIngredient` (`recette_ingredient`) | Profil nutritionnel et composition en grammes. Les créneaux autorisés sont lus par le moteur dans la table `RECETTE_TYPE_REPAS` (pas d'entité). |
| Stock | `LotStock` (`LOT_STOCK`), `MouvementStock` (`MOUVEMENT_STOCK`), `RecetteMouvementStock` (`Asso_11`) | Lots (QR code, quantités, péremption, emplacement, type de réserve, statut), mouvements d'entrée et de sortie, lien entre un mouvement et une recette |
| Agriculture | `Recolte` (`RECOLTE`) | Cultures : module, dates de semis et de récolte (prévue et réelle), quantités, taux de perte, statut |
| Repas | `TypeRepas`, `PlanningRepas`, `PlanningRepasOccupant`, `JournalRepas` | Repas prévus, part de chaque occupant (`portion_ratio`), repas réellement consommés |

`ALIMENT_ALLERGENE` et `RECETTE_TYPE_REPAS` sont optionnelles pour le moteur : si elles sont absentes, il ignore ce filtre au lieu de planter.

Valeurs imposées par validation :
>>>>>>> Stashed changes:README Back.md

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- PHP ≥ 8.2 et [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- Node.js et npm

### Via les scripts (le plus rapide)

<<<<<<< Updated upstream:README.md
Deux scripts à la racine automatisent tout ce qui suit (Bash `.sh` pour Git Bash/WSL, PowerShell `.ps1` pour un terminal Windows natif) :
=======
- `Equipage.sexe` est un booléen : `true` pour un homme, `false` pour une femme. Le moteur le convertit en `M` ou `F` pour lire les tables EFSA.
- Pour lier un profil `Equipage` à un compte, on envoie l'IRI (`{"user": "/api/users/5"}`). En lecture, seul `userId` est renvoyé.
- Un repas hors catalogue s'enregistre avec une recette générique « Repas libre ».
- Les tables d'association à clé composée ont des URI dédiées : `/api/recettes/{id}/ingredients`, `/api/equipages/{id}/allergies`, `/api/recettes/{id}/mouvements_stock`.

## Moteur de calcul

Les formules détaillées sont dans `MOTEUR_CALCUL.md` (voir [Documentation de référence](#documentation-de-référence)). En voici l'enchaînement :

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

Les paramètres par défaut (poids des scores, fenêtre de 14 jours, K = 3, seuil de péremption à 7 jours, horizon de 7 à 14 jours) sont centralisés dans `src/Service/Moteur/Support/Parametres.php` et décrits en §14 de `MOTEUR_CALCUL.md`.

## Endpoints

La documentation interactive (Swagger UI) est sur **http://127.0.0.1:8000/api/docs** une fois le serveur lancé. Elle ne montre que les routes utilisées par le front, groupées par domaine (équipages, recettes, planning-repas, journal-repas, stock). Les formats d'entrée et de sortie des routes métier sont détaillés dans `API_BESOINS_PLANNING.md`.

### Routes métier

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| GET | `/api/equipages/{id}/besoins` | admin, ou le compte lié à l'équipier | Besoins d'un équipier |
| GET | `/api/equipages/besoins?date=` | authentifié | Besoins de l'équipe : total et détail par équipier |
| GET | `/api/equipages/besoins/creneau?date=&type_repas=` | authentifié | Besoins d'un créneau, par groupe d'allergies |
| GET | `/api/equipages/{id}/ecart-nutritionnel?periode_jours=7` | admin, ou le compte lié à l'équipier | Écart entre apports réels et besoins |
| GET | `/api/recettes/{id}/profil-nutritionnel` | authentifié | Profil stocké comparé au profil recalculé depuis les ingrédients |
| POST | `/api/planning-repas/simuler` | authentifié | Couverture nutritionnelle d'un ensemble de repas. N'écrit rien en base. |
| POST | `/api/planning-repas/generer` | `ROLE_ADMIN` | Génère le planning sur un horizon donné et l'enregistre |
| GET | `/api/planning-repas?date_debut=&date_fin=&equipage_id=` | authentifié | Lit le planning, pour toute l'équipe ou pour un occupant |
| POST | `/api/journal-repas` | admin, ou occupant pour son propre `equipage_id` | Enregistre un repas consommé et renvoie son apport |
| GET | `/api/stock/autonomie` | authentifié | Autonomie globale et par nutriment, en jours |
| GET | `/api/stock/previsions?semaines=8` | authentifié | Prévision semaine par semaine et périodes à risque |
| GET | `/api/stock/besoins-agricoles?semaine=` | authentifié | Production nécessaire pour chaque aliment |

Toutes les routes qui renvoient un besoin ou un apport utilisent le même format de sortie, `BesoinNutritionnel` : `energy_kcal`, `protein_g`, `carbohydrates_g` (min/max), `lipids_g` (min/max), `fiber_g`, `water_l`, `minerals`, `vitamins`, et un champ `meta` qui liste les hypothèses utilisées.

Codes d'erreur des routes métier (corps `{"error": "..."}`) : `404` ressource introuvable, `409` conflit (ex. rupture de menu), `422` entrée invalide, `501` donnée absente du schéma actuel. Un compte qui vise l'équipier d'un autre compte reçoit un `403`.

Exemple de simulation :
>>>>>>> Stashed changes:README Back.md

```bash
./init.sh   # premiere installation : Docker, composer install, .env.local, migrations, npm install
./start.sh  # demarrage au quotidien : Docker + serveur Symfony (arriere-plan) + front React (Ctrl+C pour tout arreter)
```

```powershell
.\init.ps1
.\start.ps1
```

<<<<<<< Updated upstream:README.md
`init` ne touche pas à un `.env.local` déjà existant. Le détail manuel des étapes est ci-dessous si besoin de dépanner.

### 1. Cloner le projet

```bash
git clone https://github.com/mdeguil/NUTRIX.git
cd NUTRIX
```

### 2. Lancer la base de données (Docker)

```bash
docker compose up -d
```

Ça démarre deux conteneurs :
- **MySQL** sur le port `3306` (base `nutrix`, user/mdp `nutrix`/`nutrix`)
- **Adminer** sur http://localhost:8081 (interface web pour consulter la base)

Vérifier que la base est bien démarrée :

```bash
docker compose ps
```

### 3. Configurer et lancer l'API Symfony

```bash
cd API/NUTRIX-API
composer install
```

Copier le fichier gabarit en `.env.local` (non versionné) :

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

### 4. Lancer le front React

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
=======
Chaque entité du [modèle de données](#modèle-de-données) a ses routes `GET` (liste et détail), `POST`, `PUT`, `PATCH` et `DELETE` sous `/api/...` (ex. `/api/aliments`, `/api/lot_stocks`, `/api/recoltes`). Elles ne figurent pas dans `/api/docs` ; `php bin/console debug:router` les liste toutes.

Exceptions :

- `User` : lecture seule (`GET`), réservée à `ROLE_ADMIN` ;
- `OccupantAllergie` et `RecetteMouvementStock` : pas de `PUT`/`PATCH` (on supprime puis on recrée le lien).

## Authentification et droits

L'API est *stateless*. Toutes les routes sous `/api` exigent un JWT valide, sauf `/api/login`, `/api/register` et `/api/docs`.

| Route | Méthode | Accès | Rôle |
|---|---|---|---|
| `/api/login` | POST | public, 5 tentatives / 15 min | Renvoie un token JWT |
| `/api/register` | POST | public, 10 inscriptions / heure / IP | Crée un compte `ROLE_OCCUPANT` ou `ROLE_FERME` (mot de passe : 8 caractères minimum) |
| `/api/me` | GET | authentifié | Renvoie l'utilisateur courant |

Il existe trois rôles : `ROLE_ADMIN`, `ROLE_OCCUPANT` et `ROLE_FERME`. `ROLE_ADMIN` ne peut pas être obtenu par inscription. Il s'attribue à la main, par exemple via les fixtures.

Droits d'écriture sur le CRUD (la lecture est ouverte à tout compte authentifié, sauf `User`) :

| Ressources | Écriture autorisée pour |
|---|---|
| `Aliment`, `LotStock`, `MouvementStock`, `Recolte` | `ROLE_ADMIN`, `ROLE_FERME` |
| `JournalRepas` | `ROLE_ADMIN`, `ROLE_OCCUPANT` |
| Tout le reste (équipage, recettes, planning, référentiels…) | `ROLE_ADMIN` |

Comptes de démo créés par les fixtures (mot de passe `password123`) : `admin`, `occupant`, `ferme`.
>>>>>>> Stashed changes:README Back.md

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

<<<<<<< Updated upstream:README.md
### Arrêter l'environnement

```bash
symfony server:stop
docker compose down
=======
## Prise en main

### Prérequis

- PHP ≥ 8.2 avec les extensions `intl`, `pdo_pgsql`, `mbstring` et `openssl`
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- Les identifiants de la base PostgreSQL hébergée sur Supabase. **Il n'y a pas de base locale** : toute l'équipe travaille sur la même base, déjà remplie. Demandez le `DATABASE_URL` à l'équipe.

### Installation

```bash
git clone https://github.com/mdeguil/NUTRIX.git NUTRIX-BACK
cd NUTRIX-BACK/NUTRIX-API

# 1. Dépendances
composer install

# 2. Configuration locale (non versionnée)
cp .env.local.example .env.local
>>>>>>> Stashed changes:README Back.md
```

(`Ctrl+C` suffit si tout a été lancé via `./start.sh` / `.\start.ps1`, qui arrête aussi le serveur Symfony automatiquement.)

## CI

Une CI GitHub Actions (`.github/workflows/ci.yml`) tourne sur chaque push/PR vers `main` :

- **API Symfony** : install des dépendances, lint PHP et YAML, migrations sur une base MySQL de test, exécution des tests (`php bin/phpunit`)
- **Front React** : install, `eslint`, `npm run build`

Pour lancer les tests de l'API en local, une base `nutrix_test` dédiée est nécessaire (créée automatiquement par le script d'init Docker `BDD/init/01-test-database.sql` sur un volume neuf) :

```bash
<<<<<<< Updated upstream:README.md
cd API/NUTRIX-API
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit
```
=======
# 3. Clés JWT (config/jwt/*.pem)
php bin/console lexik:jwt:generate-keypair --skip-if-exists

# 4. Vérifier la connexion à la base
php bin/console dbal:run-sql "SELECT 1"

# 5. Vérifier que le schéma est à jour (la base partagée est normalement déjà migrée)
php bin/console doctrine:migrations:status

# 6. Lancer le serveur
symfony server:start -d --no-tls
```

L'API répond alors sur http://127.0.0.1:8000/api, et sa documentation est sur http://127.0.0.1:8000/api/docs.

Pour vérifier que tout fonctionne, connectez-vous avec un compte de démo, puis appelez `GET /api/stock/autonomie`.

Pour brancher le front en local, lancez-le avec `VITE_API_URL=http://127.0.0.1:8000` (valeur par défaut de son `.env`). Le CORS accepte déjà `localhost` et `127.0.0.1` sur tous les ports.

### Modifier le schéma

La base est partagée par toute l'équipe. Tout changement de schéma passe donc par une migration Doctrine, versionnée dans `migrations/` :

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

⚠️ `php bin/console doctrine:fixtures:load` **vide la base avant de la remplir à nouveau**. Ne lancez jamais cette commande sur la base partagée : elle effacerait les données réelles. Elle ne sert que sur une base de test.

## Tests et CI

La suite PHPUnit couvre :

- `tests/Controller/AuthControllerTest.php` : inscription, connexion, accès à une route protégée ;
- `tests/Moteur/MoteurCalculTest.php` : tests unitaires du moteur sur un jeu de données en mémoire ;
- `tests/Moteur/Fixtures/*` : besoins, recettes, planning, stock, prévisions et écart, calculés sur les données de référence ;
- `tests/Moteur/Integration/` : le moteur branché sur Doctrine.

Les tests suppriment et recréent des données. Ils doivent donc tourner sur une base **distincte** de la base partagée. En environnement `test`, Doctrine ajoute le suffixe `_test` au nom de la base : avec le `DATABASE_URL` de `.env.test`, la base ciblée est `nutrix_test` sur un PostgreSQL local. Pour en utiliser une autre, surchargez `DATABASE_URL` dans `.env.test.local`.

```bash
php bin/console doctrine:database:create --if-not-exists --env=test
php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit
```

La CI (`.github/workflows/ci.yml`) se lance à chaque push et à chaque PR vers `main`. Le job API :

1. installe PHP 8.2 et les dépendances Composer ;
2. vérifie la syntaxe PHP de `src/` et le YAML de `config/` ;
3. génère les clés JWT ;
4. applique les migrations sur un conteneur PostgreSQL 16 jetable (`nutrix_test`), propre au run ;
5. exécute `php bin/phpunit`.

La CI a besoin de deux secrets GitHub, à définir dans **Settings → Secrets and variables → Actions** : `APP_SECRET` et `JWT_PASSPHRASE`. S'ils manquent, le job échoue avec `Environment variable not found`.

## Déploiement (Vercel)

L'API est déployée sur Vercel en runtime *container* (`vercel.json`), à partir de `NUTRIX-API/Dockerfile.vercel` :

1. image `dunglas/frankenphp` (PHP 8.4) avec `intl`, `pdo_pgsql`, `opcache`… ;
2. `composer install --no-dev`, puis compilation du cache Symfony et des assets (Swagger UI de `/api/docs` compris) ;
3. au démarrage, les clés JWT sont décodées depuis les variables d'environnement, puis FrankenPHP sert l'application via le `Caddyfile`.

Dans les réglages du projet Vercel, le **Root Directory** doit pointer sur `NUTRIX-API`. Les `.env` ne sont pas lus en production (`dump-env prod --empty`) : toutes les valeurs viennent des variables d'environnement Vercel.

| Variable | Valeur |
|---|---|
| `DATABASE_URL` | URL de la base Supabase |
| `APP_SECRET` | chaîne aléatoire |
| `JWT_PASSPHRASE` | passphrase des clés JWT |
| `JWT_PRIVATE_KEY_B64` / `JWT_PUBLIC_KEY_B64` | contenu de `config/jwt/private.pem` / `public.pem`, encodé en base64 |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | `/app/config/jwt/private.pem` / `/app/config/jwt/public.pem` |
| `CORS_ALLOW_ORIGIN` | regex de l'URL du front, ex. `^https://<projet-front>\.vercel\.app$` |
| `DEFAULT_URI` | URL publique de l'API |

Pour encoder une clé : `base64 -w0 config/jwt/private.pem`.

## Sécurité et secrets

- `.env` est versionné et ne contient **aucun secret réel**. Son `DATABASE_URL` n'est qu'un exemple, remplacé par celui de `.env.local`.
- `APP_SECRET`, `JWT_PASSPHRASE` et `DATABASE_URL` se définissent dans `.env.local` (non versionné) en local, et en variables d'environnement en CI et en production.
- Les clés `config/jwt/*.pem` sont générées en local et ne sont pas versionnées.
- `/api/login` est limité à 5 tentatives par tranche de 15 minutes, `/api/register` à 10 inscriptions par heure et par IP.
- Un compte non admin ne peut lire les besoins et l'écart nutritionnel, et remplir le journal, que pour l'équipier auquel il est lié (sinon `403`).
- Le CORS n'autorise par défaut que `localhost` et `127.0.0.1`, quel que soit le port (`CORS_ALLOW_ORIGIN`).

## Documentation de référence

| Fichier | Contenu |
|---|---|
| `MOTEUR_CALCUL.md` | Toutes les formules du moteur : besoins, stock, rotation, scoring, prévisions, agriculture, rétroaction. Liste aussi les paramètres. |
| `API_BESOINS_PLANNING.md` | Spécification des routes métier : entrée, sortie, codes d'erreur, format `BesoinNutritionnel` |
| [`efsa_drv_reference.json`](./NUTRIX-API/config/nutrix/efsa_drv_reference.json) | Valeurs de référence EFSA par tranche d'âge et par sexe : énergie par PAL, protéines, glucides et lipides, fibres, eau, minéraux, vitamines, ajustements grossesse et allaitement |

> `MOTEUR_CALCUL.md` et `API_BESOINS_PLANNING.md` sont dans le dossier `Docs/`, qui n'est **pas versionné** (voir `.gitignore`). Demandez-les à l'équipe. Seul le référentiel EFSA utilisé par l'API est dans le dépôt.

## Commandes utiles

```bash
php bin/console debug:router                 # lister les routes (CRUD compris)
php bin/console make:entity                  # créer ou modifier une entité
php bin/console make:migration               # générer une migration depuis les entités
php bin/console doctrine:migrations:migrate  # appliquer les migrations
php bin/console doctrine:migrations:status   # état des migrations
php bin/console cache:clear
symfony server:log                           # logs du serveur
symfony server:stop                          # arrêter le serveur
```
>>>>>>> Stashed changes:README Back.md
