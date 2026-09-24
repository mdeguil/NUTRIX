<?php

namespace App\Service\Front;

use App\Service\Moteur\BesoinsCalculator;
use App\Service\Moteur\ConsommationCalculator;
use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\EcartCalculator;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\RecetteCalculator;
use App\Service\Moteur\StockCalculator;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;
use App\Service\Moteur\Support\Texte;

/**
 * Réponses des requêtes du front (Docs/API_REQUETES_FRONT.md, R3 à R18), construites à partir de la
 * photographie DonneesVaisseau et des calculateurs du moteur.
 *
 * Conventions de sortie (§0.2) : camelCase, nombres JSON, masses en grammes, énergie en kcal, volumes
 * en litres, dates ISO 8601. Ne lit ni n'écrit la base : les droits d'accès sont vérifiés par
 * FrontController, qui passe ici des identifiants déjà autorisés.
 */
class VuesFront
{
    private const PERIODES = ['semaine', 'mois', 'personnalisee'];

    public function __construct(
        private readonly BesoinsCalculator $besoins,
        private readonly RecetteCalculator $recettes,
        private readonly StockCalculator $stock,
        private readonly EcartCalculator $ecarts,
        private readonly ConsommationCalculator $consommation,
    ) {
    }

    // ---------------------------------------------------------------- Occupants (R5, R6, R7)

    /** GET /api/occupants?date= (R6) */
    public function occupants(DonneesVaisseau $d, ?\DateTimeImmutable $date = null): array
    {
        $date ??= $d->aujourdhui;
        $liste = [];
        foreach ($d->equipages as $id => $eq) {
            $liste[] = $this->occupant($d, $id, $date);
        }

        return $liste;
    }

    /** GET /api/occupants/{id} (R7) : l'objet de R6 plus le besoin nutritionnel détaillé. */
    public function occupantDetail(DonneesVaisseau $d, int $id, ?\DateTimeImmutable $date = null): array
    {
        $eq = $d->equipages[$id] ?? throw MoteurException::introuvable(sprintf('Occupant %d introuvable', $id));

        return $this->occupant($d, $id, $date ?? $d->aujourdhui)
            + ['besoins' => BesoinsCalculator::formater($this->besoins->besoinIndividuel($eq))];
    }

    private function occupant(DonneesVaisseau $d, int $id, \DateTimeImmutable $date): array
    {
        $eq = $d->equipages[$id];
        $besoin = $this->besoins->besoinIndividuel($eq);
        $jour = $this->ecarts->apportsParJour($d, $id, $date, $date, $besoin['energy_kcal']);

        return [
            'id' => $id,
            'userId' => $eq['user_id'],
            'nom' => $eq['nom'],
            'prenom' => $eq['prenom'],
            'age' => $eq['age'],
            // Equipage.sexe est un booléen (true = homme) : les besoins EFSA ne distinguent que M/F.
            'sexe' => $eq['sexe'] ? 'Homme' : 'Femme',
            'poids' => $eq['poids_kg'],
            'tailleCm' => $eq['taille_cm'],
            'pal' => $eq['pal'],
            'niveauActivite' => null !== $eq['activite_id'] ? ($d->activites[$eq['activite_id']] ?? null) : null,
            'apportsKcal' => (int) round($besoin['energy_kcal']),
            'apportsActuelsKcal' => (int) round($jour[$date->format('Y-m-d')]['apport']['kcal'] ?? 0),
            'fonction' => $eq['fonction'],
            'avatarUrl' => $eq['avatar_url'],
            'allergenes' => $this->allergenesListe($d, $eq['allergenes']),
        ];
    }

