<?php

namespace App\EventSubscriber;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Emails the customer when an order is placed or its status changes.
 * A mail failure is logged and never undoes the order change.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class OrderStatusMailSubscriber
{
    /** @var list<Order> */
    private array $pending = [];

    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Order) {
                $this->pending[] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Order && isset($unitOfWork->getEntityChangeSet($entity)['status'])) {
                $this->pending[] = $entity;
            }
        }
    }

    public function postFlush(): void
    {
        $orders = $this->pending;
        $this->pending = [];

        foreach ($orders as $order) {
            $email = (new Email())
                ->from(new Address('noreply@juanico.test', 'Juanico'))
                ->to((string) $order->getCustomer()->getEmail())
                ->subject(sprintf('Order %s: %s', $order->getOrderNumber(), ucfirst((string) $order->getStatus())))
                ->text(sprintf(
                    "Hi %s,\n\nYour order %s is now %s.\nTotal: PHP %s\n\nThank you for shopping at Juanico.",
                    $order->getCustomer()->getFullName(),
                    $order->getOrderNumber(),
                    $order->getStatus(),
                    $order->getTotal(),
                ));

            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $exception) {
                $this->logger->error('Order email failed for {order}: {message}', [
                    'order' => $order->getOrderNumber(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
