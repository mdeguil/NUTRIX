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
                        'properties' => ['error' => ['type' => 'string']],
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
            'moteur_journal_enregistrer',
            'Enregistre un repas reellement consomme et renvoie son apport (ROLE_ADMIN ou ROLE_OCCUPANT)',
            new \ArrayObject([
                'type' => 'object',
                'required' => ['equipage_id', 'recette_id', 'type_repas_id'],
                'properties' => [
                    'equipage_id' => ['type' => 'integer'],
                    'recette_id' => ['type' => 'integer'],
                    'type_repas_id' => ['type' => 'integer'],
                    'date_heure' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Defaut maintenant'],
                    'portion_g' => ['type' => 'number', 'description' => 'Defaut : part standard calculee sur le besoin de l\'equipier'],
                    'sortie_stock' => ['type' => 'boolean', 'description' => 'Si true, sort aussi les ingredients du stock en FEFO'],
                ],
            ]),
            $jsonResponse('Repas enregistre', $objectSchema('id, equipage_id, recette_id, type_repas_id, date_heure, portion_g, apport, sorties_stock?')),
            201,
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

        return $openApi->withPaths($paths);
    }
}
