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
        'PLANNING_REPAS_OCCUPANT', 'JOURNAL_REPAS', 'Activity_label', 'unite_stock',
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
        // Les cles des lignes retournees dependent du pilote (MySQL preserve la casse ecrite dans le
        // CREATE TABLE, PostgreSQL replie tout identifiant non quote en minuscules). On normalise donc
        // systematiquement en minuscules ici, une seule fois, plutot que de gerer la casse partout.
        $normaliser = static fn (array $r): array => array_change_key_case($r, CASE_LOWER);
        $t = static fn (string $nom): array => array_map($normaliser, $tables[$nom] ?? []);
        $optionnelle = static function (string $nom) use ($tables, $normaliser): ?array {
            $rows = $tables[$nom] ?? null;

            return null !== $rows ? array_map($normaliser, $rows) : null;
        };

        foreach ($t('ALLERGENE') as $r) {
            $d->allergenes[(int) $r['id_allergene']] = ['id' => (int) $r['id_allergene'], 'libelle' => $r['libelle'], 'cle' => Texte::cle($r['libelle'])];
        }

        $allergiesParEquipage = [];
        foreach ($t('OCCUPANT_ALLERGIE') as $r) {
            $allergiesParEquipage[(int) $r['id_equipage']][] = (int) $r['id_allergene'];
        }
        foreach ($t('Equipage') as $r) {
            $id = (int) $r['id_equipage'];
            $d->equipages[$id] = [
                'id' => $id,
                'sexe' => (bool) $r['sexe'],
                'age' => (int) $r['age'],
                'poids_kg' => (float) $r['poids_kilo'],
                'taille_cm' => (int) $r['taille_cm'],
                'pal' => (float) $r['pal'],
                'allergenes' => $allergiesParEquipage[$id] ?? [],
                'user_id' => null !== ($r['id_user'] ?? null) ? (int) $r['id_user'] : null,
                'activite_id' => null !== ($r['id_activity_label'] ?? null) ? (int) $r['id_activity_label'] : null,
                // Colonnes de la migration Version20260924120000 : absentes (null) sur une base non migrée.
                'nom' => $r['nom'] ?? null,
                'prenom' => $r['prenom'] ?? null,
                'fonction' => $r['fonction'] ?? null,
                'avatar_url' => $r['avatar_url'] ?? null,
            ];
        }
        foreach ($t('Activity_label') as $r) {
            $d->activites[(int) $r['id_activity_label']] = (string) $r['libelle'];
        }

        $categoriesIngredient = array_column($t('Categorie_ingredient'), 'libelle', 'id_categorie_ingredient');
        foreach ($categoriesIngredient as $id => $libelle) {
            $d->categoriesIngredient[(int) $id] = ['id' => (int) $id, 'libelle' => (string) $libelle];
        }
        $unites = array_column($t('unite_stock'), 'libelle', 'id_unite_stock');
        foreach ($t('Aliment') as $r) {
            $d->aliments[$r['id_aliment']] = [
                'id' => $r['id_aliment'],
                'libelle' => $r['libelle'],
                'pour100g' => [
                    'kcal' => (float) $r['kcal_100g'],
                    'proteines_g' => (float) $r['proteines_100g'],
                    'glucides_g' => (float) $r['glucides_100g'],
                    'lipides_g' => (float) $r['lipides_100g'],
                    'fibres_g' => (float) $r['fibres_100g'],
                ],
                'cycle_min' => (int) $r['cycle_jours_min'],
                'cycle_max' => (int) $r['cycle_jours_max'],
                'rendement_g_m2_j' => null !== $r['rendement_g_m2_j'] ? (float) $r['rendement_g_m2_j'] : null,
                'categorie' => Texte::cle($categoriesIngredient[$r['id_categorie_ingredient']] ?? null),
                'categorie_id' => null !== ($r['id_categorie_ingredient'] ?? null) ? (int) $r['id_categorie_ingredient'] : null,
                'unite' => null !== ($r['id_unite_stock'] ?? null) ? ($unites[$r['id_unite_stock']] ?? null) : null,
            ];
        }

        $ingredients = [];
        foreach ($t('recette_ingredient') as $r) {
            // quantite_g vaut 0 par défaut en base (colonne absente des fixtures).
            $ingredients[(int) $r['id_recette']][$r['id_aliment']] = (float) ($r['quantite_g'] ?? 0);
        }
        $categoriesRecette = array_column($t('categorie_recette'), 'libelle', 'id_categorie_recette');
        foreach ($categoriesRecette as $id => $libelle) {
            $d->categoriesRecette[(int) $id] = ['id' => (int) $id, 'libelle' => (string) $libelle];
        }
        foreach ($t('Recette') as $r) {
            $id = (int) $r['id_recette'];
            $categorie = null !== $r['id_categorie_recette'] ? (int) $r['id_categorie_recette'] : null;
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
                'temps_preparation' => $r['temps_preparation'] ?? null,
            ];
        }

        foreach ($t('type_repas') as $r) {
            $d->typesRepas[(int) $r['id_type_repas']] = ['id' => (int) $r['id_type_repas'], 'libelle' => (string) $r['libelle'], 'cle' => Texte::cle($r['libelle'])];
        }

        foreach ($t('LOT_STOCK') as $r) {
            $d->lots[] = [
                'id' => (int) $r['id_lot_stock'],
                'aliment' => $r['id_aliment'],
                'quantite_initiale_g' => (float) $r['quantite_initiale_g'],
                'quantite_g' => (float) $r['quantite_disponible_g'],
                'date_entree' => new \DateTimeImmutable($r['date_entree']),
                'date_peremption' => $r['date_peremption'] ? new \DateTimeImmutable($r['date_peremption']) : null,
                'type_reserve' => Texte::cle($r['type_reserve']),
                'statut' => Texte::cle($r['statut']),
                'recolte_id' => null !== $r['id_recolte'] ? (int) $r['id_recolte'] : null,
            ];
        }

        $recettesParMouvement = [];
        foreach ($t('Asso_11') as $r) {
            $recettesParMouvement[(int) $r['id_mouvement_stock']][] = (int) $r['id_recette'];
        }
        foreach ($t('MOUVEMENT_STOCK') as $r) {
            $id = (int) $r['id_mouvement_stock'];
            $d->mouvements[] = [
                'id' => $id,
                'type' => Texte::cle($r['type_mouvement']),
                'quantite_g' => (float) $r['quantite_g'],
                'date' => new \DateTimeImmutable($r['date_mouvement']),
                'lot_id' => (int) $r['id_lot_stock'],
                'recettes' => $recettesParMouvement[$id] ?? [],
            ];
        }

        foreach ($t('RECOLTE') as $r) {
            $id = (int) $r['id_recolte'];
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
                'aliment' => $r['id_aliment'],
            ];
        }

        $occupants = [];
        if ($planningOccupantLie) {
            foreach ($t('PLANNING_REPAS_OCCUPANT') as $r) {
                if (null !== ($r['id_planning_repas'] ?? null)) {
                    $occupants[(int) $r['id_planning_repas']][] = [
                        'equipage_id' => (int) $r['id_equipage'],
                        'portion_ratio' => null !== $r['portion_ratio'] ? (float) $r['portion_ratio'] : null,
                    ];
                }
            }
        }
        foreach ($t('PLANNING_REPAS') as $r) {
            $id = (int) $r['id_planning_repas'];
            $d->planning[] = [
                'id' => $id,
                'date' => new \DateTimeImmutable($r['date_']),
                'portions' => (float) $r['portions_prevues'],
                'type_repas' => Texte::cle($r['type_repas']),
                'recette_id' => (int) $r['id_recette'],
                'occupants' => $planningOccupantLie ? ($occupants[$id] ?? []) : null,
            ];
        }
        usort($d->planning, static fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        foreach ($t('JOURNAL_REPAS') as $r) {
            $d->journal[] = [
                'id' => (int) $r['id_journal_repas'],
                'date_heure' => new \DateTimeImmutable($r['date_heure']),
                'portion_g' => null !== $r['portion_g'] ? (float) $r['portion_g'] : null,
                'recette_id' => (int) $r['id_recette'],
                'equipage_id' => (int) $r['id_equipage'],
                'type_repas_id' => (int) $r['id_type_repas'],
                'notes' => $r['notes'] ?? null,
            ];
        }
        usort($d->journal, static fn ($a, $b) => $a['date_heure'] <=> $b['date_heure']);

        $alimentAllergene = $optionnelle('ALIMENT_ALLERGENE');
        if (null !== $alimentAllergene) {
            $d->alimentAllergenes = [];
            foreach ($alimentAllergene as $r) {
                $d->alimentAllergenes[$r['id_aliment']][] = (int) $r['id_allergene'];
            }
        }

        $recetteTypeRepas = $optionnelle('RECETTE_TYPE_REPAS');
        if (null !== $recetteTypeRepas) {
            $d->recetteCreneaux = [];
            foreach ($recetteTypeRepas as $r) {
                $type = $d->typesRepas[(int) $r['id_type_repas']] ?? null;
                if (null !== $type) {
                    $d->recetteCreneaux[(int) $r['id_recette']][] = $type['cle'];
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

    /** Notes d'un repas journalisé ; ignorées tant que la colonne JOURNAL_REPAS.notes n'existe pas. */
    public function enregistrerNotesJournal(int $journalId, ?string $notes): bool
    {
        if (null === $notes || !$this->colonneExiste('JOURNAL_REPAS', 'notes')) {
            return false;
        }
        $this->connexion->update('JOURNAL_REPAS', ['notes' => $notes], ['Id_JOURNAL_REPAS' => $journalId]);

        return true;
    }

    /** Vrai si la base répond. */
    public function baseJoignable(): bool
    {
        try {
            $this->connexion->executeQuery('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
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
