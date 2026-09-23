<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;
use App\Service\Moteur\Support\Texte;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Planning des repas : simulation de couverture (§8, C1), génération avec roulement anti-lassitude
 * (§7-§8, C2), lecture (C3) et apport d'un repas journalisé (C4).
 */
class PlanningCalculator
{
    private const SEUIL_COUVERTURE_BASSE = 80.0;
    private const SEUIL_COUVERTURE_HAUTE = 150.0;

    public function __construct(
        private readonly BesoinsCalculator $besoins,
        private readonly RecetteCalculator $recettes,
        private readonly StockCalculator $stock,
        private readonly EcartCalculator $ecarts,
        private readonly PrevisionCalculator $previsions,
    ) {
    }

    /**
     * POST /api/planning-repas/simuler — couverture nutritionnelle d'un ensemble de repas, sans rien écrire.
     *
     * Entrée : {equipage_ids: int[], repas: [{date, type_repas, recette_id, portions, equipage_ids?, portion_ratios?}]}
     */
    public function simuler(DonneesVaisseau $d, array $entree): array
    {
        $equipageIds = $this->validerEquipages($d, $entree['equipage_ids'] ?? null);
        if (!is_array($entree['repas'] ?? null) || [] === $entree['repas']) {
            throw MoteurException::invalide('repas doit être une liste non vide');
        }

        $besoinsEq = [];
        foreach ($equipageIds as $id) {
            $besoinsEq[$id] = $this->besoins->besoinIndividuel($d->equipages[$id]);
        }
        $energieMoyenne = array_sum(array_map(static fn ($b) => $b['energy_kcal'], $besoinsEq)) / count($besoinsEq);

        $apportEquipe = Nutriments::zero();
        $parEquipage = array_fill_keys($equipageIds, ['apport' => Nutriments::zero(), 'par_repas' => []]);
        $grammesParAliment = [];
        $alertes = [];
        $dates = [];

        foreach (array_values($entree['repas']) as $i => $repas) {
            [$date, $type, $recette] = $this->validerRepas($d, $repas, $i);
            $dates[] = $date;
            $convives = isset($repas['equipage_ids']) ? array_values(array_intersect($equipageIds, array_map('intval', $repas['equipage_ids']))) : $equipageIds;
            $portions = (float) ($repas['portions'] ?? count($convives));
            if ($portions <= 0) {
                throw MoteurException::invalide(sprintf('repas[%d].portions doit être positif', $i));
            }

            $portionG = RecetteCalculator::portionG($recette, $type['cle'], $energieMoyenne);
            $grammesTotal = $portions * $portionG;
            $apportEquipe = Nutriments::ajouter($apportEquipe, $this->recettes->apport($recette, $grammesTotal));
            foreach ($this->recettes->composition($d, $recette)['aliments'] as $code => $g100) {
                $grammesParAliment[$code] = ($grammesParAliment[$code] ?? 0.0) + $grammesTotal * $g100 / 100;
            }

            $ratios = $this->ratios($besoinsEq, $convives, $repas['portion_ratios'] ?? []);
            $allergenesRecette = $this->recettes->allergenes($d, $recette);
            foreach ($convives as $id) {
                $grammes = $portionG * $ratios[$id];
                $apport = $this->recettes->apport($recette, $grammes);
                $parEquipage[$id]['apport'] = Nutriments::ajouter($parEquipage[$id]['apport'], $apport);
                $parEquipage[$id]['par_repas'][] = [
                    'date' => $date->format('Y-m-d'), 'type_repas' => $type['libelle'], 'recette_id' => $recette['id'],
                    'portion_g' => round($grammes, 1), 'portion_ratio' => round($ratios[$id], 2), 'apport' => Nutriments::arrondir($apport),
                ];
                $conflit = array_intersect($allergenesRecette, $d->equipages[$id]['allergenes']);
                if ($conflit) {
                    $alertes[] = ['niveau' => 'critique', 'type' => 'allergie', 'equipage_id' => $id, 'recette_id' => $recette['id'],
                        'message' => sprintf('Équipage #%d allergique (%s) à « %s » le %s', $id,
                            implode(', ', array_map(static fn ($a) => $d->allergenes[$a]['libelle'] ?? $a, $conflit)), $recette['libelle'], $date->format('Y-m-d'))];
                }
            }
            if (!in_array($type['cle'], $this->recettes->creneaux($d, $recette), true)) {
                $alertes[] = ['niveau' => 'info', 'type' => 'creneau', 'recette_id' => $recette['id'],
                    'message' => sprintf('« %s » n\'est pas prévue pour le créneau %s', $recette['libelle'], $type['libelle'])];
            }
        }

        usort($dates, static fn ($a, $b) => $a <=> $b);
        $debut = $dates[0];
        $fin = end($dates);
        $nbJours = (int) $debut->diff($fin)->format('%a') + 1;

        $sortieEquipage = [];
        $besoinsTotal = [];
        foreach ($equipageIds as $id) {
            $besoinPeriode = $this->besoins->multiplier($besoinsEq[$id], $nbJours);
            $besoinsTotal[] = $besoinPeriode;
            $couverture = Nutriments::couverture($parEquipage[$id]['apport'], Nutriments::depuisBesoin($besoinPeriode));
            foreach ($couverture as $cle => $pct) {
                if (null !== $pct && $pct < self::SEUIL_COUVERTURE_BASSE) {
                    $alertes[] = ['niveau' => 'warning', 'type' => 'couverture', 'equipage_id' => $id, 'nutriment' => $cle,
                        'message' => sprintf('%s : couverture %.0f %% pour Équipage #%d sur la période', $cle, $pct, $id)];
                } elseif ('kcal' === $cle && null !== $pct && $pct > self::SEUIL_COUVERTURE_HAUTE) {
                    $alertes[] = ['niveau' => 'info', 'type' => 'couverture', 'equipage_id' => $id, 'nutriment' => $cle,
                        'message' => sprintf('Énergie : couverture %.0f %% pour Équipage #%d, au-dessus du besoin', $pct, $id)];
                }
            }
            $sortieEquipage[] = [
                'equipage_id' => $id,
                'apports' => Nutriments::arrondir($parEquipage[$id]['apport']),
                'besoins' => BesoinsCalculator::formater($besoinPeriode),
                'couverture_pct' => $couverture,
                'par_repas' => $parEquipage[$id]['par_repas'],
            ];
        }

        $besoinEquipe = $this->besoins->sommer($besoinsTotal);
        $dispo = $this->stock->disponible($d, $d->aujourdhui);
        foreach ($grammesParAliment as $code => $g) {
            if ($g > ($dispo[$code] ?? 0.0) + 1e-6) {
                $alertes[] = ['niveau' => 'warning', 'type' => 'stock', 'aliment_id' => $code,
                    'message' => sprintf('%s : %.0f g nécessaires, %.0f g en réserve courante', $d->aliments[$code]['libelle'] ?? $code, $g, $dispo[$code] ?? 0.0)];
            }
        }

        return [
            'periode' => ['debut' => $debut->format('Y-m-d'), 'fin' => $fin->format('Y-m-d'), 'nb_jours' => $nbJours],
            'apports_totaux_equipe' => Nutriments::arrondir($apportEquipe),
            'besoins_totaux_equipe' => BesoinsCalculator::formater($besoinEquipe),
            'couverture_pct' => Nutriments::couverture($apportEquipe, Nutriments::depuisBesoin($besoinEquipe)),
            'par_equipage' => $sortieEquipage,
            'ingredients_necessaires_g' => array_map(static fn ($g) => round($g, 1), $grammesParAliment),
            'alertes' => $alertes,
            'meta' => ['hypotheses' => [
                'part standard = énergie du créneau pour l\'équipier moyen, ajustée par portion_ratio (besoin kcal / besoin moyen)',
                'apports calculés sur les 5 valeurs nutritionnelles connues des recettes',
            ]],
        ];
    }

