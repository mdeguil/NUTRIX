<?php

namespace App\Tests\Moteur;

use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Donnees\NutrixDataProvider;

/**
 * Construit les données du moteur à partir du dump `Docs/nutrix (1).sql`, sans base de données :
 * les INSERT du dump sont lus et passés à NutrixDataProvider::construire().
 */
final class DonneesDump
{
    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $tables = null;

    /** Le dossier Docs/ n'est pas versionné : les tests sont ignorés quand le dump est absent (CI). */
    public static function chemin(): string
    {
        return dirname(__DIR__, 4).'/Docs/nutrix (1).sql';
    }

    public static function charger(string $aujourdhui = '2026-09-23'): DonneesVaisseau
    {
        return NutrixDataProvider::construire(self::tables(), new \DateTimeImmutable($aujourdhui));
    }

    /** @return array<string, list<array<string, mixed>>> */
    public static function tables(): array
    {
        if (null !== self::$tables) {
            return self::$tables;
        }
        $sql = file_get_contents(self::chemin());
        preg_match_all('/INSERT INTO `([^`]+)` \(([^)]*)\) VALUES\s*(.*?);\s*$/ms', $sql, $inserts, PREG_SET_ORDER);

        self::$tables = [];
        foreach ($inserts as [, $table, $colonnes, $valeurs]) {
            $noms = array_map(static fn ($c) => trim($c, " `"), explode(',', $colonnes));
            foreach (self::tuples($valeurs) as $tuple) {
                self::$tables[$table][] = array_combine($noms, $tuple);
            }
        }

        return self::$tables;
    }

    /**
     * Découpe « (1, 'a\'b', NULL), (2, ...) » en tuples de valeurs PHP.
     *
     * @return list<list<mixed>>
     */
    private static function tuples(string $valeurs): array
    {
        $tuples = [];
        $tuple = null;
        $n = strlen($valeurs);
        for ($i = 0; $i < $n; ++$i) {
            $c = $valeurs[$i];
            if ('(' === $c && null === $tuple) {
                $tuple = [];
                $jeton = '';
                continue;
            }
            if (null === $tuple) {
                continue;
            }
            if ("'" === $c) {
                $chaine = '';
                for (++$i; $i < $n && "'" !== $valeurs[$i]; ++$i) {
                    if ('\\' === $valeurs[$i]) {
                        ++$i;
                    }
                    $chaine .= $valeurs[$i];
                }
                $jeton = $chaine;
                $estChaine = true;
                continue;
            }
            if (',' === $c || ')' === $c) {
                $tuple[] = ($estChaine ?? false) ? $jeton : self::scalaire(trim($jeton));
                $jeton = '';
                $estChaine = false;
                if (')' === $c) {
                    $tuples[] = $tuple;
                    $tuple = null;
                }
                continue;
            }
            $jeton .= $c;
        }

        return $tuples;
    }

    private static function scalaire(string $v): mixed
    {
        return 'NULL' === $v ? null : $v;
    }
}
