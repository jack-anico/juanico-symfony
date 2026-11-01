<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Keeps deactivated accounts out: it blocks new logins, including remember-me logins,
 * and ends sessions that were already open when the admin deactivated the account.
 */
class DeactivatedUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $user = $event->getPassport()->getUser();

        if ($user instanceof User && !$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account has been deactivated.');
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && !$user->isActive()) {
            $this->security->logout(false);
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the password check, so this never reveals whether a password was right.
            CheckPassportEvent::class => ['onCheckPassport', -10],
            // Just after the firewall, which runs at priority 8 and loads the user.
            KernelEvents::REQUEST => ['onRequest', 7],
        ];
    }
}