    /**
     * POST /api/planning-repas/generer — choisit les recettes (roulement anti-lassitude) sans écrire en base.
     * L'écriture est faite par MoteurCalcul à partir de `repas_a_enregistrer`.
     *
     * Entrée : {equipage_ids, date_debut, horizon_jours, types_repas?, graine?, autoriser_rupture_stock?}
     */
    public function generer(DonneesVaisseau $d, array $entree): array
    {
        $equipageIds = $this->validerEquipages($d, $entree['equipage_ids'] ?? null);
        $debut = $this->date($entree['date_debut'] ?? null, 'date_debut');
        $horizon = (int) ($entree['horizon_jours'] ?? Parametres::HORIZON_PLANIFICATION_JOURS);
        if ($horizon < 1 || $horizon > 31) {
            throw MoteurException::invalide('horizon_jours doit être compris entre 1 et 31');
        }
        $types = [];
        foreach ($entree['types_repas'] ?? array_column($d->typesRepas, 'libelle') as $libelle) {
            $types[] = $d->typeRepasParCle(Texte::cle($libelle)) ?? throw MoteurException::invalide(sprintf('type_repas inconnu : « %s »', $libelle));
        }
        // Ordre chronologique des créneaux dans la journée.
        $ordre = array_flip(array_keys(Parametres::COEFFICIENTS_CRENEAU));
        usort($types, static fn ($a, $b) => ($ordre[$a['cle']] ?? 9) <=> ($ordre[$b['cle']] ?? 9));

        $aleatoire = new Randomizer(new Mt19937(isset($entree['graine']) ? (int) $entree['graine'] : random_int(0, PHP_INT_MAX)));
        $autoriserRupture = (bool) ($entree['autoriser_rupture_stock'] ?? false);

        $besoinsEq = $this->besoins->besoinsEquipage($d);
        $groupes = $this->besoins->groupesAllergies($d, $equipageIds);
        $historique = $this->historique($d);
        $contexte = $this->contexteRotation($d);

        // Stock réservé au fil de la génération : chaque repas choisi consomme ce qu'il utilise.
        $stock = [
            'courante' => $this->stock->disponible($d, $debut),
            'securite' => $this->stock->disponible($d, $debut, ['securite']),
        ];
        $horizonDemande = max(7, $horizon);
        $demande = $this->previsions->demande($d, $besoinsEq, $horizonDemande, $horizonDemande)['par_aliment'];
        $seuilsReserve = array_map(static fn ($jours) => array_sum($jours) / count($jours) * Parametres::JOURS_STOCK_MINIMUM, $demande);

        $repasGeneres = [];
        $alertes = [];
        foreach ($groupes as $g => $groupe) {
            $poids = $this->ecarts->poidsNutriments($d, $groupe['equipage_ids'], $debut);
            $energieMoyenne = array_sum(array_map(static fn ($id) => $besoinsEq[$id]['energy_kcal'], $groupe['equipage_ids'])) / count($groupe['equipage_ids']);
            $macrosGroupe = BesoinsCalculator::macrosEquipe($besoinsEq, $groupe['equipage_ids']);
            $precedent = null;

            for ($j = 0; $j < $horizon; ++$j) {
                $date = $debut->modify("+$j days");
                foreach ($types as $type) {
                    $choix = $this->choisir($d, [
                        'groupe' => $groupe, 'date' => $date, 'type' => $type, 'energie' => $energieMoyenne,
                        'cible' => Nutriments::multiplier($macrosGroupe, Parametres::COEFFICIENTS_CRENEAU[$type['cle']] ?? 0.25),
                        'poids' => $poids, 'precedent' => $precedent, 'historique' => $historique, 'contexte' => $contexte,
                        'stock' => $stock, 'seuils' => $seuilsReserve, 'autoriser_rupture' => $autoriserRupture,
                    ], $aleatoire);

                    if (null === $choix) {
                        throw MoteurException::conflit('rupture de menu', [
                            'alerte' => 'rupture de menu', 'date' => $date->format('Y-m-d'), 'type_repas' => $type['libelle'],
                            'equipage_ids' => $groupe['equipage_ids'],
                        ]);
                    }

                    foreach ($choix['prelevements'] as $reserve => $grammes) {
                        foreach ($grammes as $code => $g100) {
                            $stock[$reserve][$code] = ($stock[$reserve][$code] ?? 0.0) - $g100;
                        }
                    }
                    $recette = $d->recettes[$choix['recette_id']];
                    $historique[] = ['date' => $date, 'recette_id' => $recette['id'], 'equipage_ids' => $groupe['equipage_ids'], 'type_repas' => $type['cle']];
                    $precedent = ['recette' => $recette, 'date' => $date, 'type_repas' => $type['cle']];
                    if ($choix['relachement']) {
                        $alertes[] = ['niveau' => 'warning', 'type' => 'relachement', 'date' => $date->format('Y-m-d'), 'type_repas' => $type['libelle'],
                            'message' => sprintf('Contraintes assouplies (%s) pour le groupe %s', implode(', ', $choix['relachement']), implode(',', $groupe['equipage_ids']))];
                    }

                    $repasGeneres[] = [
                        'date' => $date->format('Y-m-d'),
                        'type_repas' => $type['libelle'],
                        'recette_choisie_id' => $recette['id'],
                        'libelle' => $recette['libelle'],
                        'equipage_ids' => $groupe['equipage_ids'],
                        'portions' => count($groupe['equipage_ids']),
                        'score' => round($choix['score'], 3),
                        'detail_score' => array_map(static fn ($v) => round($v, 3), $choix['detail']),
                        'candidats_evalues' => $choix['candidats'],
                        'cooldown_releve' => in_array('cooldown', $choix['relachement'], true),
                        'reserve_securite_utilisee' => in_array('reserve_securite', $choix['relachement'], true),
                        'rupture_stock_ignoree' => in_array('stock', $choix['relachement'], true),
                        'portion_ratios' => $this->ratios($besoinsEq, $groupe['equipage_ids'], []),
                    ];
                }
            }
        }

        usort($repasGeneres, static fn ($a, $b) => [$a['date'], $ordre[Texte::cle($a['type_repas'])] ?? 9] <=> [$b['date'], $ordre[Texte::cle($b['type_repas'])] ?? 9]);

        $simulation = $this->simuler($d, [
            'equipage_ids' => $equipageIds,
            'repas' => array_map(static fn ($r) => [
                'date' => $r['date'], 'type_repas' => $r['type_repas'], 'recette_id' => $r['recette_choisie_id'],
                'portions' => $r['portions'], 'equipage_ids' => $r['equipage_ids'], 'portion_ratios' => $r['portion_ratios'],
            ], $repasGeneres),
        ]);
        $simulation['alertes'] = array_merge($alertes, $simulation['alertes']);

        return $simulation + [
            'repas_generes' => array_map(static function ($r) {
                unset($r['portion_ratios']);

                return $r;
            }, $repasGeneres),
            'repas_a_enregistrer' => array_map(static fn ($r) => [
                'date' => $r['date'], 'type_repas' => $r['type_repas'], 'recette_id' => $r['recette_choisie_id'], 'portions' => (float) $r['portions'],
                'occupants' => array_map(static fn ($id, $ratio) => ['equipage_id' => $id, 'portion_ratio' => $ratio], array_keys($r['portion_ratios']), $r['portion_ratios']),
            ], $repasGeneres),
        ];
    }