    /**
     * GET /api/me/bilan-journalier (R5) : consommé du jour et objectif EFSA, pour un occupant
     * (`$equipageId`) ou pour tout l'équipage (null).
     */
    public function bilanJournalier(DonneesVaisseau $d, ?int $equipageId, ?\DateTimeImmutable $date = null): array
    {
        $date ??= $d->aujourdhui;
        if (null !== $equipageId && !isset($d->equipages[$equipageId])) {
            throw MoteurException::introuvable(sprintf('Occupant %d introuvable', $equipageId));
        }
        $ids = null !== $equipageId ? [$equipageId] : array_keys($d->equipages);

        $besoins = [];
        $consomme = Nutriments::zero();
        foreach ($ids as $id) {
            $besoin = $this->besoins->besoinIndividuel($d->equipages[$id]);
            $besoins[] = $besoin;
            $jour = $this->ecarts->apportsParJour($d, $id, $date, $date, $besoin['energy_kcal']);
            $consomme = Nutriments::ajouter($consomme, $jour[$date->format('Y-m-d')]['apport'] ?? Nutriments::zero());
        }
        $objectif = $this->besoins->sommer($besoins);

        $fourchette = static function (?array $plage, float $consomme): array {
            $min = $plage['min'] ?? 0.0;
            $max = $plage['max'] ?? 0.0;

            return [
                'consomme' => round($consomme, 1),
                // La jauge a besoin d'une cible unique : le milieu de la fourchette EFSA.
                'objectif' => round(($min + $max) / 2, 1),
                'objectifMin' => round($min, 1),
                'objectifMax' => round($max, 1),
                'unite' => 'g',
            ];
        };

        return [
            'date' => $date->format('Y-m-d'),
            'equipageId' => $equipageId,
            'calories' => ['consomme' => (int) round($consomme['kcal']), 'objectif' => (int) round($objectif['energy_kcal'] ?? 0), 'unite' => 'kcal'],
            'proteines' => ['consomme' => round($consomme['proteines_g'], 1), 'objectif' => round($objectif['protein_g'] ?? 0, 1), 'unite' => 'g'],
            'lipides' => $fourchette($objectif['lipids_g'] ?? null, $consomme['lipides_g']),
            'glucides' => $fourchette($objectif['carbohydrates_g'] ?? null, $consomme['glucides_g']),
            'fibres' => ['consomme' => round($consomme['fibres_g'], 1), 'objectif' => round($objectif['fiber_g'] ?? 0, 1), 'unite' => 'g'],
            // Aucune table ne trace l'eau bue : « non suivi » côté front (§12).
            'eau' => ['consomme' => null, 'objectif' => round($objectif['water_l'] ?? 0, 2), 'unite' => 'L'],
        ];
    }

    // ---------------------------------------------------------------- Référentiels (R8, R17)

    /** GET /api/types-repas (R8) */
    public function typesRepas(DonneesVaisseau $d): array
    {
        $types = array_map(static fn ($t) => ['id' => $t['id'], 'libelle' => $t['libelle']], $d->typesRepas);
        ksort($types);

        return array_values($types);
    }

    /** GET /api/allergenes (R17) */
    public function allergenes(DonneesVaisseau $d): array
    {
        $ids = array_keys($d->allergenes);
        sort($ids);

        return $this->allergenesListe($d, $ids);
    }

    // ---------------------------------------------------------------- Journal (R9, R10, R11)

    /** GET /api/recettes/planifiees (R9) : recettes du PLANNING_REPAS sur la période. */
    public function recettesPlanifiees(DonneesVaisseau $d, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au, ?int $typeRepasId = null): array
    {
        $du ??= $d->aujourdhui->modify('-1 day');
        $au ??= $d->aujourdhui->modify('+1 day');
        if ($au < $du) {
            throw MoteurException::invalide('dateFin doit être postérieure ou égale à dateDebut');
        }

        $liste = [];
        foreach ($d->planning as $p) {
            if ($p['date'] < $du || $p['date'] > $au) {
                continue;
            }
            $recette = $d->recettes[$p['recette_id']] ?? null;
            $type = $d->typeRepasParCle($p['type_repas']);
            if (null === $recette || (null !== $typeRepasId && $typeRepasId !== ($type['id'] ?? null))) {
                continue;
            }
            $liste[] = [
                'recetteId' => $recette['id'],
                'libelle' => $recette['libelle'],
                'date' => $p['date']->format('Y-m-d'),
                'typeRepas' => null !== $type ? ['id' => $type['id'], 'libelle' => $type['libelle']] : null,
                'poidsPortionG' => $recette['poids_total_g'],
                'kcalPortion' => (int) round($recette['pour100g']['kcal'] * $recette['poids_total_g'] / 100),
            ];
        }

        return $liste;
    }

