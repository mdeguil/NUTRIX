<?php

namespace App\Service\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\Support\Nutriments;
use App\Service\Moteur\Support\Parametres;

/**
 * Stock utilisable, FEFO, réserves (MOTEUR_CALCUL.md §6) et autonomie (§9).
 */
class StockCalculator
{
    public function __construct(private readonly BesoinsCalculator $besoins)
    {
    }

    /**
     * Lots mobilisables à une date (§6.1) : réserve autorisée, statut consommable, non périmés, quantité > 0.
     * Triés FEFO (premier à périmer en premier, sans date de péremption en dernier).
     *
     * @param list<string> $reserves
     *
     * @return list<array<string, mixed>>
     */
    public function lotsUtilisables(DonneesVaisseau $d, \DateTimeImmutable $date, array $reserves = Parametres::RESERVE_UTILISABLE, ?string $aliment = null): array
    {
        $lots = array_values(array_filter($d->lots, static fn (array $l) => $l['quantite_g'] > 0
            && in_array($l['type_reserve'], $reserves, true)
            && in_array($l['statut'], Parametres::STATUTS_LOT_UTILISABLES, true)
            && (null === $l['date_peremption'] || $l['date_peremption'] >= $date)
            && (null === $aliment || $l['aliment'] === $aliment)));
        usort($lots, static fn ($a, $b) => [null === $a['date_peremption'], $a['date_peremption']] <=> [null === $b['date_peremption'], $b['date_peremption']]);

        return $lots;
    }

    /**
     * Quantité mobilisable par aliment (g).
     *
     * @param list<string> $reserves
     *
     * @return array<string, float>
     */
    public function disponible(DonneesVaisseau $d, \DateTimeImmutable $date, array $reserves = Parametres::RESERVE_UTILISABLE): array
    {
        $dispo = [];
        foreach ($this->lotsUtilisables($d, $date, $reserves) as $lot) {
            $dispo[$lot['aliment']] = ($dispo[$lot['aliment']] ?? 0.0) + $lot['quantite_g'];
        }

        return $dispo;
    }

    /**
     * Stock par aliment et par type de réserve (lots consommables et non périmés).
     *
     * @return array<string, array<string, float>>
     */
    public function parReserve(DonneesVaisseau $d, \DateTimeImmutable $date): array
    {
        $r = [];
        $toutes = array_unique(array_column($d->lots, 'type_reserve'));
        foreach ($this->lotsUtilisables($d, $date, $toutes) as $lot) {
            $r[$lot['aliment']][$lot['type_reserve']] = ($r[$lot['aliment']][$lot['type_reserve']] ?? 0.0) + $lot['quantite_g'];
        }

        return $r;
    }

    /**
     * Sorties FEFO pour prélever `$quantite` g d'un aliment (§6.3). Ne modifie rien :
     * le résultat est appliqué en base par NutrixDataProvider::enregistrerSorties().
     *
     * @return array{sorties: list<array{lot_id: int, quantite_g: float, date_peremption: string|null}>, manque_g: float}
     */
    public function planifierSortie(DonneesVaisseau $d, string $aliment, float $quantite, \DateTimeImmutable $date): array
    {
        if ($quantite <= 0) {
            throw MoteurException::invalide('La quantité à prélever doit être positive');
        }
        $reste = $quantite;
        $sorties = [];
        foreach ($this->lotsUtilisables($d, $date, Parametres::RESERVE_UTILISABLE, $aliment) as $lot) {
            $pris = min($lot['quantite_g'], $reste);
            $sorties[] = ['lot_id' => $lot['id'], 'quantite_g' => $pris, 'date_peremption' => $lot['date_peremption']?->format('Y-m-d')];
            $reste -= $pris;
            if ($reste <= 0) {
                break;
            }
        }

        return ['sorties' => $sorties, 'manque_g' => max(0.0, $reste)];
    }

