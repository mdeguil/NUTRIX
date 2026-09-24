<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Injecte dans le schema OpenAPI genere par API Platform les routes du moteur de calcul
 * (App\Controller\MoteurController) : de simples routes Symfony, qu'API Platform ne documente
 * pas automatiquement puisqu'elles ne sont pas des #[ApiResource].
 *
 * Les 18 tables sont marquees `openapi: false` sur chaque entite : /api/docs ne montre donc que
 * ce que le front consomme reellement (les endpoints ci-dessous), pas le CRUD brut des tables.
 */
final class MoteurOpenApiDecorator implements OpenApiFactoryInterface
{
    private const SECURITY = [['JWT' => []]];

    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $besoinNutritionnelSchema = new \ArrayObject([
            'type' => 'object',
            'description' => 'Besoins en energie, macronutriments, fibres, eau, mineraux et vitamines (format BesoinNutritionnel).',
            'properties' => [
                'energy_kcal' => ['type' => 'number'],
                'protein_g' => ['type' => 'number'],
                'carbohydrates_g' => ['type' => 'object', 'properties' => ['min' => ['type' => 'number'], 'max' => ['type' => 'number']]],
                'lipids_g' => ['type' => 'object', 'properties' => ['min' => ['type' => 'number'], 'max' => ['type' => 'number']]],
                'fiber_g' => ['type' => 'number'],
                'water_l' => ['type' => 'number'],
                'minerals' => ['type' => 'object'],
                'vitamins' => ['type' => 'object'],
                'meta' => ['type' => 'object'],
            ],
        ]);

        $errorResponses = static function (array $codes): array {
            $libelles = [
                400 => 'Corps JSON invalide',
                403 => 'Role insuffisant, ou donnees d\'un autre occupant',
                404 => 'Ressource introuvable',
                409 => 'Conflit (ex. rupture de menu)',
                422 => 'Entree invalide',
                501 => 'Donnee absente du schema actuel',
            ];
            $responses = [];
            foreach ($codes as $code) {
                $responses[(string) $code] = new Response(
                    description: $libelles[$code],
                    content: new \ArrayObject(['application/json' => new MediaType(schema: new \ArrayObject([
                        'type' => 'object',
                        'properties' => [
                            'error' => ['type' => 'string', 'description' => 'Code machine stable (validation_failed, not_found…)'],
                            'message' => ['type' => 'string', 'description' => 'Texte affichable tel quel'],
                            'violations' => ['type' => 'array', 'description' => 'En 422 seulement', 'items' => ['type' => 'object', 'properties' => ['field' => ['type' => 'string'], 'message' => ['type' => 'string']]]],
                        ],
                    ]))]),
                );
            }

            return $responses;
        };

        $jsonResponse = static function (string $description, \ArrayObject $schema): Response {
            return new Response(
                description: $description,
                content: new \ArrayObject(['application/json' => new MediaType(schema: $schema)]),
            );
        };

        $objectSchema = static fn (string $description) => new \ArrayObject(['type' => 'object', 'description' => $description]);

        // Groupe les routes par leur 2e segment d'URL (equipages, recettes, planning-repas, journal-repas, stock).
        $tagFor = static fn (string $path): string => explode('/', substr($path, \strlen('/api/')))[0];

        $addGet = function (string $path, string $operationId, string $summary, array $parameters, Response $okResponse, array $errorCodes = [422, 404]) use ($paths, $errorResponses, $tagFor): void {
            $paths->addPath($path, new PathItem(get: new Operation(
                operationId: $operationId,
                tags: [$tagFor($path)],
                summary: $summary,
                parameters: $parameters,
                responses: ['200' => $okResponse] + $errorResponses($errorCodes),
                security: self::SECURITY,
            )));
        };

        $addPost = function (string $path, string $operationId, string $summary, \ArrayObject $bodySchema, Response $okResponse, int $successCode = 200, array $errorCodes = [422, 404, 409]) use ($paths, $errorResponses, $tagFor): void {
            $paths->addPath($path, new PathItem(post: new Operation(
                operationId: $operationId,
                tags: [$tagFor($path)],
                summary: $summary,
                requestBody: new RequestBody(content: new \ArrayObject(['application/json' => new MediaType(schema: $bodySchema)]), required: true),
                responses: [(string) $successCode => $okResponse] + $errorResponses($errorCodes),
                security: self::SECURITY,
            )));
        };