    /**
     * GET /api/journal-repas (R10), paginé.
     *
     * @param array{equipageId?: int|null, dateDebut?: \DateTimeImmutable|null, dateFin?: \DateTimeImmutable|null, ordre?: string} $filtres
     */
    public function journal(DonneesVaisseau $d, array $filtres, int $page = 1, int $parPage = 20): array
    {
        if ($page < 1 || $parPage < 1 || $parPage > 100) {
            throw MoteurException::invalide('page doit être ≥ 1 et itemsPerPage compris entre 1 et 100');
        }
        $equipageId = $filtres['equipageId'] ?? null;
        $du = isset($filtres['dateDebut']) ? $filtres['dateDebut']->setTime(0, 0) : null;
        $au = isset($filtres['dateFin']) ? $filtres['dateFin']->setTime(0, 0) : null;

        $lignes = array_values(array_filter($d->journal, static function (array $l) use ($equipageId, $du, $au) {
            $jour = $l['date_heure']->setTime(0, 0);

            return (null === $equipageId || $l['equipage_id'] === $equipageId)
                && (null === $du || $jour >= $du)
                && (null === $au || $jour <= $au);
        }));
        $sens = 'asc' === strtolower($filtres['ordre'] ?? 'desc') ? 1 : -1;
        usort($lignes, static fn ($a, $b) => $sens * ([$a['date_heure'], $a['id']] <=> [$b['date_heure'], $b['id']]));

        return [
            'items' => array_map(fn ($l) => $this->entreeJournal($d, $l), array_slice($lignes, ($page - 1) * $parPage, $parPage)),
            'totalItems' => count($lignes),
            'page' => $page,
            'itemsPerPage' => $parPage,
        ];
    }

    /** Une entrée du journal au format de R10 (aussi renvoyée par R11). */
    public function entreeJournal(DonneesVaisseau $d, array $ligne): array
    {
        $eq = $d->equipages[$ligne['equipage_id']] ?? null;
        $recette = $d->recettes[$ligne['recette_id']] ?? null;
        $type = $d->typesRepas[$ligne['type_repas_id']] ?? null;
        $grammes = $this->consommation->grammesRecette($d, $ligne);
        $apport = null !== $recette ? $this->recettes->apport($recette, $grammes) : Nutriments::zero();

        return [
            'id' => $ligne['id'],
            'dateHeure' => $ligne['date_heure']->format(\DateTimeInterface::ATOM),
            'equipage' => ['id' => $ligne['equipage_id'], 'nom' => $eq['nom'] ?? null, 'prenom' => $eq['prenom'] ?? null],
            'recette' => ['id' => $ligne['recette_id'], 'libelle' => $recette['libelle'] ?? null],
            'typeRepas' => ['id' => $ligne['type_repas_id'], 'libelle' => $type['libelle'] ?? null],
            'portionG' => round($grammes, 1),
            'apport' => self::apport($apport),
            'notes' => $ligne['notes'] ?? null,
        ];
    }

    // ---------------------------------------------------------------- Stock (R12)

