<?php

namespace App\Controller;

use App\Entity\Equipage;
use App\Entity\User;
use App\Http\ErreurApi;
use App\Service\Moteur\Exception\MoteurException;
use App\Service\Moteur\MoteurCalcul;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Expose le moteur de calcul (src/Service/Moteur) en HTTP — voir le docblock de MoteurCalcul
 * pour la liste des routes. Priorite haute : ces chemins litteraux (ex. /api/equipages/besoins)
 * ne doivent pas etre intercepts par la route item {id} generee par API Platform pour Equipage.
 */
#[Route('/api', priority: 10)]
class MoteurController extends AbstractController
{
    public function __construct(
        private readonly MoteurCalcul $moteur,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Un ROLE_ADMIN peut agir sur n'importe quel equipier. Sinon, l'utilisateur connecte doit
     * etre le compte lie (Equipage.user) a l'equipage cible — sans quoi 403 (IDOR sinon : n'importe
     * quel compte pourrait lire/ecrire les donnees nutritionnelles de n'importe qui).
     */
    private function assertProprietaireOuAdmin(int $equipageId): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }
        /** @var User|null $user */
        $user = $this->getUser();
        $equipage = null !== $user ? $this->em->getRepository(Equipage::class)->findOneBy(['user' => $user]) : null;
        if (null === $equipage || $equipage->getId() !== $equipageId) {
            throw new AccessDeniedHttpException('Vous ne pouvez acceder qu\'aux donnees de votre propre equipage');
        }
    }

    #[Route('/equipages/{id}/besoins', name: 'moteur_besoin_equipier', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function besoinEquipier(int $id, Request $request): JsonResponse
    {
        $this->assertProprietaireOuAdmin($id);

        return $this->executer(fn () => $this->moteur->besoinEquipier(
            $id,
            $request->query->has('lpi_mg') ? (float) $request->query->get('lpi_mg') : null,
            $request->query->get('statut_menopause'),
        ));
    }

    #[Route('/equipages/besoins', name: 'moteur_besoin_equipe', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function besoinEquipe(Request $request): JsonResponse
    {
        return $this->executer(fn () => $this->moteur->besoinEquipe($request->query->get('date')));
    }

    #[Route('/equipages/besoins/creneau', name: 'moteur_besoin_creneau', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function besoinCreneau(Request $request): JsonResponse
    {
        return $this->executer(function () use ($request) {
            $typeRepas = $request->query->get('type_repas');
            if (null === $typeRepas || '' === $typeRepas) {
                throw MoteurException::invalide('type_repas est requis');
            }

            return $this->moteur->besoinCreneau($request->query->get('date'), $typeRepas);
        });
    }

    #[Route('/equipages/{id}/ecart-nutritionnel', name: 'moteur_ecart_nutritionnel', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function ecartNutritionnel(int $id, Request $request): JsonResponse
    {
        $this->assertProprietaireOuAdmin($id);
        $periode = $request->query->has('periode_jours') ? (int) $request->query->get('periode_jours') : 7;

        return $this->executer(fn () => $this->moteur->ecartNutritionnel($id, $periode));
    }

    #[Route('/recettes/{id}/profil-nutritionnel', name: 'moteur_profil_recette', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function profilRecette(int $id): JsonResponse
    {
        return $this->executer(fn () => $this->moteur->profilRecette($id));
    }

    #[Route('/planning-repas/simuler', name: 'moteur_planning_simuler', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function simulerPlanning(Request $request): JsonResponse
    {
        return $this->executer(fn () => $this->moteur->simulerPlanning($this->corps($request)));
    }

    #[Route('/planning-repas/generer', name: 'moteur_planning_generer', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function genererPlanning(Request $request): JsonResponse
    {
        return $this->executer(fn () => $this->moteur->genererPlanning($this->corps($request)));
    }

    #[Route('/planning-repas', name: 'moteur_planning_lire', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function lirePlanning(Request $request): JsonResponse
    {
        return $this->executer(fn () => $this->moteur->lirePlanning(
            $request->query->get('date_debut'),
            $request->query->get('date_fin'),
            $request->query->has('equipage_id') ? (int) $request->query->get('equipage_id') : null,
        ));
    }

    #[Route('/stock/autonomie', name: 'moteur_stock_autonomie', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function autonomie(): JsonResponse
    {
        return $this->executer(fn () => $this->moteur->autonomie());
    }

    #[Route('/stock/previsions', name: 'moteur_stock_previsions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function previsions(Request $request): JsonResponse
    {
        $semaines = $request->query->has('semaines') ? (int) $request->query->get('semaines') : 8;

        return $this->executer(fn () => $this->moteur->previsions($semaines));
    }

    #[Route('/stock/besoins-agricoles', name: 'moteur_stock_besoins_agricoles', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function besoinsAgricoles(Request $request): JsonResponse
    {
        $semaines = $request->query->has('semaines') ? (int) $request->query->get('semaines') : 8;
        $semaine = $request->query->has('semaine') ? (int) $request->query->get('semaine') : null;

        return $this->executer(fn () => $this->moteur->besoinsAgricoles($semaine, $semaines));
    }

    /** Corps JSON d'une requete POST, tableau vide si absent/invalide. */
    private function corps(Request $request): array
    {
        $donnees = json_decode($request->getContent(), true);

        return is_array($donnees) ? $donnees : [];
    }

    /** Execute un appel au moteur et convertit une MoteurException en reponse HTTP (code porte par l'exception). */
    private function executer(callable $fn, int $statutSucces = 200): JsonResponse
    {
        try {
            return new JsonResponse($fn(), $statutSucces);
        } catch (MoteurException $e) {
            return ErreurApi::depuisMoteur($e);
        }
    }
}