        $idParam = new Parameter(name: 'id', in: 'path', description: 'Identifiant de l\'equipage', required: true, schema: ['type' => 'integer']);

        $addGet(
            '/api/equipages/{id}/besoins',
            'moteur_besoin_equipier',
            'Besoins nutritionnels d\'un equipier',
            [
                $idParam,
                new Parameter(name: 'lpi_mg', in: 'query', description: 'Apport en phytates suppose (mg), pour le calcul du zinc', schema: ['type' => 'number']),
                new Parameter(name: 'statut_menopause', in: 'query', description: 'premenopausal ou postmenopausal', schema: ['type' => 'string', 'enum' => ['premenopausal', 'postmenopausal']]),
            ],
            $jsonResponse('Besoins de l\'equipier', $besoinNutritionnelSchema),
        );

        $addGet(
            '/api/equipages/besoins',
            'moteur_besoin_equipe',
            'Besoins nutritionnels de toute l\'equipe (total + detail par equipier)',
            [new Parameter(name: 'date', in: 'query', description: 'YYYY-MM-DD, defaut aujourd\'hui', schema: ['type' => 'string', 'format' => 'date'])],
            $jsonResponse('Besoins de l\'equipe', $objectSchema('date, besoin_equipe_total (BesoinNutritionnel), par_equipage[]')),
        );

        $addGet(
            '/api/equipages/besoins/creneau',
            'moteur_besoin_creneau',
            'Besoins nutritionnels pour un creneau de repas, groupes par allergies',
            [
                new Parameter(name: 'date', in: 'query', schema: ['type' => 'string', 'format' => 'date']),
                new Parameter(name: 'type_repas', in: 'query', description: 'Petit-dejeuner, Dejeuner, Diner, Collation', required: true, schema: ['type' => 'string']),
            ],
            $jsonResponse('Besoins du creneau', $objectSchema('date, type_repas, besoin_creneau_equipe, groupes[] (par allergenes)')),
        );

        $addGet(
            '/api/equipages/{id}/ecart-nutritionnel',
            'moteur_ecart_nutritionnel',
            'Ecart entre apports reels (journal) et besoins theoriques sur une periode',
            [
                $idParam,
                new Parameter(name: 'periode_jours', in: 'query', description: 'Defaut 7', schema: ['type' => 'integer']),
            ],
            $jsonResponse('Ecart nutritionnel', $objectSchema('consomme_moyen_jour, besoin_moyen_jour, ecart, ecart_pct, par_jour[]')),
        );

        $addGet(
            '/api/recettes/{id}/profil-nutritionnel',
            'moteur_profil_recette',
            'Profil nutritionnel stocke d\'une recette, compare au profil recalcule depuis ses ingredients',
            [new Parameter(name: 'id', in: 'path', required: true, schema: ['type' => 'integer'])],
            $jsonResponse('Profil de la recette', $objectSchema('valeur_stockee, valeur_recalculee_ingredients, ecart_pct')),
        );

