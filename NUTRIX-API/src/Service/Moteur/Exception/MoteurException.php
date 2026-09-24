<?php

namespace App\Service\Moteur\Exception;

/**
 * Erreur métier du moteur de calcul. Porte le code HTTP que le contrôleur doit renvoyer
 * (404 ressource inconnue, 422 entrée invalide, 409 rupture de menu, 501 donnée absente du schéma).
 */
class MoteurException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, private readonly int $statutHttp, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function introuvable(string $message): self
    {
        return new self($message, 404);
    }

    public static function invalide(string $message): self
    {
        return new self($message, 422);
    }

    /**
     * Entrée invalide avec le détail par champ, renvoyé tel quel au front (§0.4 de API_REQUETES_FRONT.md).
     *
     * @param list<array{field: string, message: string}> $violations
     */
    public static function violations(array $violations): self
    {
        return new self($violations[0]['message'] ?? 'Entrée invalide', 422, ['violations' => $violations]);
    }

    /** @param array<string, mixed> $details */
    public static function conflit(string $message, array $details = []): self
    {
        return new self($message, 409, $details);
    }

    public static function nonDisponible(string $message): self
    {
        return new self($message, 501);
    }

    public function getStatutHttp(): int
    {
        return $this->statutHttp;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