    /** Urgence de consommation d'un lot, 0 (loin de la péremption) à 1 (périme aujourd'hui) (§6.4). */
    public static function urgencePeremption(array $lot, \DateTimeImmutable $date): float
    {
        if (null === $lot['date_peremption']) {
            return 0.0;
        }
        $jours = (int) $date->diff($lot['date_peremption'])->format('%r%a');

        return max(0.0, min(1.0, 1 - $jours / Parametres::SEUIL_ALERTE_PEREMPTION_JOURS));
    }

    /**
     * Valeur nutritionnelle d'un stock exprimé en grammes par aliment.
     *
     * @param array<string, float> $grammesParAliment
     *
     * @return array<string, float>
     */
    public static function valeurNutritionnelle(DonneesVaisseau $d, array $grammesParAliment): array
    {
        $total = Nutriments::zero();
        foreach ($grammesParAliment as $code => $g) {
            if (isset($d->aliments[$code])) {
                $total = Nutriments::ajouter($total, Nutriments::pourGrammes($d->aliments[$code]['pour100g'], $g));
            }
        }

        return $total;
    }

    /**
     * Récoltes encore à venir sur [du, au], en grammes nets de pertes, par aliment.
     *
     * @return array<string, float>
     */
    public static function recoltesAVenir(DonneesVaisseau $d, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $r = [];
        foreach ($d->recoltes as $recolte) {
            if (!in_array($recolte['statut'], Parametres::STATUTS_RECOLTE_A_VENIR, true)) {
                continue;
            }
            $date = $recolte['date_recolte_prevue'];
            // Une récolte en retard (date prévue dépassée mais pas encore faite) est attendue au plus tôt aujourd'hui.
            if ($date < $du) {
                $date = $du;
            }
            if ($date > $au) {
                continue;
            }
            $r[$recolte['aliment']] = ($r[$recolte['aliment']] ?? 0.0) + self::quantiteNetteRecolte($d, $recolte);
        }

        return $r;
    }

    /** Quantité attendue d'une récolte, diminuée du taux de perte (historique de l'aliment si non renseigné). */
    public static function quantiteNetteRecolte(DonneesVaisseau $d, array $recolte): float
    {
        return $recolte['quantite_prevue_g'] * (1 - ($recolte['taux_perte'] ?? self::tauxPerteHistorique($d, $recolte['aliment'])));
    }

    /** Taux de perte moyen des récoltes passées d'un aliment, défaut sinon. */
    public static function tauxPerteHistorique(DonneesVaisseau $d, string $aliment): float
    {
        $taux = [];
        foreach ($d->recoltes as $r) {
            if ($r['aliment'] === $aliment && null !== $r['taux_perte']) {
                $taux[] = $r['taux_perte'];
            }
        }

        return $taux ? array_sum($taux) / count($taux) : Parametres::TAUX_PERTE_RECOLTE_DEFAUT;
    }

    /**
     * Autonomie en jours par nutriment : stock / besoin journalier (§9). Null si le besoin est nul.
     *
     * @param array<string, float> $stock
     * @param array<string, float> $besoinJour
     *
     * @return array{globale: float|null, limitant: string|null, par_nutriment: array<string, float|null>}
     */
    public static function autonomieJours(array $stock, array $besoinJour): array
    {
        $par = [];
        foreach (Nutriments::CLES as $cle) {
            $par[$cle] = ($besoinJour[$cle] ?? 0) > 0 ? round($stock[$cle] / $besoinJour[$cle], 1) : null;
        }
        $valeurs = array_filter($par, static fn ($v) => null !== $v);
        $globale = $valeurs ? min($valeurs) : null;

        return [
            'globale' => $globale,
            'limitant' => null !== $globale ? array_search($globale, $valeurs, true) : null,
            'par_nutriment' => $par,
        ];
    }

