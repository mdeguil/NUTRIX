<?php

namespace App\Controller;

use App\Entity\User;
use App\Http\ErreurApi;
use App\Service\Front\VuesFront;
use App\Service\Moteur\Donnees\DonneesVaisseau;
use App\Service\Moteur\Donnees\NutrixDataProvider;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\MoteurCalcul;
use App\Service\Moteur\Support\Parametres;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Requêtes de l'interface React (Docs/API_REQUETES_FRONT.md) : une action par écran, réponses en
 * camelCase directement utilisables par les composants. Les calculs sont faits par VuesFront, sur
 * les calculateurs du moteur.
 *
 * Priorité haute, comme MoteurController : GET /api/recettes, /api/allergenes et /api/recettes/planifiees
 * doivent passer avant les routes générées par API Platform pour les mêmes chemins.
 *
 * Droits : un ROLE_OCCUPANT ne lit et n'écrit que son propre journal et son propre bilan ; il voit la
 * liste de l'équipage (R6), comme le prévoit le document. ROLE_FERME n'a accès qu'aux pages Stock,
 * Prévisionnel, Agriculture et au catalogue.
 */
#[Route('/api', priority: 10)]
class FrontController extends AbstractController
{
    private const OCCUPANT_OU_ADMIN = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_OCCUPANT")';
    private const FERME_OU_ADMIN = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_FERME")';

    public function __construct(
        private readonly NutrixDataProvider $donnees,
        private readonly VuesFront $vues,
        private readonly MoteurCalcul $moteur,
        private readonly ClockInterface $horloge,
    ) {
    }

