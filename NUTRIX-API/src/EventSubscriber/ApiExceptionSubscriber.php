<?php

namespace App\EventSubscriber;

use App\Http\ErreurApi;
use App\Service\Moteur\Exception\MoteurException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Convertit les exceptions des routes /api « maison » (contrôleurs Symfony, hors API Platform) au format
 * d'erreur commun (ErreurApi) plutôt qu'en page HTML : 403 d'un #[IsGranted], 404 de route, 500…
 *
 * Les routes gérées par API Platform gardent leur propre format (elles portent _api_resource_class).
 * La priorité est plus basse que celle du pare-feu (qui transforme un AccessDeniedException en 403)
 * et plus haute que le rendu d'erreur de Symfony.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api') || $request->attributes->has('_api_resource_class')) {
            return;
        }

        $e = $event->getThrowable();
        if ($e instanceof MoteurException) {
            $event->setResponse(ErreurApi::depuisMoteur($e));

            return;
        }
        if ($e instanceof HttpExceptionInterface) {
            $statut = $e->getStatusCode();
            $message = match ($statut) {
                // Le message de #[IsGranted] (« Access Denied. The user doesn't have ROLE_… ») détaille les règles
                // de sécurité : remplacé. Un message explicite du contrôleur est gardé.
                403 => '' === $e->getMessage() || str_starts_with($e->getMessage(), 'Access Denied') ? 'Accès refusé' : $e->getMessage(),
                404 => $request->attributes->has('_route') ? ($e->getMessage() ?: 'Ressource introuvable') : 'Route inconnue',
                default => $e->getMessage() ?: 'Requête refusée',
            };
            $reponse = ErreurApi::reponse($statut, $message);
            $reponse->headers->add($e->getHeaders());
            $event->setResponse($reponse);

            return;
        }

        $this->logger->error('Erreur non gérée sur {route}', ['route' => $request->getPathInfo(), 'exception' => $e]);
        $event->setResponse(ErreurApi::reponse(500, $this->debug ? $e->getMessage() : 'Erreur interne du serveur'));
    }
}
