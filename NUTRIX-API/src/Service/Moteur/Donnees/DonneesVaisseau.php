<?php

namespace App\Service\Moteur\Donnees;

/**
 * Photographie en mémoire des données du vaisseau utilisées par le moteur.
 *
 * Les calculateurs ne lisent jamais la base directement : ils travaillent sur cet objet,
 * chargé par NutrixDataProvider. Ça les rend testables sans base.
 *
 * Toutes les chaînes « énumérées » (type_reserve, statut, type_repas…) sont stockées
 * sous forme normalisée par Texte::cle().
 */
final class DonneesVaisseau
{
    /**
     * nom, prenom, fonction et avatar_url viennent de colonnes ajoutées par la migration Version20260924120000 :
     * null tant qu'elle n'est pas appliquée.
     *
     * @var array<int, array{id: int, sexe: bool, age: int, poids_kg: float, taille_cm: int, pal: float, allergenes: list<int>, user_id: int|null, activite_id: int|null, nom: string|null, prenom: string|null, fonction: string|null, avatar_url: string|null}>
     */
    public array $equipages = [];

    /** @var array<int, string> libellé par Id_Activity_label */
    public array $activites = [];

    /** @var array<int, array{id: int, libelle: string}> */
    public array $categoriesIngredient = [];

    /** @var array<int, array{id: int, libelle: string}> */
    public array $categoriesRecette = [];

    /** @var array<int, array{id: int, libelle: string, cle: string}> */
    public array $allergenes = [];

    /**
     * Valeurs pour 100 g (clés de Nutriments::CLES) + agronomie.
     *
     * @var array<string, array{id: string, libelle: string, pour100g: array<string, float>, cycle_min: int, cycle_max: int, rendement_g_m2_j: float|null, categorie: string, categorie_id: int|null, unite: string|null}>
     */
    public array $aliments = [];

    /**
     * @var array<int, array{id: int, libelle: string, pour100g: array<string, float>, poids_total_g: float, categorie_id: int|null, categorie: string, ingredients: array<string, float>, temps_preparation: string|null}>
     */
    public array $recettes = [];

    /** @var array<int, array{id: int, libelle: string, cle: string}> */
    public array $typesRepas = [];

    /**
     * @var list<array{id: int, aliment: string, quantite_initiale_g: float, quantite_g: float, date_entree: \DateTimeImmutable, date_peremption: \DateTimeImmutable|null, type_reserve: string, statut: string, recolte_id: int|null}>
     */
    public array $lots = [];

    /** @var list<array{id: int, type: string, quantite_g: float, date: \DateTimeImmutable, lot_id: int, recettes: list<int>}> */
    public array $mouvements = [];

    /**
     * @var array<int, array{id: int, module: string, date_semis: \DateTimeImmutable, date_recolte_prevue: \DateTimeImmutable, date_recolte_reelle: \DateTimeImmutable|null, quantite_prevue_g: float, quantite_reelle_g: float|null, statut: string, taux_perte: float|null, aliment: string}>
     */
    public array $recoltes = [];

    /** @var list<array{id: int, date: \DateTimeImmutable, portions: float, type_repas: string, recette_id: int, occupants: list<array{equipage_id: int, portion_ratio: float|null}>|null}> */
    public array $planning = [];

    /** @var list<array{id: int, date_heure: \DateTimeImmutable, portion_g: float|null, recette_id: int, equipage_id: int, type_repas_id: int, notes: string|null}> */
    public array $journal = [];

    /**
     * Allergènes par aliment (table ALIMENT_ALLERGENE). Null si la table n'existe pas en base :
     * le moteur se replie alors sur une déduction par mot-clé (Parametres::ALLERGENES_PAR_MOT_CLE).
     *
     * @var array<string, list<int>>|null
     */
    public ?array $alimentAllergenes = null;

    /**
     * Créneaux autorisés par recette (table RECETTE_TYPE_REPAS, clés normalisées). Null si la
     * table n'existe pas : repli sur la catégorie de recette (Parametres::CRENEAUX_PAR_CATEGORIE).
     *
     * @var array<int, list<string>>|null
     */
    public ?array $recetteCreneaux = null;

    /** Vrai si PLANNING_REPAS_OCCUPANT porte Id_PLANNING_REPAS (lien planning ↔ occupant, gap #10). */
    public bool $planningOccupantLie = false;

    public function __construct(public \DateTimeImmutable $aujourdhui)
    {
        $this->aujourdhui = $aujourdhui->setTime(0, 0);
    }

    public function typeRepasParCle(string $cle): ?array
    {
        foreach ($this->typesRepas as $type) {
            if ($type['cle'] === $cle) {
                return $type;
            }
        }

        return null;
    }
}
