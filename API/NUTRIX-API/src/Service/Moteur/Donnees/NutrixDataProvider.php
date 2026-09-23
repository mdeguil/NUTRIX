<?php

namespace App\Service\Moteur\Donnees;

use App\Service\Moteur\Support\Texte;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Lecture (et quelques écritures) de la base NUTRIX pour le moteur de calcul.
 *
 * Passe par DBAL plutôt que par les entités : plusieurs tables utiles au calcul n'ont pas
 * d'entité (OCCUPANT_ALLERGIE, MOUVEMENT_STOCK, Asso_11, PLANNING_REPAS_OCCUPANT), et le
 * moteur a besoin de tout charger d'un bloc. Les noms de tables et colonnes sont ceux du
 * dump `Docs/nutrix (1).sql`.
 */
class NutrixDataProvider
{
    /** Tables lues à chaque chargement (noms exacts du dump). */
    private const TABLES = [
        'ALLERGENE', 'OCCUPANT_ALLERGIE', 'Equipage', 'Categorie_ingredient', 'Aliment', 'categorie_recette', 'Recette',
        'recette_ingredient', 'type_repas', 'LOT_STOCK', 'MOUVEMENT_STOCK', 'Asso_11', 'RECOLTE', 'PLANNING_REPAS',
        'PLANNING_REPAS_OCCUPANT', 'JOURNAL_REPAS',
    ];

    /** Tables recommandées par API_BESOINS_PLANNING.md §1, absentes du dump : utilisées si elles existent. */
    private const TABLES_OPTIONNELLES = ['ALIMENT_ALLERGENE', 'RECETTE_TYPE_REPAS'];

    /** @var array<string, string>|null nom en minuscules → nom réel */
    private ?array $tables = null;