    /**
     * GET /api/stock/categories (R12) : inventaire par catégorie d'ingrédient.
     *
     * `quantite` compte tous les lots non périmés (toutes réserves) ; `niveauPercent` est l'autonomie
     * du stock consommable (réserve courante) rapportée à la cible de 45 jours, comme sur l'Agriculture.
     */
    public function stockCategories(DonneesVaisseau $d): array
    {
        $date = $d->aujourdhui;
        $toutesReserves = array_values(array_unique(array_column($d->lots, 'type_reserve')));
        $lots = $this->stock->lotsUtilisables($d, $date, $toutesReserves);
        $consommable = $this->consommation->stockConsommable($d);
        $consoJour = $this->consommation->moyenneJournaliere($d, Parametres::HISTORIQUE_AGRICULTURE_JOURS);

        $parAliment = [];
        foreach ($lots as $lot) {
            $a = &$parAliment[$lot['aliment']];
            $a['quantite'] = ($a['quantite'] ?? 0.0) + $lot['quantite_g'];
            if (null !== $lot['date_peremption'] && (!isset($a['peremption']) || $lot['date_peremption'] < $a['peremption'])) {
                $a['peremption'] = $lot['date_peremption'];
            }
            unset($a);
        }

        $categories = [];
        foreach ($parAliment as $code => $info) {
            $aliment = $d->aliments[$code] ?? null;
            if (null === $aliment) {
                continue;
            }
            $catId = $aliment['categorie_id'];
            $categories[$catId ?? 0] ??= [
                'id' => $catId,
                'nom' => null !== $catId ? ($d->categoriesIngredient[$catId]['libelle'] ?? 'Non classé') : 'Non classé',
                'stockConsommable' => 0.0,
                'consoJour' => 0.0,
                'items' => [],
            ];
            $unite = self::uniteBase($aliment['unite']);
            $jours = ConsommationCalculator::joursAutonomie($consommable[$code] ?? 0.0, $consoJour[$code] ?? 0.0);
            $categories[$catId ?? 0]['stockConsommable'] += $consommable[$code] ?? 0.0;
            $categories[$catId ?? 0]['consoJour'] += $consoJour[$code] ?? 0.0;
            $categories[$catId ?? 0]['items'][] = [
                'alimentId' => $code,
                'nom' => $aliment['libelle'],
                'quantite' => round($info['quantite'], 1),
                'quantiteConsommable' => round($consommable[$code] ?? 0.0, 1),
                'unite' => $unite,
                'quantiteAffichee' => self::formaterQuantite($info['quantite'], $unite),
                'prochainePeremption' => isset($info['peremption']) ? $info['peremption']->format('Y-m-d') : null,
                'joursAutonomie' => null !== $jours ? (int) round($jours) : null,
                // Pas de réserve minimale en base : on retient le stock minimum du moteur (JOURS_STOCK_MINIMUM jours de consommation).
                'sousReserveMinimale' => null !== $jours && $jours < Parametres::JOURS_STOCK_MINIMUM,
            ];
        }
        ksort($categories);

        return array_values(array_map(static function (array $c) {
            $jours = ConsommationCalculator::joursAutonomie($c['stockConsommable'], $c['consoJour']);
            usort($c['items'], static fn ($a, $b) => strcmp($a['nom'], $b['nom']));

            return [
                'id' => $c['id'],
                'nom' => $c['nom'],
                'code' => Texte::cle($c['nom']),
                'niveauPercent' => self::jauge($jours, $c['stockConsommable']),
                'joursAutonomie' => null !== $jours ? (int) round($jours) : null,
                'items' => $c['items'],
            ];
        }, $categories));
    }

    // ---------------------------------------------------------------- Prévisionnel (R13, R14, R15)

    /** GET /api/previsions/production?semaines=8 (R13) */
    public function previsionsProduction(DonneesVaisseau $d, int $semaines = Parametres::PREVISION_SEMAINES, int $historiqueJours = Parametres::HISTORIQUE_PREVISION_JOURS): array
    {
        if ($semaines < 1 || $semaines > 52) {
            throw MoteurException::invalide('semaines doit être compris entre 1 et 52');
        }
        self::verifierHistorique($historiqueJours);
        $t0 = $d->aujourdhui;
        $consoJour = $this->consommation->moyenneJournaliere($d, $historiqueJours);
        $stock = $this->consommation->stockConsommable($d);
        $recoltes = StockCalculator::recoltesAVenir($d, $t0, $t0->modify(sprintf('+%d days', 7 * $semaines)));

        $aliments = [];
        foreach ($this->codesSuivis($d, $consoJour, $stock, $recoltes) as $code) {
            $hebdo = 7 * ($consoJour[$code] ?? 0.0);
            $besoin = $hebdo * $semaines;
            $disponible = ($stock[$code] ?? 0.0) + ($recoltes[$code] ?? 0.0);
            $couverture = $besoin > 0 ? (int) min(100, round(100 * $disponible / $besoin)) : 100;
            $aliments[] = [
                'alimentId' => $code,
                'aliment' => $d->aliments[$code]['libelle'],
                'consommationHebdoG' => round($hebdo),
                'stockActuelG' => round($stock[$code] ?? 0.0),
                'recoltesPrevuesG' => round($recoltes[$code] ?? 0.0),
                'besoinTotalG' => round($besoin),
                'aProduireG' => round(max(0.0, $besoin - $disponible)),
                'couverturePercent' => $couverture,
                'priorite' => match (true) {
                    $couverture < Parametres::PRODUCTION_SEUIL_URGENTE_PCT => 'urgente',
                    $couverture < Parametres::PRODUCTION_SEUIL_MOYENNE_PCT => 'moyenne',
                    default => 'faible',
                },
            ];
        }

        return [
            'horizonSemaines' => $semaines,
            'historiqueJours' => $historiqueJours,
            'genereLe' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'aliments' => $aliments,
        ];
    }