    /** GET /api/planning-repas?date_debut=&date_fin=&equipage_id= */
    public function lire(DonneesVaisseau $d, ?string $dateDebut, ?string $dateFin, ?int $equipageId): array
    {
        $du = null !== $dateDebut ? $this->date($dateDebut, 'date_debut') : null;
        $au = null !== $dateFin ? $this->date($dateFin, 'date_fin') : null;
        if (null !== $equipageId) {
            if (!$d->planningOccupantLie) {
                throw MoteurException::nonDisponible('Filtre equipage_id indisponible : PLANNING_REPAS_OCCUPANT n\'a pas de colonne Id_PLANNING_REPAS (gap #10)');
            }
            if (!isset($d->equipages[$equipageId])) {
                throw MoteurException::introuvable(sprintf('Équipage %d introuvable', $equipageId));
            }
        }

        $besoinsEq = $this->besoins->besoinsEquipage($d);
        $energieMoyenne = $besoinsEq ? array_sum(array_map(static fn ($b) => $b['energy_kcal'], $besoinsEq)) / count($besoinsEq) : 0.0;
        $planning = [];
        foreach ($d->planning as $p) {
            if ((null !== $du && $p['date'] < $du) || (null !== $au && $p['date'] > $au)) {
                continue;
            }
            $recette = $d->recettes[$p['recette_id']] ?? null;
            if (null === $recette) {
                continue;
            }
            $portionG = RecetteCalculator::portionG($recette, $p['type_repas'], $energieMoyenne);
            $ligne = [
                'id' => $p['id'],
                'date' => $p['date']->format('Y-m-d'),
                'type_repas' => $d->typeRepasParCle($p['type_repas'])['libelle'] ?? $p['type_repas'],
                'recette_id' => $recette['id'],
                'libelle' => $recette['libelle'],
                'portions_prevues' => $p['portions'],
                'portion_standard_g' => round($portionG, 1),
                'apport_total' => Nutriments::arrondir($this->recettes->apport($recette, $p['portions'] * $portionG)),
            ];

            if (null !== $equipageId) {
                $occupant = null;
                foreach ($p['occupants'] ?? [] as $o) {
                    if ($o['equipage_id'] === $equipageId) {
                        $occupant = $o;
                    }
                }
                if (null === $occupant) {
                    continue;
                }
                $ratio = $occupant['portion_ratio'] ?? 1.0;
                $ligne['portion_ratio'] = $ratio;
                $ligne['apport_occupant'] = Nutriments::arrondir($this->recettes->apport($recette, $portionG * $ratio));
            }
            $planning[] = $ligne;
        }

        return ['planning' => $planning];
    }

