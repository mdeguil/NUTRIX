<?php

namespace App\Service\Moteur\Support;

/**
 * Normalisation des libellés et des valeurs texte de la base.
 *
 * La base mélange casse et accents (« Courante », « Petit-dejeuner », « En cours »),
 * les comparaisons se font donc toujours sur une clé normalisée.
 */
final class Texte
{
    /** « Petit-déjeuner » → « petitdejeuner », « En cours » → « encours ». */
    public static function cle(?string $valeur): string
    {
        if (null === $valeur) {
            return '';
        }
        $ascii = strtr(mb_strtolower(trim($valeur)), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);

        return preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
    }

    /**
     * Les colonnes nutritionnelles de Recette sont des VARCHAR (MOTEUR_CALCUL.md §1 écart #8) :
     * « 18 kcal », « 5,2 » ou « 112 » doivent tous donner un nombre.
     */
    public static function nombre(mixed $valeur): float
    {
        if (null === $valeur || '' === $valeur) {
            return 0.0;
        }
        if (is_int($valeur) || is_float($valeur)) {
            return (float) $valeur;
        }
        if (preg_match('/-?\d+(?:[.,]\d+)?/', (string) $valeur, $m)) {
            return (float) str_replace(',', '.', $m[0]);
        }

        return 0.0;
    }

    private function __construct()
    {
    }
}
