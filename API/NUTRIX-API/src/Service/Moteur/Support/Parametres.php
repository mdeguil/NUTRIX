<?php

namespace App\Service\Moteur\Support;

/**
 * Paramètres par défaut du moteur de calcul (MOTEUR_CALCUL.md §14).
 *
 * Tout ce qui n'existe pas en base (seuils de stock, coefficients agricoles…)
 * est regroupé ici pour pouvoir être ajusté sans toucher aux formules.
 */
final class Parametres
{
    /** Répartition de l'énergie journalière par créneau (clé = libellé normalisé, cf. Texte::cle()). */
    public const COEFFICIENTS_CRENEAU = [
        'petitdejeuner' => 0.20,
        'dejeuner' => 0.35,
        'diner' => 0.35,
        'collation' => 0.10,
    ];

    /** Hypothèses EFSA par défaut quand la donnée individuelle manque. */
    public const AGE_MENOPAUSE = 55;
    public const AGE_MAX_EFSA_ENERGIE = 79;

    /** Stock (§6). */
    public const RESERVE_UTILISABLE = ['courante'];
    public const STATUTS_LOT_UTILISABLES = ['frais', 'transforme', 'congele', 'disponible'];
    public const SEUIL_ALERTE_PEREMPTION_JOURS = 7;

    /** Récoltes : statuts « encore à venir » et « perdue » (valeurs README + valeurs présentes dans le dump). */
    public const STATUTS_RECOLTE_A_VENIR = ['semis', 'croissance', 'replantee', 'planifiee', 'encours'];
    public const STATUTS_RECOLTE_PERDUE = ['perdue'];
    public const TAUX_PERTE_RECOLTE_DEFAUT = 0.05;

    /** Portions : taille d'une part calculée sur l'énergie du créneau, bornée (g). */
    public const PORTION_G_MIN = 100.0;
    public const PORTION_G_MAX = 600.0;

    /** Roulement anti-lassitude (§7). */
    public const FENETRE_EQUITE_JOURS = 14;
    public const POIDS_RECENCE = 0.5;
    public const POIDS_EQUITE = 0.3;
    public const POIDS_DIVERSITE = 0.2;

    /** Score final (§8.2) et tirage (§8.3). */
    public const POIDS_NUTRITION = 0.4;
    public const POIDS_ROTATION = 0.3;
    public const POIDS_PEREMPTION = 0.2;
    public const POIDS_RESERVE = 0.1;
    public const TOP_K = 3;
    public const HORIZON_PLANIFICATION_JOURS = 7;

    /** Rétroaction (§13) : nombre de jours consécutifs en déficit avant majoration, et facteur appliqué. */
    public const RETROACTION_JOURS_CONSECUTIFS = 3;
    public const RETROACTION_FACTEUR = 1.5;

    /**
     * Gestion de stock par aliment (prévision 8 semaines).
     *  - stock minimum  = consommation journalière × JOURS_STOCK_MINIMUM
     *  - stock d'alerte = stock minimum + consommation journalière × délai d'obtention
     *                     (délai = cycle de culture moyen pour un aliment cultivable)
     *  - stock maximum  = stock minimum + consommation journalière × JOURS_COUVERTURE_CIBLE
     *                     (plafonné à ce qui peut être consommé avant péremption)
     */
    public const PREVISION_SEMAINES = 8;
    public const JOURS_STOCK_MINIMUM = 7;
    public const JOURS_COUVERTURE_CIBLE = 14;
    public const DELAI_APPRO_NON_CULTIVABLE_JOURS = 14;

    /**
     * Coefficients du module Agriculture, absents de la BDD NUTRIX (MOTEUR_CALCUL.md §11.1).
     * Valeurs indicatives pour une culture hydroponique sous LED, à remplacer par les données réelles.
     */
    public const DENSITE_SEMIS_GRAINES_M2 = 25.0;
    public const CONSO_EAU_L_M2_J = 3.0;
    public const CONSO_ENERGIE_KWH_M2_J = 0.25;

    /** Catégories de recettes qui ne sont pas des repas (ingrédients intermédiaires). */
    public const CATEGORIES_RECETTE_NON_REPAS = ['reutilisationbiomasse', 'valorisationzerodechet'];

    /**
     * Créneaux compatibles par catégorie de recette, utilisés tant que la table
     * RECETTE_TYPE_REPAS n'existe pas en base (API_BESOINS_PLANNING.md gap #12).
     */
    public const CRENEAUX_PAR_CATEGORIE = [
        'petitdejeuner' => ['petitdejeuner'],
        'dejeuner' => ['dejeuner'],
        'diner' => ['diner'],
        'collation' => ['collation'],
        'boissons' => ['petitdejeuner', 'collation'],
        'boulangerie' => ['petitdejeuner', 'collation'],
        'cerealeslegumineuses' => ['dejeuner', 'diner'],
        'platschauds' => ['dejeuner', 'diner'],
        'saladesfroides' => ['dejeuner', 'diner'],
        'recettedebase' => ['dejeuner', 'diner'],
    ];

    /**
     * Allergènes déduits du code aliment, utilisés tant que la table ALIMENT_ALLERGENE
     * n'existe pas en base (gap #11). Clé = libellé d'allergène normalisé, valeur = mots du code aliment
     * (un mot correspond à un segment du code séparé par « _ », comparé en préfixe ; « = » impose le code exact).
     */
    public const ALLERGENES_PAR_MOT_CLE = [
        'gluten' => ['BLE', 'PAIN', 'PATES', 'FLOCONS_DAVOINE'],
        'lactose' => ['=LAIT_POUDRE'],
        'arachide' => ['ARACHIDE'],
        'fruitsacoque' => ['=NOIX'],
        'soja' => ['SOJA', 'TOFU', 'EDAMAME'],
    ];

    private function __construct()
    {
    }
}