        $addPost(
            '/api/planning-repas/simuler',
            'moteur_planning_simuler',
            'Simule la couverture nutritionnelle d\'un ensemble de repas, sans rien ecrire en base',
            new \ArrayObject([
                'type' => 'object',
                'required' => ['equipage_ids', 'repas'],
                'properties' => [
                    'equipage_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'repas' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'date' => ['type' => 'string', 'format' => 'date'],
                        'type_repas' => ['type' => 'string'],
                        'recette_id' => ['type' => 'integer'],
                        'portions' => ['type' => 'number'],
                        'equipage_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'portion_ratios' => ['type' => 'object'],
                    ]]],
                ],
            ]),
            $jsonResponse('Couverture nutritionnelle simulee', $objectSchema('apports_totaux_equipe, besoins_totaux_equipe, couverture_pct, par_equipage[], ingredients_necessaires_g, alertes[]')),
        );

        $addPost(
            '/api/planning-repas/generer',
            'moteur_planning_generer',
            'Genere un planning de repas (roulement anti-lassitude) sur un horizon donne et l\'enregistre (ROLE_ADMIN)',
            new \ArrayObject([
                'type' => 'object',
                'required' => ['equipage_ids', 'date_debut'],
                'properties' => [
                    'equipage_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'date_debut' => ['type' => 'string', 'format' => 'date'],
                    'horizon_jours' => ['type' => 'integer', 'description' => 'Defaut 7, max 31'],
                    'types_repas' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'graine' => ['type' => 'integer', 'description' => 'Graine aleatoire, pour un resultat reproductible'],
                    'autoriser_rupture_stock' => ['type' => 'boolean'],
                    'enregistrer' => ['type' => 'boolean', 'description' => 'Defaut true'],
                ],
            ]),
            $jsonResponse('Planning genere (et enregistre si demande)', $objectSchema('planning_repas_ids[], occupants_enregistres, repas_generes[], + le meme contenu que /simuler')),
        );

        $addGet(
            '/api/planning-repas',
            'moteur_planning_lire',
            'Lit le planning de repas, pour toute l\'equipe ou un occupant',
            [
                new Parameter(name: 'date_debut', in: 'query', schema: ['type' => 'string', 'format' => 'date']),
                new Parameter(name: 'date_fin', in: 'query', schema: ['type' => 'string', 'format' => 'date']),
                new Parameter(name: 'equipage_id', in: 'query', description: 'Filtre sur la part d\'un occupant (501 si le schema ne le permet pas)', schema: ['type' => 'integer']),
            ],
            $jsonResponse('Planning', $objectSchema('planning[]')),
            [501],
        );

        $addPost(
            '/api/journal-repas',
            'front_journal_enregistrer',
            'R11 — Enregistre un repas consomme et sort ses ingredients du stock en FEFO (ROLE_ADMIN, ou ROLE_OCCUPANT pour lui-meme)',
            new \ArrayObject([
                'type' => 'object',
                'required' => ['equipageId', 'recetteId', 'typeRepasId', 'dateHeure'],
                'properties' => [
                    'equipageId' => ['type' => 'integer'],
                    'recetteId' => ['type' => 'integer'],
                    'typeRepasId' => ['type' => 'integer'],
                    'dateHeure' => ['type' => 'string', 'format' => 'date-time', 'description' => 'ISO 8601 avec fuseau, pas dans le futur'],
                    'portionG' => ['type' => 'number', 'description' => 'Defaut : part standard calculee sur le besoin de l\'occupant'],
                    'notes' => ['type' => 'string', 'maxLength' => 255],
                ],
            ]),
            $jsonResponse('Repas enregistre (meme objet qu\'un element de GET /api/journal-repas, + alertesStock si le stock courant ne suffisait pas)', $objectSchema('id, dateHeure, equipage{id,nom,prenom}, recette{id,libelle}, typeRepas{id,libelle}, portionG, apport{kcal,proteinesG,glucidesG,lipidesG,fibresG}, notes, alertesStock?')),
            201,
            [422, 403, 404],
        );

        $addGet(
            '/api/stock/autonomie',
            'moteur_stock_autonomie',
            'Autonomie du stock en jours, globale et par nutriment (nutriment limitant identifie)',
            [],
            $jsonResponse('Autonomie', $objectSchema('autonomie_globale_jours, nutriment_limitant, par_nutriment, avec_recoltes_prevues, toutes_reserves, alertes[]')),
            [],
        );

        $addGet(
            '/api/stock/previsions',
            'moteur_stock_previsions',
            'Previsions de stock semaine par semaine (defaut 8 semaines) : besoins, recoltes, consommation, deficits',
            [new Parameter(name: 'semaines', in: 'query', description: 'Defaut 8', schema: ['type' => 'integer'])],
            $jsonResponse('Previsions', $objectSchema('semaines[], aliments[], plan_semis[], synthese_semis[], alertes[]')),
            [422],
        );

        $addGet(
            '/api/stock/besoins-agricoles',
            'moteur_stock_besoins_agricoles',
            'Production agricole necessaire (quantite, surface, semences, eau, energie) pour combler les deficits prevus',
            [
                new Parameter(name: 'semaine', in: 'query', description: 'Restreint a une semaine (1 a semaines)', schema: ['type' => 'integer']),
                new Parameter(name: 'semaines', in: 'query', description: 'Horizon total, defaut 8', schema: ['type' => 'integer']),
            ],
            $jsonResponse('Besoins agricoles', $objectSchema('besoins[], totaux, hors_culture[]')),
            [422],
        );

        // ---- Requetes du front (Docs/API_REQUETES_FRONT.md), App\Controller\FrontController ----
        $q = static fn (string $nom, string $type, string $description, bool $requis = false, ?string $format = null) => new Parameter(
            name: $nom, in: 'query', description: $description, required: $requis,
            schema: array_filter(['type' => $type, 'format' => $format]),
        );
        $date = static fn (string $nom, string $description) => $q($nom, 'string', $description, false, 'date');
        $periode = [
            $q('periode', 'string', 'semaine | mois | personnalisee (defaut mois)'),
            $date('dateDebut', 'Requis si periode=personnalisee'),
            $date('dateFin', 'Requis si periode=personnalisee'),
        ];

        $addGet('/api/status', 'front_status', 'R4 — Etat de l\'API et de la base, niveau (nominal/attention/critique) et alertes en cours', [],
            $jsonResponse('Statut', $objectSchema('api, database (ok|down), niveau, alertes[{niveau, message}], horodatage')), []);
        $addGet('/api/me/bilan-journalier', 'front_bilan_journalier', 'R5 — Consomme du jour et objectif EFSA de l\'occupant connecte (ADMIN : equipageId ou total equipage)',
            [$date('date', 'Defaut aujourd\'hui'), $q('equipageId', 'integer', 'ADMIN seulement ; absent = total equipage')],
            $jsonResponse('Bilan', $objectSchema('date, equipageId, calories|proteines|lipides|glucides|fibres|eau : {consomme, objectif, objectifMin?, objectifMax?, unite}')), [403, 404, 422]);
        $addGet('/api/occupants', 'front_occupants', 'R6 — Liste de l\'equipage (ROLE_ADMIN, ROLE_OCCUPANT)', [$date('date', 'Jour de apportsActuelsKcal, defaut aujourd\'hui')],
            $jsonResponse('Occupants', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'id, userId, nom, prenom, age, sexe, poids, tailleCm, pal, niveauActivite, apportsKcal, apportsActuelsKcal, fonction, avatarUrl, allergenes[{id, nom}]']])), [403, 422]);
        $addGet('/api/occupants/{id}', 'front_occupant', 'R7 — Detail d\'un occupant avec ses besoins (un occupant ne lit que le sien)',
            [new Parameter(name: 'id', in: 'path', required: true, schema: ['type' => 'integer']), $date('date', 'Defaut aujourd\'hui')],
            $jsonResponse('Occupant', $objectSchema('champs de GET /api/occupants + besoins (BesoinNutritionnel)')), [403, 404]);
        $addGet('/api/types-repas', 'front_types_repas', 'R8 — Types de repas', [],
            $jsonResponse('Types de repas', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'id, libelle']])), []);
        $addGet('/api/recettes/planifiees', 'front_recettes_planifiees', 'R9 — Recettes du planning sur une periode (menu servi du Journal)',
            [$date('dateDebut', 'Defaut hier'), $date('dateFin', 'Defaut demain'), $q('typeRepasId', 'integer', 'Filtre sur un creneau')],
            $jsonResponse('Recettes planifiees', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'recetteId, libelle, date, typeRepas{id, libelle}, poidsPortionG, kcalPortion']])), [422]);
        $paths->addPath('/api/journal-repas', $paths->getPath('/api/journal-repas')->withGet(new Operation(
            operationId: 'front_journal_lire',
            tags: ['journal-repas'],
            summary: 'R10 — Historique du journal, pagine (un occupant ne voit que ses repas)',
            parameters: [
                $q('page', 'integer', 'Defaut 1'), $q('itemsPerPage', 'integer', 'Defaut 20, max 100'), $q('equipageId', 'integer', 'Filtre sur un occupant'),
                $date('dateDebut', 'Debut de periode'), $date('dateFin', 'Fin de periode'), $q('order[dateHeure]', 'string', 'desc (defaut) ou asc'),
            ],
            responses: ['200' => $jsonResponse('Page du journal', $objectSchema('items[] (meme format que la reponse de POST), totalItems, page, itemsPerPage'))] + $errorResponses([403, 422]),
            security: self::SECURITY,
        )));
        $addGet('/api/stock/categories', 'front_stock_categories', 'R12 — Inventaire par categorie d\'ingredient, jauge = autonomie / 45 jours', [],
            $jsonResponse('Categories', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'id, nom, code, niveauPercent, joursAutonomie, items[{alimentId, nom, quantite, quantiteConsommable, unite, quantiteAffichee, prochainePeremption, joursAutonomie, sousReserveMinimale}]']])), []);
        $addGet('/api/previsions/production', 'front_previsions_production', 'R13 — Production a prevoir par aliment sur l\'horizon (ROLE_ADMIN, ROLE_FERME)',
            [$q('semaines', 'integer', 'Defaut 8'), $q('historiqueJours', 'integer', 'Fenetre du journal, defaut 28')],
            $jsonResponse('Previsions', $objectSchema('horizonSemaines, historiqueJours, genereLe, aliments[{alimentId, aliment, consommationHebdoG, stockActuelG, recoltesPrevuesG, besoinTotalG, aProduireG, couverturePercent, priorite}]')), [403, 422]);
        $addGet('/api/statistiques/aliments-consommes', 'front_stats_aliments', 'R14 — Aliments les plus consommes (ROLE_ADMIN, ROLE_FERME)',
            [...$periode, $q('limit', 'integer', 'Defaut 6')],
            $jsonResponse('Classement', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'alimentId, nom, quantiteG']])), [403, 422]);
        $addGet('/api/statistiques/menus-servis', 'front_stats_menus', 'R15 — Menus les plus servis (ROLE_ADMIN, ROLE_FERME)',
            [...$periode, $q('limit', 'integer', 'Defaut 5')],
            $jsonResponse('Classement', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'recetteId, nom, fois']])), [403, 422]);
        $addGet('/api/recettes', 'front_recettes', 'R16 — Catalogue des recettes : valeurs par portion, composition, allergenes, disponibilite',
            [
                $q('disponible', 'boolean', 'Filtre sur la disponibilite'), $q('sansAllergenes', 'string', 'Ids d\'allergenes a exclure : sansAllergenes[]=1&sansAllergenes[]=2 ou 1,2'),
                $q('categorieId', 'integer', 'Categorie de recette'), $q('portions', 'number', 'Portions pour le calcul de disponible, defaut 1'),
            ],
            $jsonResponse('Recettes', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'id, label, categorie{id, libelle}, poidsPortionG, calories, proteines, glucides, lipides, fibres, tempsPreparation, aliments[{id, nom, quantiteG}], allergenes[{id, nom}], disponible, portionsRealisables']])), [422]);
        $addGet('/api/allergenes', 'front_allergenes', 'R17 — Allergenes', [],
            $jsonResponse('Allergenes', new \ArrayObject(['type' => 'array', 'items' => ['type' => 'object', 'description' => 'id, nom']])), []);
        $addGet('/api/agriculture/besoins-plantation', 'front_besoins_plantation', 'R18 — Autonomie par aliment et priorites de plantation (ROLE_ADMIN, ROLE_FERME)',
            [$q('historiqueJours', 'integer', 'Defaut 30'), $q('autonomieCibleJours', 'integer', 'Defaut 45')],
            $jsonResponse('Besoins de plantation', $objectSchema('autonomieCibleJours, historiqueJours, aliments[{alimentId, aliment, consommationMoisG, stockActuelG, joursAutonomie, gaugePercent, priorite, recoltesEnCours[], cycleJoursMin, cycleJoursMax}]')), [403, 422]);

        return $openApi->withPaths($paths);
    }
}