    /**
     * GET /api/stock/autonomie
     *
     * Autonomie sur la réserve courante (usage normal), variante incluant les récoltes attendues
     * sur l'horizon de prévision, et variante incluant toutes les réserves (sécurité, urgence, stratégique).
     */
    public function autonomie(DonneesVaisseau $d, int $horizonRecoltesJours = Parametres::PREVISION_SEMAINES * 7): array
    {
        $date = $d->aujourdhui;
        $besoins = $this->besoins->besoinsEquipage($d);
        $besoinJour = BesoinsCalculator::macrosEquipe($besoins, array_keys($besoins));

        $courant = $this->disponible($d, $date);
        $stockCourant = self::valeurNutritionnelle($d, $courant);
        $autonomie = self::autonomieJours($stockCourant, $besoinJour);

        $recoltes = self::recoltesAVenir($d, $date, $date->modify(sprintf('+%d days', $horizonRecoltesJours)));
        $avecRecoltes = self::autonomieJours(
            Nutriments::ajouter($stockCourant, self::valeurNutritionnelle($d, $recoltes)),
            $besoinJour
        );

        $toutesReserves = array_unique(array_column($d->lots, 'type_reserve'));
        $stockTotal = self::valeurNutritionnelle($d, $this->disponible($d, $date, $toutesReserves));
        $autonomieTotale = self::autonomieJours($stockTotal, $besoinJour);

        return [
            'date' => $date->format('Y-m-d'),
            'nb_equipiers' => count($besoins),
            'autonomie_globale_jours' => $autonomie['globale'],
            'nutriment_limitant' => $autonomie['limitant'],
            'par_nutriment' => $autonomie['par_nutriment'],
            'stock_disponible' => Nutriments::arrondir($stockCourant),
            'besoin_journalier_equipe' => Nutriments::arrondir($besoinJour),
            'avec_recoltes_prevues' => [
                'horizon_jours' => $horizonRecoltesJours,
                'recoltes_attendues_g' => array_map(static fn ($g) => round($g, 1), $recoltes),
                'autonomie_globale_jours' => $avecRecoltes['globale'],
                'nutriment_limitant' => $avecRecoltes['limitant'],
                'par_nutriment' => $avecRecoltes['par_nutriment'],
            ],
            'toutes_reserves' => [
                'reserves' => array_values($toutesReserves),
                'autonomie_globale_jours' => $autonomieTotale['globale'],
                'nutriment_limitant' => $autonomieTotale['limitant'],
                'par_nutriment' => $autonomieTotale['par_nutriment'],
            ],
            'alertes' => $this->alertesLots($d, $date),
            'meta' => [
                'reserves_utilisees' => Parametres::RESERVE_UTILISABLE,
                'hypotheses' => ['besoins glucides/lipides : borne basse de la fourchette EFSA'],
            ],
        ];
    }

    /**
     * Lots à surveiller : périmés avec du reste, ou proches de la péremption.
     *
     * @return list<array<string, mixed>>
     */
    public function alertesLots(DonneesVaisseau $d, \DateTimeImmutable $date): array
    {
        $alertes = [];
        foreach ($d->lots as $lot) {
            if ($lot['quantite_g'] <= 0 || null === $lot['date_peremption'] || in_array($lot['statut'], ['epuise', 'perime'], true)) {
                continue;
            }
            $jours = (int) $date->diff($lot['date_peremption'])->format('%r%a');
            if ($jours < 0) {
                $alertes[] = [
                    'niveau' => 'critique', 'type' => 'lot_perime', 'lot_id' => $lot['id'], 'aliment_id' => $lot['aliment'],
                    'quantite_g' => $lot['quantite_g'],
                    'message' => sprintf('Lot %d (%s) périmé depuis %d jour(s) : %.0f g à sortir du stock', $lot['id'], $lot['aliment'], -$jours, $lot['quantite_g']),
                ];
            } elseif ($jours <= Parametres::SEUIL_ALERTE_PEREMPTION_JOURS) {
                $alertes[] = [
                    'niveau' => 'warning', 'type' => 'peremption_proche', 'lot_id' => $lot['id'], 'aliment_id' => $lot['aliment'],
                    'quantite_g' => $lot['quantite_g'],
                    'message' => sprintf('Lot %d (%s) périme dans %d jour(s) : à consommer en priorité', $lot['id'], $lot['aliment'], $jours),
                ];
            }
        }

        return $alertes;
    }
}