    public function __construct(
        private readonly Connection $connexion,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function charger(?\DateTimeImmutable $aujourdhui = null): DonneesVaisseau
    {
        $tables = [];
        foreach (self::TABLES as $table) {
            $tables[$table] = $this->connexion->fetchAllAssociative(sprintf('SELECT * FROM %s', $table));
        }
        foreach (self::TABLES_OPTIONNELLES as $table) {
            $reel = $this->table($table);
            $tables[$table] = null !== $reel ? $this->connexion->fetchAllAssociative(sprintf('SELECT * FROM %s', $reel)) : null;
        }

        return self::construire(
            $tables,
            $aujourdhui ?? $this->horloge->now(),
            $this->colonneExiste('PLANNING_REPAS_OCCUPANT', 'Id_PLANNING_REPAS'),
        );
    }

    /**
     * Construit la photographie à partir des lignes brutes de chaque table (liste de tableaux associatifs
     * par table, colonnes nommées comme en base). N'accède pas à la base : utilisable dans les tests.
     *
     * @param array<string, list<array<string, mixed>>|null> $tables
     */
    public static function construire(array $tables, \DateTimeImmutable $aujourdhui, bool $planningOccupantLie = false): DonneesVaisseau
    {
        $d = new DonneesVaisseau($aujourdhui);
        $d->planningOccupantLie = $planningOccupantLie;
        $t = static fn (string $nom): array => $tables[$nom] ?? [];

        foreach ($t('ALLERGENE') as $r) {
            $d->allergenes[(int) $r['Id_ALLERGENE']] = ['id' => (int) $r['Id_ALLERGENE'], 'libelle' => $r['libelle'], 'cle' => Texte::cle($r['libelle'])];
        }

        $allergiesParEquipage = [];
        foreach ($t('OCCUPANT_ALLERGIE') as $r) {
            $allergiesParEquipage[(int) $r['Id_Equipage']][] = (int) $r['Id_ALLERGENE'];
        }
        foreach ($t('Equipage') as $r) {
            $id = (int) $r['Id_Equipage'];
            $d->equipages[$id] = [
                'id' => $id,
                'sexe' => (bool) $r['sexe'],
                'age' => (int) $r['age'],
                'poids_kg' => (float) $r['poids_kilo'],
                'taille_cm' => (int) $r['taille_cm'],
                'pal' => (float) $r['pal'],
                'allergenes' => $allergiesParEquipage[$id] ?? [],
            ];
        }

        $categoriesIngredient = array_column($t('Categorie_ingredient'), 'Libelle', 'Id_Categorie_ingredient');
        foreach ($t('Aliment') as $r) {
            $d->aliments[$r['Id_Aliment']] = [
                'id' => $r['Id_Aliment'],
                'libelle' => $r['Libelle'],
                'pour100g' => [
                    'kcal' => (float) $r['Kcal_100g'],
                    'proteines_g' => (float) $r['Proteines_100g'],
                    'glucides_g' => (float) $r['Glucides_100g'],
                    'lipides_g' => (float) $r['Lipides_100g'],
                    'fibres_g' => (float) $r['Fibres_100g'],
                ],
                'cycle_min' => (int) $r['Cycle_jours_min'],
                'cycle_max' => (int) $r['Cycle_jours_max'],
                'rendement_g_m2_j' => null !== $r['rendement_g_m2_j'] ? (float) $r['rendement_g_m2_j'] : null,
                'categorie' => Texte::cle($categoriesIngredient[$r['Id_Categorie_ingredient']] ?? null),
            ];
        }

        $ingredients = [];
        foreach ($t('recette_ingredient') as $r) {
            // quantite_g vaut 0 par défaut en base (colonne absente des fixtures).
            $ingredients[(int) $r['Id_Recette']][$r['Id_Aliment']] = (float) ($r['quantite_g'] ?? 0);
        }
        $categoriesRecette = array_column($t('categorie_recette'), 'libelle', 'Id_categorie_recette');
        foreach ($t('Recette') as $r) {
            $id = (int) $r['Id_Recette'];
            $categorie = null !== $r['Id_categorie_recette'] ? (int) $r['Id_categorie_recette'] : null;
            $d->recettes[$id] = [
                'id' => $id,
                'libelle' => $r['libelle'],
                'pour100g' => [
                    'kcal' => Texte::nombre($r['kcal_100g']),
                    'proteines_g' => Texte::nombre($r['proteines_g_100g']),
                    'glucides_g' => Texte::nombre($r['glucides_g_100g']),
                    'lipides_g' => Texte::nombre($r['lipides_g_100g']),
                    'fibres_g' => Texte::nombre($r['fibres_g_100g']),
                ],
                'poids_total_g' => (float) $r['poids_total_g'],
                'categorie_id' => $categorie,
                'categorie' => Texte::cle($categoriesRecette[$categorie] ?? null),
                'ingredients' => $ingredients[$id] ?? [],
            ];
        }

        foreach ($t('type_repas') as $r) {
            $d->typesRepas[(int) $r['Id_type_repas']] = ['id' => (int) $r['Id_type_repas'], 'libelle' => (string) $r['libelle'], 'cle' => Texte::cle($r['libelle'])];
        }

        foreach ($t('LOT_STOCK') as $r) {
            $d->lots[] = [
                'id' => (int) $r['Id_LOT_STOCK'],
                'aliment' => $r['Id_Aliment'],
                'quantite_initiale_g' => (float) $r['quantite_initiale_g'],
                'quantite_g' => (float) $r['quantite_disponible_g'],
                'date_entree' => new \DateTimeImmutable($r['date_entree']),
                'date_peremption' => $r['date_peremption'] ? new \DateTimeImmutable($r['date_peremption']) : null,
                'type_reserve' => Texte::cle($r['type_reserve']),
                'statut' => Texte::cle($r['statut']),
                'recolte_id' => null !== $r['Id_RECOLTE'] ? (int) $r['Id_RECOLTE'] : null,
            ];
        }

        $recettesParMouvement = [];
        foreach ($t('Asso_11') as $r) {
            $recettesParMouvement[(int) $r['Id_MOUVEMENT_STOCK']][] = (int) $r['Id_Recette'];
        }
        foreach ($t('MOUVEMENT_STOCK') as $r) {
            $id = (int) $r['Id_MOUVEMENT_STOCK'];
            $d->mouvements[] = [
                'id' => $id,
                'type' => Texte::cle($r['type_mouvement']),
                'quantite_g' => (float) $r['quantite_g'],
                'date' => new \DateTimeImmutable($r['date_mouvement']),
                'lot_id' => (int) $r['Id_LOT_STOCK'],
                'recettes' => $recettesParMouvement[$id] ?? [],
            ];
        }

        foreach ($t('RECOLTE') as $r) {
            $id = (int) $r['Id_RECOLTE'];
            $d->recoltes[$id] = [
                'id' => $id,
                'module' => $r['module_culture'],
                'date_semis' => new \DateTimeImmutable($r['date_semis']),
                'date_recolte_prevue' => new \DateTimeImmutable($r['date_recolte_prevue']),
                'date_recolte_reelle' => $r['date_recolte_reelle'] ? new \DateTimeImmutable($r['date_recolte_reelle']) : null,
                'quantite_prevue_g' => (float) $r['quantite_prevue_g'],
                'quantite_reelle_g' => null !== $r['quantite_reelle_g'] ? (float) $r['quantite_reelle_g'] : null,
                'statut' => Texte::cle($r['statut']),
                // taux_perte_pct est stocké en pourcentage (6.00 = 6 %).
                'taux_perte' => null !== $r['taux_perte_pct'] ? (float) $r['taux_perte_pct'] / 100 : null,
                'aliment' => $r['Id_Aliment'],
            ];
        }

        $occupants = [];
        if ($planningOccupantLie) {
            foreach ($t('PLANNING_REPAS_OCCUPANT') as $r) {
                if (null !== ($r['Id_PLANNING_REPAS'] ?? null)) {
                    $occupants[(int) $r['Id_PLANNING_REPAS']][] = [
                        'equipage_id' => (int) $r['Id_Equipage'],
                        'portion_ratio' => null !== $r['portion_ratio'] ? (float) $r['portion_ratio'] : null,
                    ];
                }
            }
        }
        foreach ($t('PLANNING_REPAS') as $r) {
            $id = (int) $r['Id_PLANNING_REPAS'];
            $d->planning[] = [
                'id' => $id,
                'date' => new \DateTimeImmutable($r['date_']),
                'portions' => (float) $r['portions_prevues'],
                'type_repas' => Texte::cle($r['type_repas']),
                'recette_id' => (int) $r['Id_Recette'],
                'occupants' => $planningOccupantLie ? ($occupants[$id] ?? []) : null,
            ];
        }
        usort($d->planning, static fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        foreach ($t('JOURNAL_REPAS') as $r) {
            $d->journal[] = [
                'id' => (int) $r['Id_JOURNAL_REPAS'],
                'date_heure' => new \DateTimeImmutable($r['date_heure']),
                'portion_g' => null !== $r['portion_g'] ? (float) $r['portion_g'] : null,
                'recette_id' => (int) $r['Id_Recette'],
                'equipage_id' => (int) $r['Id_Equipage'],
                'type_repas_id' => (int) $r['Id_type_repas'],
            ];
        }
        usort($d->journal, static fn ($a, $b) => $a['date_heure'] <=> $b['date_heure']);

        if (null !== ($tables['ALIMENT_ALLERGENE'] ?? null)) {
            $d->alimentAllergenes = [];
            foreach ($tables['ALIMENT_ALLERGENE'] as $r) {
                $d->alimentAllergenes[$r['Id_Aliment']][] = (int) $r['Id_ALLERGENE'];
            }
        }

        if (null !== ($tables['RECETTE_TYPE_REPAS'] ?? null)) {
            $d->recetteCreneaux = [];
            foreach ($tables['RECETTE_TYPE_REPAS'] as $r) {
                $type = $d->typesRepas[(int) $r['Id_type_repas']] ?? null;
                if (null !== $type) {
                    $d->recetteCreneaux[(int) $r['Id_Recette']][] = $type['cle'];
                }
            }
        }

        return $d;
    }

    /**
     * Enregistre des repas planifiés (POST /api/planning-repas/generer).
     * Les parts par occupant ne sont écrites que si PLANNING_REPAS_OCCUPANT porte Id_PLANNING_REPAS.
     *
     * @param list<array{date: string, type_repas: string, recette_id: int, portions: float, occupants: list<array{equipage_id: int, portion_ratio: float}>}> $repas
     *
     * @return list<int> identifiants créés
     */
    public function enregistrerPlanning(array $repas, bool $avecOccupants): array
    {
        $ids = [];
        $this->connexion->transactional(function (Connection $c) use ($repas, $avecOccupants, &$ids) {
            foreach ($repas as $r) {
                $c->insert('PLANNING_REPAS', [
                    'date_' => $r['date'],
                    'portions_prevues' => $r['portions'],
                    'type_repas' => $r['type_repas'],
                    'Id_Recette' => $r['recette_id'],
                ]);
                $id = (int) $c->lastInsertId();
                $ids[] = $id;
                if ($avecOccupants) {
                    foreach ($r['occupants'] as $o) {
                        $c->insert('PLANNING_REPAS_OCCUPANT', [
                            'Id_PLANNING_REPAS' => $id,
                            'Id_Equipage' => $o['equipage_id'],
                            'portion_ratio' => round($o['portion_ratio'], 2),
                        ]);
                    }
                }
            }
        });

        return $ids;
    }

    /** Enregistre un repas consommé (POST /api/journal-repas). */
    public function enregistrerJournal(int $equipageId, int $recetteId, int $typeRepasId, \DateTimeImmutable $dateHeure, ?float $portionG): int
    {
        $this->connexion->insert('JOURNAL_REPAS', [
            'date_heure' => $dateHeure->format('Y-m-d H:i:s'),
            'portion_g' => $portionG,
            'Id_Recette' => $recetteId,
            'Id_Equipage' => $equipageId,
            'Id_type_repas' => $typeRepasId,
        ]);

        return (int) $this->connexion->lastInsertId();
    }

    /**
     * Applique des sorties de stock calculées en FEFO (StockCalculator::planifierSortie).
     *
     * @param list<array{lot_id: int, quantite_g: float}> $sorties
     */
    public function enregistrerSorties(array $sorties, ?int $recetteId, \DateTimeImmutable $date): void
    {
        $this->connexion->transactional(function (Connection $c) use ($sorties, $recetteId, $date) {
            foreach ($sorties as $s) {
                $c->insert('MOUVEMENT_STOCK', [
                    'type_mouvement' => 'Sortie',
                    'quantite_g' => $s['quantite_g'],
                    'date_mouvement' => $date->format('Y-m-d H:i:s'),
                    'Id_LOT_STOCK' => $s['lot_id'],
                ]);
                $mouvementId = (int) $c->lastInsertId();
                $c->executeStatement(
                    'UPDATE LOT_STOCK SET quantite_disponible_g = GREATEST(0, quantite_disponible_g - ?) WHERE Id_LOT_STOCK = ?',
                    [$s['quantite_g'], $s['lot_id']]
                );
                if (null !== $recetteId) {
                    $c->insert('Asso_11', ['Id_Recette' => $recetteId, 'Id_MOUVEMENT_STOCK' => $mouvementId]);
                }
            }
        });
    }

    /** Nom réel d'une table optionnelle (insensible à la casse), null si elle n'existe pas. */
    private function table(string $nom): ?string
    {
        if (null === $this->tables) {
            $this->tables = [];
            foreach ($this->connexion->createSchemaManager()->listTableNames() as $t) {
                $this->tables[strtolower($t)] = $t;
            }
        }

        return $this->tables[strtolower($nom)] ?? null;
    }

    private function colonneExiste(string $table, string $colonne): bool
    {
        $reel = $this->table($table);
        if (null === $reel) {
            return false;
        }
        foreach ($this->connexion->createSchemaManager()->listTableColumns($reel) as $c) {
            if (0 === strcasecmp($c->getName(), $colonne)) {
                return true;
            }
        }

        return false;
    }
}
