<?php

namespace App\Http;

use App\Service\Moteur\Exception\MoteurException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Forme unique des erreurs des routes /api (Docs/API_REQUETES_FRONT.md §0.4) :
 *
 *   { "error": "validation_failed", "message": "…", "violations": [ { "field": "…", "message": "…" } ] }
 *
 * `error` est un code machine stable, `message` un texte affichable tel quel, `violations` n'apparaît
 * qu'en 422 quand l'erreur porte sur des champs précis.
 */
final class ErreurApi
{
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'access_denied',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        501 => 'not_implemented',
    ];

    /** @param list<array{field: string, message: string}> $violations */
    public static function reponse(int $statut, string $message, ?string $code = null, array $violations = [], array $extra = []): JsonResponse
    {
        $corps = ['error' => $code ?? self::code($statut), 'message' => $message];
        if ($violations) {
            $corps['violations'] = $violations;
        }

        return new JsonResponse($corps + $extra, $statut);
    }

    public static function depuisMoteur(MoteurException $e): JsonResponse
    {
        $details = $e->getDetails();
        $violations = $details['violations'] ?? [];
        unset($details['violations']);

        return self::reponse($e->getStatutHttp(), $e->getMessage(), null, $violations, $details ? ['details' => $details] : []);
    }

    public static function code(int $statut): string
    {
        return self::CODES[$statut] ?? ($statut >= 500 ? 'server_error' : 'error');
    }

    private function __construct()
    {
    }
}
