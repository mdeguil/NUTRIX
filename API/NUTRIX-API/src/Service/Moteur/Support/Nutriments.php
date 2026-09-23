<?php

namespace App\Service\Moteur\Support;

/**
 * Vecteurs d'apport en macronutriments.
 *
 * La base ne renseigne que 5 valeurs par aliment et par recette (kcal, protéines,
 * glucides, lipides, fibres) : c'est donc sur ces 5 nutriments que portent tous les
 * calculs côté stock, repas et prévisions. Minéraux et vitamines ne sont connus que
 * côté besoins (EFSA).
 */
final class Nutriments
{
    public const CLES = ['kcal', 'proteines_g', 'glucides_g', 'lipides_g', 'fibres_g'];

    /** Correspondance clé d'apport → clé du format BesoinNutritionnel. */
    public const CLES_BESOIN = [
        'kcal' => 'energy_kcal',
        'proteines_g' => 'protein_g',
        'glucides_g' => 'carbohydrates_g',
        'lipides_g' => 'lipids_g',
        'fibres_g' => 'fiber_g',
    ];

    /** @return array<string, float> */
    public static function zero(): array
    {
        return array_fill_keys(self::CLES, 0.0);
    }

    /**
     * @param array<string, float> $a
     * @param array<string, float> $b
     *
     * @return array<string, float>
     */
    public static function ajouter(array $a, array $b, float $facteur = 1.0): array
    {
        foreach (self::CLES as $cle) {
            $a[$cle] = ($a[$cle] ?? 0.0) + ($b[$cle] ?? 0.0) * $facteur;
        }

        return $a;
    }

    /**
     * @param array<string, float> $v
     *
     * @return array<string, float>
     */
    public static function multiplier(array $v, float $facteur): array
    {
        return self::ajouter(self::zero(), $v, $facteur);
    }

    /**
     * Apport de `$grammes` d'un aliment ou d'une recette dont les valeurs sont données pour 100 g.
     *
     * @param array<string, float> $pour100g
     *
     * @return array<string, float>
     */
    public static function pourGrammes(array $pour100g, float $grammes): array
    {
        return self::multiplier($pour100g, $grammes / 100);
    }

    /**
     * Besoin en macronutriments extrait d'un BesoinNutritionnel. Pour les glucides et les
     * lipides, exprimés en fourchette, on retient la borne basse : c'est le minimum à couvrir.
     *
     * @param array<string, mixed> $besoin
     *
     * @return array<string, float>
     */
    public static function depuisBesoin(array $besoin): array
    {
        $v = [];
        foreach (self::CLES_BESOIN as $cle => $cleBesoin) {
            $valeur = $besoin[$cleBesoin] ?? 0.0;
            $v[$cle] = (float) (is_array($valeur) ? $valeur['min'] : $valeur);
        }

        return $v;
    }

    /**
     * Couverture en % (apport / besoin). Null quand le besoin est nul.
     *
     * @param array<string, float> $apport
     * @param array<string, float> $besoin
     *
     * @return array<string, float|null>
     */
    public static function couverture(array $apport, array $besoin): array
    {
        $c = [];
        foreach (self::CLES as $cle) {
            $c[$cle] = ($besoin[$cle] ?? 0.0) > 0 ? round(100 * ($apport[$cle] ?? 0.0) / $besoin[$cle], 1) : null;
        }

        return $c;
    }

    /**
     * @param array<string, float> $v
     *
     * @return array<string, float>
     */
    public static function arrondir(array $v, int $decimales = 1): array
    {
        return array_map(static fn ($x) => round((float) $x, $decimales), $v);
    }

    private function __construct()
    {
    }
}