    /** GET /api/statistiques/aliments-consommes (R14), trié par quantité décroissante. */
    public function alimentsConsommes(DonneesVaisseau $d, string $periode = 'mois', ?\DateTimeImmutable $du = null, ?\DateTimeImmutable $au = null, int $limit = 6): array
    {
        [$du, $au] = $this->periode($d, $periode, $du, $au);
        $parAliment = $this->consommation->parAliment($d, $du, $au);
        arsort($parAliment);

        $liste = [];
        foreach (array_slice($parAliment, 0, self::limite($limit), true) as $code => $g) {
            $liste[] = ['alimentId' => $code, 'nom' => $d->aliments[$code]['libelle'] ?? $code, 'quantiteG' => round($g)];
        }

        return $liste;
    }

    /** GET /api/statistiques/menus-servis (R15), trié par nombre de services décroissant. */
    public function menusServis(DonneesVaisseau $d, string $periode = 'mois', ?\DateTimeImmutable $du = null, ?\DateTimeImmutable $au = null, int $limit = 5): array
    {
        [$du, $au] = $this->periode($d, $periode, $du, $au);
        $parRecette = ConsommationCalculator::parRecette($d, $du, $au);
        uksort($parRecette, static fn ($a, $b) => [$parRecette[$b], $a] <=> [$parRecette[$a], $b]);

        $liste = [];
        foreach (array_slice($parRecette, 0, self::limite($limit), true) as $id => $fois) {
            $liste[] = ['recetteId' => $id, 'nom' => $d->recettes[$id]['libelle'] ?? (string) $id, 'fois' => $fois];
        }

        return $liste;
    }

    // ---------------------------------------------------------------- Planificateur (R16)

    /**
     * GET /api/recettes (R16) : catalogue avec valeurs par portion, composition, allergènes et disponibilité.
     *
     * @param array{disponible?: bool|null, sansAllergenes?: list<int>, categorieId?: int|null, portions?: float} $filtres
     */
    public function recettes(DonneesVaisseau $d, array $filtres = []): array
    {
        $portions = $filtres['portions'] ?? 1.0;
        if ($portions <= 0) {
            throw MoteurException::invalide('portions doit être positif');
        }
        $sans = $filtres['sansAllergenes'] ?? [];
        $stock = $this->consommation->stockConsommable($d);

        $liste = [];
        foreach ($d->recettes as $id => $recette) {
            if (null !== ($filtres['categorieId'] ?? null) && $recette['categorie_id'] !== $filtres['categorieId']) {
                continue;
            }
            $allergenes = $this->recettes->allergenes($d, $recette);
            if (array_intersect($allergenes, $sans)) {
                continue;
            }
            $poids = $recette['poids_total_g'];
            $parPortion = $this->recettes->apport($recette, $poids);

            $aliments = [];
            $realisables = null;
            foreach ($this->recettes->composition($d, $recette)['aliments'] as $code => $g100) {
                $quantite = $g100 * $poids / 100;
                $aliments[] = ['id' => $code, 'nom' => $d->aliments[$code]['libelle'] ?? $code, 'quantiteG' => round($quantite, 1)];
                if ($quantite > 0) {
                    $possible = (int) floor(($stock[$code] ?? 0.0) / $quantite);
                    $realisables = null === $realisables ? $possible : min($realisables, $possible);
                }
            }
            $realisables ??= 0;
            $disponible = $realisables >= $portions;
            if (null !== ($filtres['disponible'] ?? null) && $filtres['disponible'] !== $disponible) {
                continue;
            }
            sort($allergenes);
            $categorie = null !== $recette['categorie_id'] ? ($d->categoriesRecette[$recette['categorie_id']] ?? null) : null;

            $liste[] = [
                'id' => $id,
                'label' => $recette['libelle'],
                'categorie' => $categorie,
                'poidsPortionG' => $poids,
                'calories' => (int) round($parPortion['kcal']),
                'proteines' => round($parPortion['proteines_g'], 1),
                'glucides' => round($parPortion['glucides_g'], 1),
                'lipides' => round($parPortion['lipides_g'], 1),
                'fibres' => round($parPortion['fibres_g'], 1),
                'tempsPreparation' => $recette['temps_preparation'],
                'aliments' => $aliments,
                'allergenes' => $this->allergenesListe($d, $allergenes),
                'disponible' => $disponible,
                'portionsRealisables' => $realisables,
            ];
        }

        return $liste;
    }