    /**
     * POST /api/journal-repas — valide l'entrée et calcule l'apport du repas.
     * L'écriture est faite par MoteurCalcul.
     *
     * @return array{equipage_id: int, recette_id: int, type_repas_id: int, date_heure: \DateTimeImmutable, portion_g: float, apport: array<string, float>}
     */
    public function repasJournal(DonneesVaisseau $d, array $entree): array
    {
        $equipageId = (int) ($entree['equipage_id'] ?? 0);
        $eq = $d->equipages[$equipageId] ?? throw MoteurException::invalide(sprintf('equipage_id %d inconnu', $equipageId));
        $recette = $d->recettes[(int) ($entree['recette_id'] ?? 0)] ?? throw MoteurException::invalide('recette_id inconnu');
        $type = $d->typesRepas[(int) ($entree['type_repas_id'] ?? 0)] ?? throw MoteurException::invalide('type_repas_id inconnu');
        try {
            $dateHeure = new \DateTimeImmutable($entree['date_heure'] ?? 'now');
        } catch (\Exception) {
            throw MoteurException::invalide('date_heure invalide');
        }
        $portionG = isset($entree['portion_g']) ? (float) $entree['portion_g'] : null;
        if (null !== $portionG && $portionG <= 0) {
            throw MoteurException::invalide('portion_g doit être positif');
        }
        $portionG ??= RecetteCalculator::portionG($recette, $type['cle'], $this->besoins->besoinIndividuel($eq)['energy_kcal']);

        return [
            'equipage_id' => $equipageId,
            'recette_id' => $recette['id'],
            'type_repas_id' => $type['id'],
            'date_heure' => $dateHeure,
            'portion_g' => round($portionG, 2),
            'apport' => Nutriments::arrondir($this->recettes->apport($recette, $portionG)),
        ];
    }

