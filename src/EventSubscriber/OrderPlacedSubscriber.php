<?php

namespace App\EventSubscriber;

use App\Entity\Order;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends the order confirmation and the admin notice. Checkout dispatches this only after the commit,
 * and a mail failure is logged because the order is already placed.
 */
class OrderPlacedSubscriber implements EventSubscriberInterface
{
    public const EVENT = 'order.placed';

    public function __construct(
        private MailerInterface $mailer,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    public function onOrderPlaced(GenericEvent $event): void
    {
        /** @var Order $order */
        $order = $event->getSubject();

        $this->send(
            (string) $order->getCustomer()->getEmail(),
            sprintf('Order %s received', $order->getOrderNumber()),
            sprintf(
                "Hi %s,\n\nWe received your order %s.\nTotal: PHP %s\nPayment: %s (%s)\n\nThank you for shopping at Juanico.",
                $order->getCustomer()->getFullName(),
                $order->getOrderNumber(),
                $order->getTotal(),
                $order->getPayment()->getMethod(),
                $order->getPayment()->getStatus(),
            ),
            $order,
        );

        foreach ($this->users->findAdmins() as $admin) {
            $this->send(
                (string) $admin->getEmail(),
                sprintf('New order %s', $order->getOrderNumber()),
                sprintf("New order %s for PHP %s.", $order->getOrderNumber(), $order->getTotal()),
                $order,
            );
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            self::EVENT => 'onOrderPlaced',
        ];
    }

    private function send(string $to, string $subject, string $text, Order $order): void
    {
        try {
            $this->mailer->send((new Email())
                ->from(new Address('noreply@juanico.test', 'Juanico'))
                ->to($to)
                ->subject($subject)
                ->text($text));
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Order placed email failed for {order}: {message}', [
                'order' => $order->getOrderNumber(),
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
