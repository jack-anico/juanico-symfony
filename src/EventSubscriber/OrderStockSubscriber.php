<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class OrderStockSubscriber implements EventSubscriberInterface
{
    public function onRequestEvent(RequestEvent $event): void
    {
        // ...
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onRequestEvent',
        ];
    }
}