    /**
     * Évalue les candidats d'un créneau pour un groupe et tire la recette (§8.3).
     * Relâche successivement le délai minimal, la réserve de sécurité, puis (si autorisé) le stock.
     */
    private function choisir(DonneesVaisseau $d, array $c, Randomizer $aleatoire): ?array
    {
        $niveaux = [[], ['cooldown'], ['cooldown', 'reserve_securite']];
        if ($c['autoriser_rupture']) {
            $niveaux[] = ['cooldown', 'reserve_securite', 'stock'];
        }

        foreach ($niveaux as $relachement) {
            $scores = [];
            foreach ($d->recettes as $recette) {
                $s = $this->evaluer($d, $recette, $c, $relachement);
                if (null !== $s) {
                    $scores[] = $s;
                }
            }
            if ([] === $scores) {
                continue;
            }
            usort($scores, static fn ($a, $b) => $b['score'] <=> $a['score']);
            $top = array_slice($scores, 0, Parametres::TOP_K);

            // Tirage pondéré par le score parmi les K meilleures.
            $total = array_sum(array_map(static fn ($s) => max(0.001, $s['score']), $top));
            $tirage = self::tirageFloat($aleatoire, $total);
            $choisi = $top[count($top) - 1];
            foreach ($top as $s) {
                $tirage -= max(0.001, $s['score']);
                if ($tirage <= 0) {
                    $choisi = $s;
                    break;
                }
            }
            $choisi['candidats'] = count($scores);
            $choisi['relachement'] = $relachement;

            return $choisi;
        }

        return null;
    }

