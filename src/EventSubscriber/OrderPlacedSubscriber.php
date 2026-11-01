<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public function onOrderPlaced($event): void
    {
        // ...
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'order.placed' => 'onOrderPlaced',
        ];
    }
}
