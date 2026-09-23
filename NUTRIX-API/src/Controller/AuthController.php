<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AuthController extends AbstractController
{
    /**
     * Roles qu'un utilisateur peut s'attribuer lui-meme a l'inscription.
     * ROLE_ADMIN doit etre accorde manuellement (fixtures, console, ou back-office).
     */
    private const ALLOWED_SELF_REGISTER_ROLES = ['ROLE_OCCUPANT', 'ROLE_FERME'];

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        $role = $data['role'] ?? 'ROLE_OCCUPANT';

        if (!$username || !$password) {
            return new JsonResponse(['error' => 'username et password sont requis'], 400);
        }

        if (!in_array($role, self::ALLOWED_SELF_REGISTER_ROLES, true)) {
            return new JsonResponse([
                'error' => sprintf('role invalide, attendu : %s', implode(' ou ', self::ALLOWED_SELF_REGISTER_ROLES)),
            ], 400);
        }

        if ($em->getRepository(User::class)->findOneBy(['username' => $username])) {
            return new JsonResponse(['error' => 'ce nom d\'utilisateur est deja utilise'], 409);
        }

        $user = new User();
        $user->setUsername($username);
        $user->setRoles([$role]);
        $user->setPassword($passwordHasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return new JsonResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
        ], 201);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return new JsonResponse(['error' => 'non authentifie'], 401);
        }

        return new JsonResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
        ]);
    }
}
