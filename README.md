# NUTRIX — API

**Backend du système de planification alimentaire NUTRIX, pour un vaisseau spatial / habitat isolé.**

NUTRIX relie l'équipage, ses besoins nutritionnels, les stocks, les repas et la production agricole. Il répond à une question : *combien de besoins humains la nourriture disponible permet-elle encore de couvrir, et que faut-il produire pour tenir ?*

Ce dépôt contient l'**API REST** du projet, en Symfony + API Platform. Elle fait quatre choses :

- elle expose les données du vaisseau en CRUD : équipage, aliments, stocks, récoltes, recettes, repas ;
- elle calcule, avec son **moteur de calcul**, les besoins nutritionnels, l'autonomie du stock, les prévisions sur 8 semaines, les besoins agricoles et un planning de repas qui évite la lassitude ;
- elle sert à l'interface React une **route par écran** (Dashboard, Occupants, Journal, Stock, Prévisionnel, Planificateur, Agriculture), au format attendu par ses composants ;
- elle gère l'authentification par JWT et les droits par rôle.

Le front React est dans un dépôt séparé : [Own-667AI/NUTRIX](https://github.com/Own-667AI/NUTRIX). Les requêtes dont il a besoin sont décrites écran par écran dans son fichier `doc/API_REQUETES_FRONT.md`.

L'API de production tourne sur Vercel : **https://nutrixbackend.vercel.app/api** (documentation sur `/api/docs`).

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
- [Déploiement (Vercel)](#déploiement-vercel)
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
| Agriculture | Traduit les déficits en besoins de production : quantités, surface, semences, eau, énergie. Donne à la ferme les aliments à planter en priorité, d'après leurs jours d'autonomie. |
| Interface React | Une route par écran : bilan du jour, liste de l'équipage, journal paginé, inventaire par catégorie, production à prévoir, statistiques de consommation, catalogue des recettes, besoins de plantation, état du système. |

## Stack technique

| Élément | Technologie |
|---|---|
| Langage | PHP ≥ 8.2 |
| Framework | Symfony 7.4 |
| API REST | API Platform 5 (JSON-LD / Hydra, OpenAPI) |
| ORM / migrations | Doctrine ORM 3, Doctrine Migrations |
| Base de données | PostgreSQL 16 (Supabase), hébergée, partagée par l'équipe et déjà remplie |
| Référentiel nutritionnel | Valeurs de référence EFSA (version 4, 2017) dans [`efsa_drv_reference.json`](./NUTRIX-API/config/nutrix/efsa_drv_reference.json) |
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
│  │ API Platform          │   │ Contrôleurs            │  │
│  │ CRUD généré depuis    │   │ MoteurController       │  │
│  │ src/Entity/*          │   │ FrontController        │  │
│  │ (#[ApiResource])      │   │ AuthController         │  │
│  └──────────┬────────────┘   └──────────┬─────────────┘  │
│             │                           ▼                │
│             │                ┌────────────────────────┐  │
│             │                │ VuesFront (réponses    │  │
│             │                │ au format du front)    │  │
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
                  PostgreSQL / Supabase (base hébergée et remplie)
```

Il y a trois types de routes :

- **Le CRUD** : chaque entité porte l'attribut `#[ApiResource]`, et API Platform génère à partir de lui les routes, la sérialisation, la validation et la documentation OpenAPI.
- **Les routes métier** (`MoteurController`) : elles appellent le moteur de calcul et renvoient ses résultats bruts (snake_case, format `BesoinNutritionnel`).
- **Les routes du front** (`FrontController`) : une par écran de l'interface React. `VuesFront` y assemble les résultats du moteur au format attendu par les composants (camelCase, objets imbriqués, valeurs par portion).

Les résultats calculés (besoins, autonomie, prévisions, scores) ne sont **jamais enregistrés en base**. Ils sont recalculés à chaque requête à partir des données et du référentiel EFSA. Seules la génération de planning, l'enregistrement d'un repas (et ses sorties de stock) et l'inscription écrivent en base.

Les routes métier et les routes du front sont déclarées avec une priorité haute : `GET /api/recettes`, `/api/allergenes` et `/api/recettes/planifiees` passent avant les routes générées par API Platform pour les mêmes chemins. Les autres routes CRUD (`/api/recettes/{id}`, `POST /api/recettes`…) restent servies par API Platform.

### Arborescence

```text
NUTRIX-API/
├── config/
│   ├── packages/          # config des bundles (security, api_platform, doctrine, jwt, cors…)
│   └── jwt/               # clés JWT générées localement (non versionnées)
├── migrations/            # migrations Doctrine (schéma de la base)
├── src/
│   ├── Controller/        # AuthController, MoteurController (routes métier), FrontController (routes du front)
│   ├── Service/
│   │   ├── Moteur/        # moteur de calcul (calculateurs, lecture de la base, paramètres)
│   │   └── Front/         # VuesFront : réponses des routes du front
│   ├── EventSubscriber/   # format d'erreur commun des routes /api
│   ├── Http/              # ErreurApi (construction des réponses d'erreur)
│   ├── OpenApi/           # ajoute les routes métier et du front à /api/docs
│   ├── DataFixtures/      # données de référence + comptes de démo
│   ├── Entity/            # entités Doctrine = ressources API
│   └── Repository/
├── tests/                 # tests unitaires du moteur + tests fonctionnels des routes
├── Dockerfile.vercel      # image de production (FrankenPHP) déployée sur Vercel
├── Caddyfile              # configuration du serveur web de l'image
├── vercel.json            # déclare le service conteneur pour Vercel
├── .env                   # valeurs par défaut, sans secret (versionné)
├── .env.local.example     # gabarit pour .env.local
└── .env.test              # config de l'environnement de test
```

## Modèle de données

La base hébergée contient déjà le catalogue d'aliments et de recettes, l'équipage, les stocks, les récoltes et l'historique des repas.

| Domaine | Tables / entités | Rôle |
|---|---|---|
| Équipage | `Equipage`, `ActivityLabel` | Profil d'un occupant : nom, prénom, fonction à bord, avatar, sexe, âge, poids, taille, IMC, PAL (niveau d'activité). Peut être lié en 1-1 à un compte `User`. |
| Allergies | `Allergene`, `OccupantAllergie` | Qui est allergique à quoi |
| Comptes | `User` | Username, rôles, mot de passe hashé. Lecture seule via l'API. |
| Aliments | `Aliment`, `CategorieIngredient`, `UniteStock` | Valeurs nutritionnelles pour 100 g, cycle de culture, rendement (g/m²/jour). La clé est un code texte (ex. `RIZ`). |
| Recettes | `Recette`, `CategorieRecette`, `RecetteIngredient` | Libellé (150 caractères), profil nutritionnel pour 100 g, poids d'une portion, composition en grammes |
| Stock | `LotStock`, `MouvementStock`, `Asso11` | Lots (QR code, quantités, péremption, emplacement, type de réserve, statut), mouvements d'entrée et de sortie, lien entre un mouvement et une recette |
| Agriculture | `Recolte` | Cultures : module, dates de semis et de récolte (prévue et réelle), quantités, taux de perte, statut |
| Repas | `TypeRepas`, `PlanningRepas`, `PlanningRepasOccupant`, `JournalRepas` | Repas prévus, part de chaque occupant (`portion_ratio`), repas réellement consommés (avec des notes) |

Valeurs imposées par validation :

- `LotStock.typeReserve` : `courante`, `securite`, `urgence`, `strategique`
- `LotStock.statut` : `frais`, `transforme`, `congele`, `epuise`, `perime`
- `Recolte.statut` : `semis`, `croissance`, `recolte`, `perdue`, `replantee`

Conventions à connaître :

- `Equipage.sexe` est un booléen : `true` pour un homme, `false` pour une femme. Le moteur le convertit en `M` ou `F` pour lire les tables EFSA.
- `Equipage.fonction` est la fonction à bord (« Commandant de bord »…). Elle n'a rien à voir avec les rôles de sécurité de `User`.
- Pour lier un profil `Equipage` à un compte, on envoie l'IRI (`{"user": "/api/users/5"}`). En lecture, seul `userId` est renvoyé.
- Il n'y a pas encore de table `ALIMENT_ALLERGENE` : les allergènes d'une recette sont déduits du code de ses aliments (`BLE` → gluten, `LAIT_POUDRE` → lactose…, voir `Parametres::ALLERGENES_PAR_MOT_CLE`). Si la table est créée, le moteur l'utilise à la place. Même principe pour `RECETTE_TYPE_REPAS` (créneaux d'une recette), remplacée en attendant par sa catégorie.
- Aucune table ne trace l'eau bue : le bilan du jour renvoie `eau.consomme: null`.
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

**Consommation réelle (routes du front).** Le Prévisionnel, l'Agriculture, la jauge du Stock et les statistiques partent tous de `ConsommationCalculator` : les repas du journal, convertis en grammes de chaque aliment via la composition des recettes. Un même aliment affiche donc les mêmes chiffres sur toutes les pages.

| Vue | Calcul | Priorité |
|---|---|---|
| Production à prévoir (R13) | consommation hebdo (28 derniers jours) × semaines − stock courant − récoltes prévues nettes de pertes | couverture < 25 % : urgente, < 60 % : moyenne |
| Besoins de plantation (R18) | jours d'autonomie = stock courant / consommation journalière (30 derniers jours) ; jauge pleine à 45 jours | < 15 j : urgente, < 30 j : moyenne |
| Stock par catégorie (R12) | quantité = tous les lots non périmés ; jauge = autonomie du stock courant / 45 jours | seuils de couleur côté front |
| Catalogue (R16) | portions réalisables = minimum, sur les ingrédients, de stock courant / quantité par portion | — |

Ces seuils sont dans `Support/Parametres.php`. Les réserves de sécurité, d'urgence et stratégique sont comptées dans l'inventaire, mais jamais dans le stock consommable.

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

### Routes du front

Ce sont les requêtes de l'interface React, décrites écran par écran dans `API_REQUETES_FRONT.md` (dépôt du front, dossier `doc/`). Les réponses sont en camelCase, avec les masses en grammes, l'énergie en kcal et les dates en ISO 8601. Elles sont calculées par `App\Service\Front\VuesFront`, qui s'appuie sur le moteur, et exposées par `App\Controller\FrontController`.

| # | Méthode | Route | Accès | Page |
|---|---|---|---|---|
| R3 | GET | `/api/me` | connecté | session : rôle, `equipageId`, nom, prénom |
| R4 | GET | `/api/status` | connecté | Sidebar / TopBar : base joignable, niveau, alertes |
| R5 | GET | `/api/me/bilan-journalier?date=&equipageId=` | admin, occupant | Dashboard : consommé du jour et objectif EFSA |
| R6 | GET | `/api/occupants?date=` | admin, occupant | Occupants, sélecteur du Journal |
| R7 | GET | `/api/occupants/{id}` | admin, occupant (soi) | détail avec besoins |
| R8 | GET | `/api/types-repas` | connecté | Journal |
| R9 | GET | `/api/recettes/planifiees?dateDebut=&dateFin=&typeRepasId=` | connecté | Journal : menus planifiés |
| R10 | GET | `/api/journal-repas?page=&itemsPerPage=&equipageId=&dateDebut=&dateFin=` | admin, occupant (soi) | Journal : historique paginé |
| R11 | POST | `/api/journal-repas` | admin, occupant (soi) | Journal : enregistre le repas et sort le stock en FEFO |
| R12 | GET | `/api/stock/categories` | connecté | Stock : inventaire, jauge = autonomie / 45 j |
| R13 | GET | `/api/previsions/production?semaines=8` | admin, ferme | Prévisionnel |
| R14 | GET | `/api/statistiques/aliments-consommes?periode=mois&limit=6` | admin, ferme | Prévisionnel |
| R15 | GET | `/api/statistiques/menus-servis?periode=mois&limit=5` | admin, ferme | Prévisionnel |
| R16 | GET | `/api/recettes?disponible=&sansAllergenes[]=&categorieId=&portions=` | connecté | Planificateur |
| R17 | GET | `/api/allergenes` | connecté | Planificateur |
| R18 | GET | `/api/agriculture/besoins-plantation` | admin, ferme | Agriculture |

Les réponses complètes (champs et exemples) sont celles de `API_REQUETES_FRONT.md` ; `/api/docs` les liste aussi.

Droits : un `ROLE_OCCUPANT` ne lit et n'écrit que **son propre** journal et son propre bilan, mais il voit la liste de l'équipage. Un `ROLE_ADMIN` voit tout, et sans `equipageId` le bilan du jour est celui de tout l'équipage. `ROLE_FERME` a accès au Stock, au Prévisionnel, à l'Agriculture et au catalogue.

Exemple : enregistrer un repas. Les ingrédients sortent du stock courant en FEFO. Si le stock ne suffit pas, le repas est quand même enregistré (il a été mangé) et la réponse contient `alertesStock`.

```bash
curl -X POST http://127.0.0.1:8000/api/journal-repas \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{ "equipageId": 1, "recetteId": 5, "typeRepasId": 1,
        "dateHeure": "2026-09-24T08:00:00+02:00", "portionG": 300, "notes": "Portion complète" }'
```

Les routes `/api` écrites à la main (routes métier et routes du front) renvoient toutes leurs erreurs au même format : `{ "error": "validation_failed", "message": "…", "violations": [{ "field": "…", "message": "…" }] }`. `error` est un code stable (`bad_request`, `access_denied`, `not_found`, `conflict`, `validation_failed`, `server_error`…), `message` peut être affiché tel quel, et `violations` n'est présent qu'en 422. Seules exceptions : `/api/login` et un token absent ou expiré renvoient le format de lexik, `{ "code": 401, "message": "…" }`.

Le Prévisionnel, l'Agriculture, la jauge du Stock et les statistiques s'appuient tous sur `ConsommationCalculator` (consommation réelle issue du journal, stock de la réserve courante). Un même aliment affiche donc les mêmes chiffres sur toutes les pages. Les seuils de priorité sont dans `Parametres`.

### Routes CRUD (API Platform)

Chaque entité du [modèle de données](#modèle-de-données) a ses routes `GET` (liste et détail), `POST`, `PUT`, `PATCH` et `DELETE` sous `/api/...`. Exception : `User` est en lecture seule.

## Authentification

L'API est *stateless*. Toutes les routes sous `/api` exigent un JWT valide, sauf `/api/login`, `/api/register` et `/api/docs`.

| Route | Méthode | Accès | Rôle |
|---|---|---|---|
| `/api/login` | POST | public | Renvoie un token JWT |
| `/api/register` | POST | public | Crée un compte `ROLE_OCCUPANT` ou `ROLE_FERME`. Avec `nom`, `prenom` et `profil` (`sexe`, `age`, `poidsKg`, `tailleCm`, `pal`, `activiteId`), crée aussi le profil `Equipage` de l'occupant |
| `/api/me` | GET | authentifié | Renvoie l'utilisateur courant et son `equipageId` (null s'il n'a pas de profil) |

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

# S'inscrire avec son profil nutritionnel (l'occupant apparaît tout de suite dans les listes)
curl -X POST http://127.0.0.1:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{"username":"cdubois","password":"password123","role":"ROLE_OCCUPANT","nom":"Dubois","prenom":"Claire",
       "profil":{"sexe":"Femme","age":34,"poidsKg":61.5,"tailleCm":168,"pal":1.6,"activiteId":3}}'
```

Codes de l'inscription : `201` créé, `403` pour `ROLE_ADMIN`, `409` identifiant déjà pris (`username_taken`), `422` champ manquant, mot de passe de moins de 8 caractères ou profil invalide, `429` plus de 10 inscriptions par heure depuis une même adresse. Le login est limité à 5 tentatives par 15 minutes.

Il n'y a pas de route de déconnexion : le JWT est sans état (durée de vie 1 h), le front supprime simplement son token.

## Prise en main

### Prérequis

- PHP ≥ 8.2 avec les extensions `intl`, `pdo_pgsql`, `mbstring` et `openssl`
- [Composer](https://getcomposer.org/)
- [Symfony CLI](https://symfony.com/download)
- Les identifiants de la base PostgreSQL hébergée sur Supabase. **Il n'y a pas de base locale** : toute l'équipe travaille sur la même base, déjà remplie. Demandez le `DATABASE_URL` à l'équipe.

### Installation manuelle

```bash
git clone https://github.com/mdeguil/NUTRIX.git
cd NUTRIX/NUTRIX-API

# 1. Dépendances
composer install

# 2. Configuration locale (non versionnée)
cp .env.local.example .env.local
```

Complétez ensuite `.env.local` :

```dotenv
DATABASE_URL="postgresql://USER:PASSWORD@HOTE_SUPABASE:5432/NOM_BASE?serverVersion=16&charset=utf8"
APP_SECRET=<chaîne aléatoire>
JWT_PASSPHRASE=<chaîne aléatoire>
```

Pour générer une valeur aléatoire : `php -r "echo bin2hex(random_bytes(16));"`

Créez aussi `.env.test.local` avec la **même** valeur `JWT_PASSPHRASE`. Symfony ignore `.env.local` en environnement de test.

> **Windows** : si `pdo_pgsql` n'est pas activée dans `php.ini`, ajoutez `-d extension=pdo_pgsql` à chaque commande (`php -d extension=pdo_pgsql bin/console …`). Si `lexik:jwt:generate-keypair` échoue avec `error:80000003:system library`, générez les clés avec l'`openssl` de Git Bash :
> ```bash
> mkdir -p config/jwt
> openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -aes256 -pass pass:<JWT_PASSPHRASE> -out config/jwt/private.pem
> openssl pkey -in config/jwt/private.pem -passin pass:<JWT_PASSPHRASE> -pubout -out config/jwt/public.pem
> ```

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

Pour vérifier que tout fonctionne, connectez-vous avec un compte de démo, puis appelez `GET /api/status` ou `GET /api/occupants`.

### Modifier le schéma

La base est partagée par toute l'équipe. Tout changement de schéma passe donc par une migration Doctrine, versionnée dans `migrations/` :

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

En production, les migrations sont appliquées automatiquement au démarrage du conteneur (voir [Déploiement](#déploiement-vercel)). Écrivez-les pour qu'elles puissent être rejouées sans erreur (`ADD COLUMN IF NOT EXISTS`…).

⚠️ `php bin/console doctrine:fixtures:load` **vide la base avant de la remplir à nouveau**. Ne lancez jamais cette commande sur la base partagée : elle effacerait les données réelles. Elle ne sert que sur une base de test.

## Tests et CI

Les tests suppriment et recréent des données. Ils doivent donc tourner sur une base `nutrix_test` distincte de la base partagée. Le plus simple est un PostgreSQL jetable avec les identifiants de `.env.test` :

```bash
docker run -d --name nutrix_pg_test -p 5432:5432 \
  -e POSTGRES_DB=nutrix_test -e POSTGRES_USER=nutrix -e POSTGRES_PASSWORD=nutrix postgres:16

php bin/console doctrine:migrations:migrate --no-interaction --env=test
php bin/phpunit
```

Pour une autre base, configurez son `DATABASE_URL` dans `.env.test.local` (Doctrine ajoute le suffixe `_test` au nom de la base).

| Tests | Contenu |
|---|---|
| `tests/Moteur/` | Moteur de calcul sur les données des fixtures, sans base (unitaires), puis branché sur la vraie base (`Integration/`) |
| `tests/Controller/AuthControllerTest.php` | Inscription, login, `/api/me` |
| `tests/Controller/FrontControllerTest.php` | Les routes du front : valeurs calculées, droits par rôle, format d'erreur, enregistrement d'un repas et sortie de stock |

Les tests qui ont besoin de la base sont ignorés si elle est injoignable. Ceux qui lisent le dump `Docs/nutrix (1).sql` (non versionné) sont ignorés s'il est absent.

La CI (`.github/workflows/ci.yml`) se lance à chaque push et à chaque PR vers `main`. Le job API :

1. installe PHP 8.2 et les dépendances Composer ;
2. vérifie la syntaxe PHP de `src/` et le YAML de `config/` ;
3. génère les clés JWT ;
4. applique les migrations sur un conteneur PostgreSQL 16 jetable, propre au run ;
5. exécute `php bin/phpunit`.

La CI a besoin de deux secrets GitHub, à définir dans **Settings → Secrets and variables → Actions** : `APP_SECRET` et `JWT_PASSPHRASE`. S'ils manquent, le job échoue avec `Environment variable not found`.

## Déploiement (Vercel)

L'API est déployée sur Vercel en **conteneur** (`vercel.json`, runtime `container`) à partir de `NUTRIX-API/Dockerfile.vercel` : PHP 8.4 + FrankenPHP, Caddy sert `public/`. Un push sur `main` déclenche le build.

Au démarrage, le conteneur :

1. décode les clés JWT depuis `JWT_PRIVATE_KEY_B64` / `JWT_PUBLIC_KEY_B64` vers `config/jwt/*.pem` ;
2. applique les migrations Doctrine (45 s au plus). Un échec est journalisé (`[nutrix] migrations non appliquees au demarrage`) sans empêcher l'API de démarrer ;
3. lance FrankenPHP.

Variables à définir dans le projet Vercel :

| Variable | Obligatoire | Valeur |
|---|---|---|
| `APP_SECRET` | oui | chaîne aléatoire |
| `DATABASE_URL` | oui | URL PostgreSQL Supabase, **par le pooler** (`…pooler.supabase.com`). L'hôte direct `db.<projet>.supabase.co` n'est joignable qu'en IPv6, ce que Vercel ne permet pas. |
| `JWT_PASSPHRASE` | oui | passphrase des clés |
| `JWT_PRIVATE_KEY_B64`, `JWT_PUBLIC_KEY_B64` | oui | contenu des `.pem` en base64 (`base64 -w0 config/jwt/private.pem`) |
| `CORS_ALLOW_ORIGIN` | oui en prod | regex de l'origine du front déployé, ex. `^https://nutrix-front\.vercel\.app$`. Par défaut, seul localhost est autorisé. |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `MESSENGER_TRANSPORT_DSN`, `MAILER_DSN`, `DEFAULT_URI` | non | valeurs par défaut fixées dans l'image |

⚠️ L'image est compilée avec `composer dump-env prod --empty` : **aucune valeur de `.env` n'est reprise en production**. Toute nouvelle variable ajoutée à `.env` doit aussi recevoir une valeur par défaut dans `Dockerfile.vercel` (stage `runtime`) ou être définie sur Vercel. Sinon, la route qui l'utilise répond 500 (`Environment variable not found`).

Pour tester l'image de production en local avant de pousser :

```bash
docker build -f Dockerfile.vercel -t nutrix-api .
docker run --rm -p 8089:8080 -e PORT=8080 -e APP_SECRET=… -e JWT_PASSPHRASE=… \
  -e JWT_PRIVATE_KEY_B64="$(base64 -w0 config/jwt/private.pem)" -e JWT_PUBLIC_KEY_B64="$(base64 -w0 config/jwt/public.pem)" \
  -e DATABASE_URL="postgresql://nutrix:nutrix@host.docker.internal:5432/nutrix_test?serverVersion=16&charset=utf8" nutrix-api
# puis http://127.0.0.1:8089/api/docs
```

Vérification après un déploiement : `POST /api/login` avec un mauvais mot de passe doit répondre `401 Invalid credentials.`, et non 500.

## Sécurité et secrets

- `.env` est versionné et ne contient **aucun secret réel**. Son `DATABASE_URL` PostgreSQL n'est qu'un exemple, remplacé par celui de `.env.local`.
- `APP_SECRET`, `JWT_PASSPHRASE` et `DATABASE_URL` se définissent dans `.env.local`, qui n'est pas versionné.
- Les clés `config/jwt/*.pem` sont générées en local et ne sont pas versionnées.
- Le CORS n'autorise par défaut que `localhost` et `127.0.0.1`, quel que soit le port (`CORS_ALLOW_ORIGIN`).
- Un occupant ne peut lire ou écrire que les données nutritionnelles de son propre profil (journal, bilan, besoins, écart). Un refus renvoie `403` avec le message générique « Accès refusé », sans détailler les règles de sécurité.
- En production (`APP_DEBUG=0`), une erreur 500 renvoie « Erreur interne du serveur ». Le détail est dans les logs, pas dans la réponse.

## Documentation de référence

| Fichier | Contenu |
|---|---|
| [`MOTEUR_CALCUL.md`](./MOTEUR_CALCUL.md) | Toutes les formules du moteur : besoins, stock, rotation, scoring, prévisions, agriculture, rétroaction. Liste aussi les paramètres. |
| [`API_BESOINS_PLANNING.md`](./API_BESOINS_PLANNING.md) | Spécification des routes métier : entrée, sortie, codes d'erreur, format `BesoinNutritionnel` |
| `API_REQUETES_FRONT.md` (dépôt du front, `doc/`) | Les requêtes de l'interface React, écran par écran : pourquoi, ce qui est envoyé, ce qui est attendu |
| [`efsa_drv_reference.json`](./efsa_drv_reference.json) | Valeurs de référence EFSA par tranche d'âge et par sexe : énergie par PAL, protéines, glucides et lipides, fibres, eau, minéraux, vitamines, ajustements grossesse et allaitement |

## Commandes utiles

```bash
php bin/console debug:router                 # lister les routes
php bin/console make:entity                  # créer ou modifier une entité
php bin/console make:migration               # générer une migration depuis les entités
php bin/console doctrine:migrations:migrate  # appliquer les migrations
php bin/console doctrine:migrations:status   # état des migrations
php bin/console router:match /api/recettes   # quelle route répond à un chemin
php bin/console api:openapi:export           # schéma OpenAPI (celui de /api/docs)
php bin/console cache:clear
symfony server:log                           # logs du serveur
symfony server:stop                          # arrêter le serveur
```