    /**
     * Flottant uniforme dans [0, $max[ tiré depuis $aleatoire. Randomizer::getFloat() n'existe qu'à
     * partir de PHP 8.3 (le projet cible >=8.2) : on retrouve un tirage uniforme équivalent à partir
     * de getInt(), disponible depuis PHP 8.2, en conservant le déterminisme du moteur seedé.
     */
    private static function tirageFloat(Randomizer $aleatoire, float $max): float
    {
        return $max * ($aleatoire->getInt(0, PHP_INT_MAX) / PHP_INT_MAX);
    }

    /**
     * Score d'une recette pour un créneau (§8.2), null si elle est exclue.
     *
     * @param list<string> $relachement
     */
    private function evaluer(DonneesVaisseau $d, array $recette, array $c, array $relachement): ?array
    {
        $groupe = $c['groupe'];
        if ($recette['pour100g']['kcal'] <= 0 || !in_array($c['type']['cle'], $this->recettes->creneaux($d, $recette), true)) {
            return null;
        }
        if (array_intersect($this->recettes->allergenes($d, $recette), $groupe['allergene_ids'])) {
            return null;
        }

        // Rotation (§7).
        $contexte = $c['contexte'];
        $categorie = $recette['categorie_id'] ?? 0;
        $cible = $contexte['cooldown_cible'][$categorie] ?? 1;
        $cooldownMin = max(2, intdiv($cible, 3));
        $dernier = null;
        $frequences = [];
        $debutFenetre = $c['date']->modify(sprintf('-%d days', Parametres::FENETRE_EQUITE_JOURS));
        foreach ($c['historique'] as $h) {
            if ($h['date'] >= $c['date'] || (null !== $h['equipage_ids'] && !array_intersect($h['equipage_ids'], $groupe['equipage_ids']))) {
                continue;
            }
            if ($h['recette_id'] === $recette['id'] && (null === $dernier || $h['date'] > $dernier)) {
                $dernier = $h['date'];
            }
            if ($h['date'] >= $debutFenetre) {
                $frequences[$h['recette_id']] = ($frequences[$h['recette_id']] ?? 0) + 1;
            }
        }
        $joursRepos = null === $dernier ? INF : (int) $dernier->diff($c['date'])->format('%a');
        if ($joursRepos < $cooldownMin && !in_array('cooldown', $relachement, true)) {
            return null;
        }
        $recence = min(1.0, $joursRepos / $cible);

        $memeCategorie = $contexte['par_categorie'][$categorie] ?? [$recette['id']];
        $freqMoyenne = array_sum(array_map(static fn ($id) => $frequences[$id] ?? 0, $memeCategorie)) / count($memeCategorie);
        $equite = 1 - min(1.0, ($frequences[$recette['id']] ?? 0) / ($freqMoyenne + 1));

        $diversite = 1.0;
        $precedent = $c['precedent'];
        if (null !== $precedent) {
            if ($precedent['recette']['categorie_id'] === $recette['categorie_id']) {
                $diversite -= 0.5;
            }
        }
        $hier = $c['date']->modify('-1 day');
        foreach ($c['historique'] as $h) {
            if ($h['date'] == $hier && $h['type_repas'] === $c['type']['cle'] && isset($d->recettes[$h['recette_id']])
                && (null === $h['equipage_ids'] || array_intersect($h['equipage_ids'], $groupe['equipage_ids']))
                && array_intersect_key($recette['ingredients'], $d->recettes[$h['recette_id']]['ingredients'])) {
                $diversite -= 0.5;
                break;
            }
        }
        $rotation = Parametres::POIDS_RECENCE * $recence + Parametres::POIDS_EQUITE * $equite + Parametres::POIDS_DIVERSITE * max(0.0, $diversite);

        // Quantités nécessaires et faisabilité en stock (§6.2).
        $portions = count($groupe['equipage_ids']);
        $grammesRecette = $portions * RecetteCalculator::portionG($recette, $c['type']['cle'], $c['energie']);
        $besoinsAliments = [];
        foreach ($this->recettes->composition($d, $recette)['aliments'] as $code => $g100) {
            $besoinsAliments[$code] = $grammesRecette * $g100 / 100;
        }
        $prelevements = ['courante' => [], 'securite' => []];
        foreach ($besoinsAliments as $code => $g) {
            $courant = max(0.0, $c['stock']['courante'][$code] ?? 0.0);
            $pris = min($courant, $g);
            if ($pris > 0) {
                $prelevements['courante'][$code] = $pris;
            }
            $reste = $g - $pris;
            if ($reste > 1e-6 && in_array('reserve_securite', $relachement, true)) {
                $pris = min(max(0.0, $c['stock']['securite'][$code] ?? 0.0), $reste);
                if ($pris > 0) {
                    $prelevements['securite'][$code] = $pris;
                }
                $reste -= $pris;
            }
            if ($reste > 1e-6 && !in_array('stock', $relachement, true)) {
                return null;
            }
        }

        // Nutrition (§8.1), avec la pondération issue de la rétroaction (§13).
        $apport = $this->recettes->apport($recette, $grammesRecette);
        $ecart = 0.0;
        $sommePoids = 0.0;
        foreach (Nutriments::CLES as $cle) {
            if ($c['cible'][$cle] <= 0) {
                continue;
            }
            $w = $c['poids'][$cle] ?? 1.0;
            $ecart += $w * min(1.0, abs($apport[$cle] - $c['cible'][$cle]) / $c['cible'][$cle]);
            $sommePoids += $w;
        }
        $nutrition = $sommePoids > 0 ? max(0.0, 1 - $ecart / $sommePoids) : 0.0;

        // Anti-gaspillage (§6.4) : urgence du lot le plus urgent de chaque ingrédient, pondérée par la quantité.
        $peremption = 0.0;
        $total = array_sum($besoinsAliments);
        if ($total > 0) {
            foreach ($besoinsAliments as $code => $g) {
                $lots = $this->stock->lotsUtilisables($d, $c['date'], Parametres::RESERVE_UTILISABLE, $code);
                if ($lots) {
                    $peremption += $g / $total * StockCalculator::urgencePeremption($lots[0], $c['date']);
                }
            }
        }

        // Préservation des réserves (§6.5) : pénalité si des ingrédients passent sous leur stock minimum.
        $sousSeuil = 0;
        foreach ($besoinsAliments as $code => $g) {
            if (($c['stock']['courante'][$code] ?? 0.0) - $g < ($c['seuils'][$code] ?? 0.0)) {
                ++$sousSeuil;
            }
        }
        $reserve = $besoinsAliments ? -$sousSeuil / count($besoinsAliments) : 0.0;

        $score = Parametres::POIDS_NUTRITION * $nutrition + Parametres::POIDS_ROTATION * $rotation
            + Parametres::POIDS_PEREMPTION * $peremption + Parametres::POIDS_RESERVE * $reserve;

        return [
            'recette_id' => $recette['id'],
            'score' => $score,
            'detail' => ['nutrition' => $nutrition, 'rotation' => $rotation, 'peremption' => $peremption, 'reserve' => $reserve],
            'prelevements' => $prelevements,
        ];
    }

