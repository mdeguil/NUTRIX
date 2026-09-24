<?php

namespace App\Controller;

use App\Entity\ActivityLabel;
use App\Entity\Equipage;
use App\Entity\User;
use App\Http\ErreurApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AuthController extends AbstractController
{
    /**
     * Roles qu'un utilisateur peut s'attribuer lui-meme a l'inscription.
     * ROLE_ADMIN doit etre accorde manuellement (fixtures, console, ou back-office).
     */
    private const ALLOWED_SELF_REGISTER_ROLES = ['ROLE_OCCUPANT', 'ROLE_FERME'];

    private const PASSWORD_MIN_LENGTH = 8;

    /**
     * Inscription. Le corps minimal est { username, password, role } ; nom, prenom et profil sont
     * optionnels. Avec un profil (ROLE_OCCUPANT seulement), la ligne Equipage est creee en meme temps
     * que le compte : l'occupant apparait tout de suite dans les listes et sur le Dashboard.
     *
     *   "profil": { "sexe": "Femme", "age": 34, "poidsKg": 61.5, "tailleCm": 168, "pal": 1.6, "activiteId": 3 }
     */
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'limiter.register')] RateLimiterFactory $registerLimiter,
    ): JsonResponse {
        if (false === $registerLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return ErreurApi::reponse(429, 'Trop d\'inscriptions depuis cette adresse, reessayez plus tard');
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return ErreurApi::reponse(400, 'Corps JSON invalide');
        }

        $username = is_string($data['username'] ?? null) ? trim($data['username']) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $role = $data['role'] ?? 'ROLE_OCCUPANT';

        if ('ROLE_ADMIN' === $role) {
            return ErreurApi::reponse(403, 'Un compte administrateur ne peut pas etre cree par inscription');
        }

        $violations = [];
        if ('' === $username || mb_strlen($username) > 180) {
            $violations[] = ['field' => 'username', 'message' => 'L\'identifiant est obligatoire (180 caracteres au plus).'];
        }
        if (\strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $violations[] = ['field' => 'password', 'message' => sprintf('Le mot de passe doit contenir au moins %d caracteres.', self::PASSWORD_MIN_LENGTH)];
        }
        if (!in_array($role, self::ALLOWED_SELF_REGISTER_ROLES, true)) {
            $violations[] = ['field' => 'role', 'message' => sprintf('Role invalide, attendu : %s.', implode(' ou ', self::ALLOWED_SELF_REGISTER_ROLES))];
        }
        foreach (['nom', 'prenom'] as $champ) {
            if (null !== ($data[$champ] ?? null) && (!is_string($data[$champ]) || mb_strlen($data[$champ]) > 100)) {
                $violations[] = ['field' => $champ, 'message' => 'Texte de 100 caracteres au plus attendu.'];
            }
        }
        $profil = $data['profil'] ?? null;
        if (null !== $profil) {
            if ('ROLE_OCCUPANT' !== $role) {
                $violations[] = ['field' => 'profil', 'message' => 'Seul un occupant a un profil nutritionnel.'];
            } else {
                array_push($violations, ...$this->validerProfil($profil, $em));
            }
        }
        if ($violations) {
            return ErreurApi::reponse(422, $violations[0]['message'], null, $violations);
        }

        if ($em->getRepository(User::class)->findOneBy(['username' => $username])) {
            return ErreurApi::reponse(409, 'Cet identifiant est deja utilise.', 'username_taken');
        }

        $user = new User();
        $user->setUsername($username);
        $user->setRoles([$role]);
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $em->persist($user);

        $equipage = null;
        if (null !== $profil) {
            $equipage = (new Equipage())
                ->setUser($user)
                ->setNom($data['nom'] ?? null)
                ->setPrenom($data['prenom'] ?? null)
                ->setSexe(self::sexe($profil['sexe']))
                ->setAge((int) $profil['age'])
                ->setPoidsKilo((float) $profil['poidsKg'])
                ->setTailleCm((int) $profil['tailleCm'])
                ->setBmi(round((float) $profil['poidsKg'] / (((int) $profil['tailleCm'] / 100) ** 2), 2))
                ->setPal((float) $profil['pal'])
                ->setActivityLabel($em->getRepository(ActivityLabel::class)->find((int) $profil['activiteId']));
            $em->persist($equipage);
        }
        $em->flush();

        return new JsonResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
            'equipageId' => $equipage?->getId(),
        ], 201);
    }

    /** Compte connecte et profil equipage lie (null pour un admin, un compte ferme ou un inscrit sans profil). */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user, EntityManagerInterface $em): JsonResponse
    {
        if (!$user) {
            return ErreurApi::reponse(401, 'Non authentifie');
        }
        $equipage = $em->getRepository(Equipage::class)->findOneBy(['user' => $user]);

        return new JsonResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
            'equipageId' => $equipage?->getId(),
            'nom' => $equipage?->getNom(),
            'prenom' => $equipage?->getPrenom(),
        ]);
    }

    /** @return list<array{field: string, message: string}> */
    private function validerProfil(mixed $profil, EntityManagerInterface $em): array
    {
        if (!is_array($profil)) {
            return [['field' => 'profil', 'message' => 'Objet attendu.']];
        }
        $v = [];
        if (null === self::sexe($profil['sexe'] ?? null)) {
            $v[] = ['field' => 'profil.sexe', 'message' => 'Valeur attendue : "Homme" ou "Femme".'];
        }
        $bornes = ['age' => [1, 120], 'poidsKg' => [20, 300], 'tailleCm' => [50, 250], 'pal' => [1.0, 2.5]];
        foreach ($bornes as $champ => [$min, $max]) {
            $valeur = $profil[$champ] ?? null;
            if (!is_numeric($valeur) || $valeur < $min || $valeur > $max) {
                $v[] = ['field' => 'profil.'.$champ, 'message' => sprintf('Nombre entre %s et %s attendu.', $min, $max)];
            }
        }
        $activite = $profil['activiteId'] ?? null;
        if (!is_int($activite) || null === $em->getRepository(ActivityLabel::class)->find($activite)) {
            $v[] = ['field' => 'profil.activiteId', 'message' => 'Niveau d\'activite inconnu.'];
        }

        return $v;
    }

    /** "Homme" / true → true, "Femme" / false → false (convention d'Equipage.sexe), null sinon. */
    private static function sexe(mixed $valeur): ?bool
    {
        return match (true) {
            is_bool($valeur) => $valeur,
            'homme' === (is_string($valeur) ? mb_strtolower($valeur) : null) => true,
            'femme' === (is_string($valeur) ? mb_strtolower($valeur) : null) => false,
            default => null,
        };
    }
}