    // ---------------------------------------------------------------- Agriculture (R18) et statut (R4)

    /** GET /api/agriculture/besoins-plantation (R18) */
    public function besoinsPlantation(DonneesVaisseau $d, int $historiqueJours = Parametres::HISTORIQUE_AGRICULTURE_JOURS, int $cibleJours = Parametres::AUTONOMIE_CIBLE_JOURS): array
    {
        self::verifierHistorique($historiqueJours);
        if ($cibleJours < 1 || $cibleJours > 365) {
            throw MoteurException::invalide('autonomieCibleJours doit être compris entre 1 et 365');
        }
        $consoJour = $this->consommation->moyenneJournaliere($d, $historiqueJours);
        $stock = $this->consommation->stockConsommable($d);

        $enCours = [];
        foreach ($d->recoltes as $r) {
            if (in_array($r['statut'], Parametres::STATUTS_RECOLTE_A_VENIR, true)) {
                $enCours[$r['aliment']][] = [
                    'id' => $r['id'],
                    'moduleCulture' => $r['module'],
                    'dateRecoltePrevue' => $r['date_recolte_prevue']->format('Y-m-d'),
                    'quantitePrevueG' => $r['quantite_prevue_g'],
                ];
            }
        }

        $aliments = [];
        foreach ($this->codesSuivis($d, $consoJour, $stock, array_map(static fn () => 1.0, $enCours)) as $code) {
            $aliment = $d->aliments[$code];
            $jours = ConsommationCalculator::joursAutonomie($stock[$code] ?? 0.0, $consoJour[$code] ?? 0.0);
            $aliments[] = [
                'alimentId' => $code,
                'aliment' => $aliment['libelle'],
                'consommationMoisG' => round(30 * ($consoJour[$code] ?? 0.0)),
                'stockActuelG' => round($stock[$code] ?? 0.0),
                // null : rien n'est consommé, l'autonomie est illimitée.
                'joursAutonomie' => null !== $jours ? (int) round($jours) : null,
                'gaugePercent' => self::jauge($jours, $stock[$code] ?? 0.0, $cibleJours),
                'priorite' => match (true) {
                    null === $jours => 'faible',
                    $jours < Parametres::PLANTATION_SEUIL_URGENTE_JOURS => 'urgente',
                    $jours < Parametres::PLANTATION_SEUIL_MOYENNE_JOURS => 'moyenne',
                    default => 'faible',
                },
                'recoltesEnCours' => $enCours[$code] ?? [],
                'cycleJoursMin' => $aliment['cycle_min'],
                'cycleJoursMax' => $aliment['cycle_max'],
            ];
        }

        return ['autonomieCibleJours' => $cibleJours, 'historiqueJours' => $historiqueJours, 'aliments' => $aliments];
    }