    /**
     * Historique des repas servis ou prévus, pour le roulement : PLANNING_REPAS (tout l'équipage, sauf si le
     * lien occupant existe) et JOURNAL_REPAS (un équipier).
     *
     * @return list<array{date: \DateTimeImmutable, recette_id: int, equipage_ids: list<int>|null, type_repas: string}>
     */
    private function historique(DonneesVaisseau $d): array
    {
        $h = [];
        foreach ($d->planning as $p) {
            $h[] = [
                'date' => $p['date'], 'recette_id' => $p['recette_id'], 'type_repas' => $p['type_repas'],
                'equipage_ids' => null !== $p['occupants'] && [] !== $p['occupants'] ? array_column($p['occupants'], 'equipage_id') : null,
            ];
        }
        foreach ($d->journal as $j) {
            $h[] = [
                'date' => $j['date_heure']->setTime(0, 0), 'recette_id' => $j['recette_id'], 'equipage_ids' => [$j['equipage_id']],
                'type_repas' => $d->typesRepas[$j['type_repas_id']]['cle'] ?? '',
            ];
        }

        return $h;
    }

    /**
     * Délai cible par catégorie (§7.1) : taille de la catégorie / créneaux compatibles par jour.
     *
     * @return array{cooldown_cible: array<int, int>, par_categorie: array<int, list<int>>}
     */
    private function contexteRotation(DonneesVaisseau $d): array
    {
        $parCategorie = [];
        $creneaux = [];
        foreach ($d->recettes as $r) {
            $c = $this->recettes->creneaux($d, $r);
            if ([] === $c) {
                continue;
            }
            $cat = $r['categorie_id'] ?? 0;
            $parCategorie[$cat][] = $r['id'];
            $creneaux[$cat] = max($creneaux[$cat] ?? 1, count($c));
        }
        $cibles = [];
        foreach ($parCategorie as $cat => $ids) {
            $cibles[$cat] = max(1, (int) ceil(count($ids) / $creneaux[$cat]));
        }

        return ['cooldown_cible' => $cibles, 'par_categorie' => $parCategorie];
    }