    /** R4 — état de l'API, de la base et alertes en cours (Sidebar, TopBar). */
    #[Route('/status', name: 'front_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(): JsonResponse
    {
        $base = $this->donnees->baseJoignable();
        $alertes = [];
        if ($base) {
            try {
                $alertes = $this->vues->alertes($this->donnees->charger());
            } catch (\Throwable) {
                $base = false;
            }
        }
        $niveaux = array_column($alertes, 'niveau');

        return new JsonResponse([
            'api' => 'ok',
            'database' => $base ? 'ok' : 'down',
            'niveau' => match (true) {
                !$base || in_array('critique', $niveaux, true) => 'critique',
                in_array('attention', $niveaux, true) => 'attention',
                default => 'nominal',
            },
            'alertes' => $alertes,
            'horodatage' => $this->horloge->now()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** R5 — consommé du jour et objectif EFSA (Dashboard). */
    #[Route('/me/bilan-journalier', name: 'front_bilan_journalier', methods: ['GET'])]
    #[IsGranted(new Expression(self::OCCUPANT_OU_ADMIN))]
    public function bilanJournalier(Request $request): JsonResponse
    {
        $d = $this->donnees->charger();
        $demande = self::entier($request, 'equipageId');
        if ($this->isGranted('ROLE_ADMIN')) {
            // Sans equipageId, un administrateur voit le total de l'équipage.
            $equipageId = $demande;
        } else {
            $equipageId = $this->equipageConnecte($d);
            if (null === $equipageId) {
                return ErreurApi::reponse(404, 'Profil nutritionnel non renseigné pour ce compte', 'profil_non_renseigne');
            }
            if (null !== $demande && $demande !== $equipageId) {
                throw new AccessDeniedHttpException('Vous ne pouvez consulter que votre propre bilan');
            }
        }

        return new JsonResponse($this->vues->bilanJournalier($d, $equipageId, self::date($request, 'date')));
    }

    /** R6 — liste de l'équipage (Occupants, sélecteur du Journal). */
    #[Route('/occupants', name: 'front_occupants', methods: ['GET'])]
    #[IsGranted(new Expression(self::OCCUPANT_OU_ADMIN))]
    public function occupants(Request $request): JsonResponse
    {
        return new JsonResponse($this->vues->occupants($this->donnees->charger(), self::date($request, 'date')));
    }

    /** R7 — détail d'un occupant avec ses besoins ; un occupant ne peut lire que le sien. */
    #[Route('/occupants/{id}', name: 'front_occupant', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression(self::OCCUPANT_OU_ADMIN))]
    public function occupant(int $id, Request $request): JsonResponse
    {
        $d = $this->donnees->charger();
        if (!$this->isGranted('ROLE_ADMIN') && $this->equipageConnecte($d) !== $id) {
            throw new AccessDeniedHttpException('Vous ne pouvez consulter que votre propre profil');
        }

        return new JsonResponse($this->vues->occupantDetail($d, $id, self::date($request, 'date')));
    }

    /** R8 — types de repas (sélecteur du Journal). */
    #[Route('/types-repas', name: 'front_types_repas', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function typesRepas(): JsonResponse
    {
        return new JsonResponse($this->vues->typesRepas($this->donnees->charger()));
    }

    /** R9 — recettes planifiées sur une période (sélecteur « Menu servi » du Journal). */
    #[Route('/recettes/planifiees', name: 'front_recettes_planifiees', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function recettesPlanifiees(Request $request): JsonResponse
    {
        return new JsonResponse($this->vues->recettesPlanifiees(
            $this->donnees->charger(),
            self::date($request, 'dateDebut'),
            self::date($request, 'dateFin'),
            self::entier($request, 'typeRepasId'),
        ));
    }

    /** R10 — historique du journal, paginé. Un occupant ne voit que ses propres repas. */
    #[Route('/journal-repas', name: 'front_journal_lire', methods: ['GET'])]
    #[IsGranted(new Expression(self::OCCUPANT_OU_ADMIN))]
    public function journal(Request $request): JsonResponse
    {
        $d = $this->donnees->charger();
        $equipageId = self::entier($request, 'equipageId');
        if (!$this->isGranted('ROLE_ADMIN')) {
            $sien = $this->equipageConnecte($d);
            if (null !== $equipageId && $equipageId !== $sien) {
                throw new AccessDeniedHttpException('Vous ne pouvez consulter que votre propre journal');
            }
            if (null === $sien) {
                return new JsonResponse(['items' => [], 'totalItems' => 0, 'page' => 1, 'itemsPerPage' => self::entier($request, 'itemsPerPage') ?? 20]);
            }
            $equipageId = $sien;
        }
        $ordre = $request->query->all('order')['dateHeure'] ?? 'desc';

        return new JsonResponse($this->vues->journal($d, [
            'equipageId' => $equipageId,
            'dateDebut' => self::date($request, 'dateDebut'),
            'dateFin' => self::date($request, 'dateFin'),
            'ordre' => is_string($ordre) ? $ordre : 'desc',
        ], self::entier($request, 'page') ?? 1, self::entier($request, 'itemsPerPage') ?? 20));
    }

    /**
     * R11 — enregistre un repas consommé et sort ses ingrédients du stock (FEFO, réserve courante).
     *
     * Un repas déjà mangé ne peut pas être refusé : si le stock ne couvre pas tout, le repas est
     * enregistré quand même et la réponse porte `alertesStock`.
     */
    #[Route('/journal-repas', name: 'front_journal_enregistrer', methods: ['POST'])]
    #[IsGranted(new Expression(self::OCCUPANT_OU_ADMIN))]
    public function enregistrerRepas(Request $request): JsonResponse
    {
        $corps = json_decode($request->getContent(), true);
        if (!is_array($corps)) {
            return ErreurApi::reponse(400, 'Corps JSON invalide');
        }
        // Les noms snake_case de la première version de la route restent acceptés.
        $champ = static fn (string $camel, string $snake) => $corps[$camel] ?? $corps[$snake] ?? null;
        $entree = [
            'equipageId' => $champ('equipageId', 'equipage_id'),
            'recetteId' => $champ('recetteId', 'recette_id'),
            'typeRepasId' => $champ('typeRepasId', 'type_repas_id'),
            'dateHeure' => $champ('dateHeure', 'date_heure'),
            'portionG' => $champ('portionG', 'portion_g'),
            'notes' => $champ('notes', 'notes'),
        ];

        $violations = [];
        foreach (['equipageId', 'recetteId', 'typeRepasId'] as $cle) {
            if (!is_int($entree[$cle]) && !(is_string($entree[$cle]) && ctype_digit($entree[$cle]))) {
                $violations[] = ['field' => $cle, 'message' => 'Identifiant entier obligatoire.'];
            }
        }
        $dateHeure = null;
        if (!is_string($entree['dateHeure']) || '' === $entree['dateHeure']) {
            $violations[] = ['field' => 'dateHeure', 'message' => 'La date est obligatoire.'];
        } else {
            try {
                $dateHeure = new \DateTimeImmutable($entree['dateHeure']);
                // Tolérance de 5 minutes pour l'écart d'horloge entre le navigateur et le serveur.
                if ($dateHeure > $this->horloge->now()->modify('+5 minutes')) {
                    $violations[] = ['field' => 'dateHeure', 'message' => 'La date ne peut pas être dans le futur.'];
                }
            } catch (\Exception) {
                $violations[] = ['field' => 'dateHeure', 'message' => 'Date invalide, format ISO 8601 attendu.'];
            }
        }
        if (null !== $entree['portionG'] && (!is_numeric($entree['portionG']) || (float) $entree['portionG'] <= 0)) {
            $violations[] = ['field' => 'portionG', 'message' => 'La portion doit être un nombre positif.'];
        }
        if (null !== $entree['notes'] && (!is_string($entree['notes']) || mb_strlen($entree['notes']) > 255)) {
            $violations[] = ['field' => 'notes', 'message' => 'Les notes doivent être un texte de 255 caractères au plus.'];
        }
        if ($violations) {
            throw MoteurException::violations($violations);
        }

        $d = $this->donnees->charger();
        $equipageId = (int) $entree['equipageId'];
        foreach ([
            [$d->equipages, $equipageId, 'Occupant'],
            [$d->recettes, (int) $entree['recetteId'], 'Recette'],
            [$d->typesRepas, (int) $entree['typeRepasId'], 'Type de repas'],
        ] as [$liste, $id, $libelle]) {
            if (!isset($liste[$id])) {
                throw MoteurException::introuvable(sprintf('%s %d introuvable', $libelle, $id));
            }
        }
        if (!$this->isGranted('ROLE_ADMIN') && $this->equipageConnecte($d) !== $equipageId) {
            throw new AccessDeniedHttpException('Vous ne pouvez enregistrer que vos propres repas');
        }

        // La colonne date_heure est sans fuseau : on la stocke dans le fuseau du serveur, celui dans lequel elle est relue.
        $dateLocale = $dateHeure->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $repas = $this->moteur->enregistrerRepas([
            'equipage_id' => $equipageId,
            'recette_id' => (int) $entree['recetteId'],
            'type_repas_id' => (int) $entree['typeRepasId'],
            'date_heure' => $dateLocale->format('Y-m-d H:i:s'),
            'portion_g' => null !== $entree['portionG'] ? (float) $entree['portionG'] : null,
            'sortie_stock' => true,
        ]);
        $notes = null !== $entree['notes'] && '' !== trim($entree['notes']) ? trim($entree['notes']) : null;
        $notesEnregistrees = $this->donnees->enregistrerNotesJournal($repas['id'], $notes);

        $reponse = $this->vues->entreeJournal($d, [
            'id' => $repas['id'],
            'date_heure' => new \DateTimeImmutable($dateLocale->format('Y-m-d H:i:s')),
            'portion_g' => $repas['portion_g'],
            'recette_id' => $repas['recette_id'],
            'equipage_id' => $repas['equipage_id'],
            'type_repas_id' => $repas['type_repas_id'],
            'notes' => $notesEnregistrees ? $notes : null,
        ]);
        $manques = array_values(array_filter($repas['sorties_stock'] ?? [], static fn ($s) => $s['manque_g'] > 0));
        if ($manques) {
            $reponse['alertesStock'] = array_map(static fn ($s) => [
                'alimentId' => $s['aliment_id'],
                'manqueG' => round($s['manque_g'], 1),
                'message' => sprintf('Stock courant insuffisant pour %s : %.0f g non sortis', $d->aliments[$s['aliment_id']]['libelle'] ?? $s['aliment_id'], $s['manque_g']),
            ], $manques);
        }

        return new JsonResponse($reponse, 201);
    }

    /** R12 — inventaire par catégorie d'ingrédient (Stock). */
    #[Route('/stock/categories', name: 'front_stock_categories', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function stockCategories(): JsonResponse
    {
        return new JsonResponse($this->vues->stockCategories($this->donnees->charger()));
    }

    /** R13 — production à prévoir par aliment sur l'horizon (Prévisionnel). */
    #[Route('/previsions/production', name: 'front_previsions_production', methods: ['GET'])]
    #[IsGranted(new Expression(self::FERME_OU_ADMIN))]
    public function previsionsProduction(Request $request): JsonResponse
    {
        return new JsonResponse($this->vues->previsionsProduction(
            $this->donnees->charger(),
            self::entier($request, 'semaines') ?? Parametres::PREVISION_SEMAINES,
            self::entier($request, 'historiqueJours') ?? Parametres::HISTORIQUE_PREVISION_JOURS,
        ));
    }

    /** R14 — aliments les plus consommés sur la période (Prévisionnel). */
    #[Route('/statistiques/aliments-consommes', name: 'front_stats_aliments', methods: ['GET'])]
    #[IsGranted(new Expression(self::FERME_OU_ADMIN))]
    public function alimentsConsommes(Request $request): JsonResponse
    {
        return new JsonResponse($this->vues->alimentsConsommes(
            $this->donnees->charger(),
            $request->query->getString('periode', 'mois'),
            self::date($request, 'dateDebut'),
            self::date($request, 'dateFin'),
            self::entier($request, 'limit') ?? 6,
        ));
    }

    /** R15 — menus les plus servis sur la période (Prévisionnel). */
    #[Route('/statistiques/menus-servis', name: 'front_stats_menus', methods: ['GET'])]
    #[IsGranted(new Expression(self::FERME_OU_ADMIN))]
    public function menusServis(Request $request): JsonResponse
    {
        return new JsonResponse($this->vues->menusServis(
            $this->donnees->charger(),
            $request->query->getString('periode', 'mois'),
            self::date($request, 'dateDebut'),
            self::date($request, 'dateFin'),
            self::entier($request, 'limit') ?? 5,
        ));
    }

    /** R16 — catalogue des recettes (Planificateur). */
    #[Route('/recettes', name: 'front_recettes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function recettes(Request $request): JsonResponse
    {
        $disponible = $request->query->has('disponible')
            ? filter_var($request->query->get('disponible'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        if ($request->query->has('disponible') && null === $disponible) {
            throw MoteurException::invalide('disponible attendu : true ou false');
        }
        // sansAllergenes[]=1&sansAllergenes[]=2 ou sansAllergenes=1,2
        $sans = $request->query->all()['sansAllergenes'] ?? [];
        $sans = is_array($sans) ? $sans : explode(',', (string) $sans);
        foreach ($sans as $id) {
            if (!ctype_digit((string) $id)) {
                throw MoteurException::invalide('sansAllergenes attend des identifiants entiers');
            }
        }
        $portions = $request->query->get('portions');
        if (null !== $portions && !is_numeric($portions)) {
            throw MoteurException::invalide('portions doit être un nombre');
        }

        return new JsonResponse($this->vues->recettes($this->donnees->charger(), [
            'disponible' => $disponible,
            'sansAllergenes' => array_map('intval', $sans),
            'categorieId' => self::entier($request, 'categorieId'),
            'portions' => null !== $portions ? (float) $portions : 1.0,
        ]));
    }

    /** R17 — allergènes (filtre du Planificateur). */
    #[Route('/allergenes', name: 'front_allergenes', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function allergenes(): JsonResponse
    {
        return new JsonResponse($this->vues->allergenes($this->donnees->charger()));
    }

    /** R18 — autonomie par aliment et priorités de plantation (Agriculture). */
    #[Route('/agriculture/besoins-plantation', name: 'front_besoins_plantation', methods: ['GET'])]
    #[IsGranted(new Expression(self::FERME_OU_ADMIN))]
    public function besoinsPlantation(Request $request): JsonResponse
    {
        return new JsonResponse($this->vues->besoinsPlantation(
            $this->donnees->charger(),
            self::entier($request, 'historiqueJours') ?? Parametres::HISTORIQUE_AGRICULTURE_JOURS,
            self::entier($request, 'autonomieCibleJours') ?? Parametres::AUTONOMIE_CIBLE_JOURS,
        ));
    }

    /** Équipage lié au compte connecté (Equipage.Id_User), null s'il n'en a pas. */
    private function equipageConnecte(DonneesVaisseau $d): ?int
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }
        foreach ($d->equipages as $id => $eq) {
            if ($eq['user_id'] === $user->getId()) {
                return $id;
            }
        }

        return null;
    }

    private static function date(Request $request, string $param): ?\DateTimeImmutable
    {
        $valeur = $request->query->get($param);
        if (null === $valeur || '' === $valeur) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $valeur);
        if (false === $date || $date->format('Y-m-d') !== $valeur) {
            throw MoteurException::violations([['field' => $param, 'message' => 'Date au format YYYY-MM-DD attendue.']]);
        }

        return $date;
    }

    private static function entier(Request $request, string $param): ?int
    {
        $valeur = $request->query->get($param);
        if (null === $valeur || '' === $valeur) {
            return null;
        }
        if (false === filter_var($valeur, FILTER_VALIDATE_INT)) {
            throw MoteurException::violations([['field' => $param, 'message' => 'Nombre entier attendu.']]);
        }

        return (int) $valeur;
    }
}
