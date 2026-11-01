<?php

namespace App\EventSubscriber;

use App\Entity\Order;
use App\Service\OrderTransitionService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Emails the customer after an order's status change has committed.
 */
class OrderStatusMailSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function onStatusChanged(GenericEvent $event): void
    {
        /** @var Order $order */
        $order = $event->getSubject();

        try {
            $this->mailer->send((new Email())
                ->from(new Address('noreply@juanico.test', 'Juanico'))
                ->to((string) $order->getCustomer()->getEmail())
                ->subject(sprintf('Order %s: %s', $order->getOrderNumber(), ucfirst((string) $order->getStatus())))
                ->text(sprintf(
                    "Hi %s,\n\nYour order %s is now %s.\nTotal: PHP %s\n\nThank you for shopping at Juanico.",
                    $order->getCustomer()->getFullName(),
                    $order->getOrderNumber(),
                    $order->getStatus(),
                    $order->getTotal(),
                )));
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Order status email failed for {order}: {message}', [
                'order' => $order->getOrderNumber(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderTransitionService::EVENT_STATUS_CHANGED => 'onStatusChanged',
        ];
    }
}
