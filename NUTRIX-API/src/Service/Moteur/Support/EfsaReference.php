<?php

namespace App\Service\Moteur\Support;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Accès au référentiel EFSA (efsa_drv_reference.json).
 *
 * Chaque bloc nutriment est une liste de lignes {age_label, sex?, value…}. La ligne retenue
 * est celle dont la tranche d'âge contient l'âge de l'équipier et dont le sexe correspond
 * (ou vaut « both », ou est absent). Si plusieurs tranches conviennent, la plus étroite gagne.
 */
class EfsaReference
{
    /** @var array<string, mixed>|null */
    private ?array $donnees = null;

    /** @var array<string, array{min: float, max: float}> */
    private array $tranches = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/nutrix/efsa_drv_reference.json')]
        private readonly string $chemin,
    ) {
    }

    /** @return array<string, mixed> */
    public function donnees(): array
    {
        if (null === $this->donnees) {
            $json = file_get_contents($this->chemin);
            if (false === $json) {
                throw new \RuntimeException(sprintf('Référentiel EFSA introuvable : %s', $this->chemin));
            }
            $this->donnees = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            foreach ($this->donnees['age_brackets'] as $tranche) {
                $this->tranches[$tranche['label']] = ['min' => (float) $tranche['age_min'], 'max' => (float) $tranche['age_max']];
            }
        }

        return $this->donnees;
    }

    /** @return mixed valeur au chemin « a.b.c » du JSON, null si absente */
    public function bloc(string $chemin): mixed
    {
        $noeud = $this->donnees();
        foreach (explode('.', $chemin) as $cle) {
            if (!is_array($noeud) || !array_key_exists($cle, $noeud)) {
                return null;
            }
            $noeud = $noeud[$cle];
        }

        return $noeud;
    }

    public function kcalParMj(): float
    {
        return (float) $this->bloc('energy_ar.kcal_per_mj');
    }

    public function lpiParDefaut(): float
    {
        return (float) $this->bloc('minerals.zinc.default_lpi_assumption_mg');
    }

    /**
     * @param list<array<string, mixed>> $lignes
     * @param bool                       $replier si aucune tranche ne contient l'âge, retenir la plus proche
     *
     * @return array<string, mixed>|null
     */
    public function ligne(array $lignes, float $age, string $sexe, bool $replier = false): ?array
    {
        $this->donnees();
        $meilleure = null;
        $largeur = INF;
        $proche = null;
        $distance = INF;

        foreach ($lignes as $ligne) {
            $sexeLigne = $ligne['sex'] ?? 'both';
            if ('both' !== $sexeLigne && $sexeLigne !== $sexe) {
                continue;
            }
            $tranche = $this->tranches[$ligne['age_label']] ?? null;
            if (null === $tranche) {
                continue;
            }
            if ($age >= $tranche['min'] && $age <= $tranche['max']) {
                $l = $tranche['max'] - $tranche['min'];
                // À largeur égale, une ligne propre au sexe l'emporte sur « both ».
                if ($l < $largeur || ($l === $largeur && 'both' !== $sexeLigne)) {
                    $meilleure = $ligne;
                    $largeur = $l;
                }
            } else {
                $d = $age < $tranche['min'] ? $tranche['min'] - $age : $age - $tranche['max'];
                if ($d < $distance) {
                    $proche = $ligne;
                    $distance = $d;
                }
            }
        }

        return $meilleure ?? ($replier ? $proche : null);
    }

    /**
     * Valeur d'une ligne, quelle que soit sa forme : value, value_min/value_max (moyenne),
     * value_by_status (fer) ou value_by_lpi_mg (zinc, interpolé entre les paliers).
     *
     * @param array<string, mixed>|null $ligne
     */
    public function valeur(?array $ligne, ?string $statutMenopause = null, ?float $lpi = null): ?float
    {
        if (null === $ligne) {
            return null;
        }
        if (isset($ligne['value'])) {
            return (float) $ligne['value'];
        }
        if (isset($ligne['value_min'], $ligne['value_max'])) {
            return ((float) $ligne['value_min'] + (float) $ligne['value_max']) / 2;
        }
        if (isset($ligne['value_by_status'])) {
            return (float) ($ligne['value_by_status'][$statutMenopause ?? 'premenopausal'] ?? reset($ligne['value_by_status']));
        }
        if (isset($ligne['value_by_lpi_mg'])) {
            return self::interpoler($ligne['value_by_lpi_mg'], $lpi ?? $this->lpiParDefaut());
        }

        return null;
    }

    /**
     * Interpolation linéaire dans une table {abscisse: valeur}, bornée aux extrémités.
     *
     * @param array<string|int, float|int> $table
     */
    public static function interpoler(array $table, float $x): float
    {
        $points = [];
        foreach ($table as $abscisse => $valeur) {
            $points[] = [(float) $abscisse, (float) $valeur];
        }
        usort($points, static fn ($a, $b) => $a[0] <=> $b[0]);

        if ($x <= $points[0][0]) {
            return $points[0][1];
        }
        foreach ($points as $i => [$x1, $y1]) {
            if (!isset($points[$i + 1])) {
                break;
            }
            [$x2, $y2] = $points[$i + 1];
            if ($x <= $x2) {
                return $y1 + ($y2 - $y1) * ($x - $x1) / ($x2 - $x1);
            }
        }

        return $points[count($points) - 1][1];
    }
}