    /**
     * portion_ratio par convive (§8.3) : besoin kcal / besoin kcal moyen des convives, sauf valeur fournie.
     *
     * @param array<int, array<string, mixed>> $besoinsEq
     * @param list<int>                        $convives
     * @param array<int|string, float>         $fournis
     *
     * @return array<int, float>
     */
    private function ratios(array $besoinsEq, array $convives, array $fournis): array
    {
        if ([] === $convives) {
            return [];
        }
        $moyenne = array_sum(array_map(static fn ($id) => $besoinsEq[$id]['energy_kcal'], $convives)) / count($convives);
        $ratios = [];
        foreach ($convives as $id) {
            $ratios[$id] = isset($fournis[$id]) ? (float) $fournis[$id] : ($moyenne > 0 ? $besoinsEq[$id]['energy_kcal'] / $moyenne : 1.0);
        }

        return $ratios;
    }

    /** @return list<int> */
    private function validerEquipages(DonneesVaisseau $d, mixed $ids): array
    {
        if (!is_array($ids) || [] === $ids) {
            throw MoteurException::invalide('equipage_ids doit être une liste non vide');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        foreach ($ids as $id) {
            if (!isset($d->equipages[$id])) {
                throw MoteurException::invalide(sprintf('equipage_id %d inconnu', $id));
            }
        }

        return $ids;
    }

    /** @return array{0: \DateTimeImmutable, 1: array, 2: array} */
    private function validerRepas(DonneesVaisseau $d, mixed $repas, int $i): array
    {
        if (!is_array($repas)) {
            throw MoteurException::invalide(sprintf('repas[%d] invalide', $i));
        }
        $date = $this->date($repas['date'] ?? null, sprintf('repas[%d].date', $i));
        $type = $d->typeRepasParCle(Texte::cle($repas['type_repas'] ?? ''))
            ?? throw MoteurException::invalide(sprintf('repas[%d].type_repas « %s » ne correspond à aucun type_repas', $i, $repas['type_repas'] ?? ''));
        $recette = $d->recettes[(int) ($repas['recette_id'] ?? 0)]
            ?? throw MoteurException::invalide(sprintf('repas[%d].recette_id %s inconnu', $i, $repas['recette_id'] ?? 'null'));

        return [$date, $type, $recette];
    }

    private function date(mixed $valeur, string $champ): \DateTimeImmutable
    {
        $date = is_string($valeur) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) : false;
        if (false === $date) {
            throw MoteurException::invalide(sprintf('%s doit être une date au format YYYY-MM-DD', $champ));
        }

        return $date;
    }
}