    /**
     * Alertes de R4 (GET /api/status) : aliments en autonomie urgente (R18) et lots périmés ou proches
     * de la péremption (moteur, StockCalculator::alertesLots).
     *
     * @return list<array{niveau: string, message: string}>
     */
    public function alertes(DonneesVaisseau $d): array
    {
        $alertes = [];
        foreach ($this->besoinsPlantation($d)['aliments'] as $a) {
            if ('urgente' === $a['priorite']) {
                $alertes[] = ['niveau' => 'critique', 'message' => sprintf('%s : %d jour(s) d\'autonomie restant(s)', $a['aliment'], $a['joursAutonomie'])];
            }
        }
        foreach ($this->stock->alertesLots($d, $d->aujourdhui) as $a) {
            $alertes[] = ['niveau' => 'critique' === $a['niveau'] ? 'critique' : 'attention', 'message' => $a['message']];
        }

        return $alertes;
    }

    // ---------------------------------------------------------------- Utilitaires

    /** @param list<int> $ids */
    private function allergenesListe(DonneesVaisseau $d, array $ids): array
    {
        return array_values(array_map(static fn (int $id) => ['id' => $id, 'nom' => $d->allergenes[$id]['libelle'] ?? (string) $id], $ids));
    }

    /**
     * Aliments connus ayant une consommation, du stock ou une récolte attendue, triés par code.
     *
     * @param array<string, float> ...$sources
     *
     * @return list<string>
     */
    private function codesSuivis(DonneesVaisseau $d, array ...$sources): array
    {
        $codes = [];
        foreach ($sources as $source) {
            foreach ($source as $code => $valeur) {
                if ($valeur > 0 && isset($d->aliments[$code])) {
                    $codes[$code] = true;
                }
            }
        }
        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    private function periode(DonneesVaisseau $d, string $periode, ?\DateTimeImmutable $du, ?\DateTimeImmutable $au): array
    {
        $t0 = $d->aujourdhui;

        return match ($periode) {
            'semaine' => [$t0->modify('monday this week'), $t0],
            'mois' => [$t0->modify('first day of this month'), $t0],
            'personnalisee' => null !== $du && null !== $au && $du <= $au
                ? [$du, $au]
                : throw MoteurException::invalide('dateDebut et dateFin (dateDebut ≤ dateFin) sont requis pour periode=personnalisee'),
            default => throw MoteurException::invalide(sprintf('periode attendue : %s', implode(', ', self::PERIODES))),
        };
    }

    /** Jauge 0–100 : jours d'autonomie rapportés à la cible. Pleine si rien n'est consommé et qu'il reste du stock. */
    private static function jauge(?float $jours, float $stockG, int $cible = Parametres::AUTONOMIE_CIBLE_JOURS): int
    {
        if (null === $jours) {
            return $stockG > 0 ? 100 : 0;
        }

        return (int) min(100, round(100 * $jours / $cible));
    }

    /** Unité d'affichage d'un aliment : les lots sont en grammes, ou en mL / unités selon unite_stock. */
    private static function uniteBase(?string $libelle): string
    {
        return match (Texte::cle($libelle)) {
            'l', 'ml' => 'mL',
            'u', 'unite', 'unites' => 'u',
            default => 'g',
        };
    }

    private static function formaterQuantite(float $quantite, string $unite): string
    {
        $court = static fn (float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');

        return match ($unite) {
            'u' => sprintf('%d unité%s', (int) round($quantite), round($quantite) > 1 ? 's' : ''),
            'mL' => $quantite >= 1000 ? $court($quantite / 1000).' L' : $court($quantite).' mL',
            default => $quantite >= 1000 ? $court($quantite / 1000).' kg' : $court($quantite).' g',
        };
    }

    /** @param array<string, float> $apport */
    private static function apport(array $apport): array
    {
        return [
            'kcal' => (int) round($apport['kcal']),
            'proteinesG' => round($apport['proteines_g'], 1),
            'glucidesG' => round($apport['glucides_g'], 1),
            'lipidesG' => round($apport['lipides_g'], 1),
            'fibresG' => round($apport['fibres_g'], 1),
        ];
    }

    private static function limite(int $limit): int
    {
        return $limit >= 1 && $limit <= 100 ? $limit : throw MoteurException::invalide('limit doit être compris entre 1 et 100');
    }

    private static function verifierHistorique(int $jours): void
    {
        if ($jours < 1 || $jours > 365) {
            throw MoteurException::invalide('historiqueJours doit être compris entre 1 et 365');
        }
    }
}
