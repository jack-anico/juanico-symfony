<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Repository\ProductRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * The only code allowed to change Order.status.
 * Rejected changes throw \DomainException with a message safe to show the user.
 */
class OrderTransitionService
{
    public const EVENT_STATUS_CHANGED = 'order.status_changed';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EventDispatcherInterface $dispatcher,
        private ProductRepository $products,
    ) {
    }

    /** Puts a new order into its first status. The caller flushes as part of the checkout transaction. */
    public function start(Order $order, User $actor, ?string $note = null): void
    {
        $order->setStatus(Order::STATUS_PENDING);
        $this->recordHistory($order, null, Order::STATUS_PENDING, $actor, $note);
    }

    /**
     * @param \Closure|null $inTransaction Extra work that must commit or fail with this change, such as an audit row
     */
    public function transition(Order $order, string $to, User $actor, ?string $note = null, ?\Closure $inTransaction = null): void
    {
        $this->assertAllowed($order, $to, $actor, $note);
        $from = $order->getStatus();

        $this->entityManager->wrapInTransaction(function () use ($order, $to, $actor, $note, $inTransaction, &$from): void {
            // Re-check under a row lock, so a double click cannot cancel twice and return stock twice.
            $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE);
            $this->assertAllowed($order, $to, $actor, $note);
            $from = $order->getStatus();

            if (Order::STATUS_CANCELLED === $to) {
                $this->restoreStock($order, $actor);
                $order->setCancelReason($note);
            }
            if (Order::STATUS_DELIVERED === $to) {
                $order->setDeliveredAt(new \DateTimeImmutable());
            }

            $order->setStatus($to);
            $this->recordHistory($order, $from, $to, $actor, $note);

            if (null !== $inTransaction) {
                $inTransaction();
            }

            $this->entityManager->flush();
        });

        // Only reached after the commit succeeded.
        $this->dispatcher->dispatch(new GenericEvent($order, ['from' => $from, 'to' => $to]), self::EVENT_STATUS_CHANGED);
    }

    private function assertAllowed(Order $order, string $to, User $actor, ?string $note): void
    {
        if (!$order->canTransitionTo($to)) {
            throw new \DomainException(sprintf('An order cannot go from %s to %s.', $order->getStatus(), $to));
        }

        $isAdmin = in_array('ROLE_ADMIN', $actor->getRoles(), true);
        if (!$isAdmin && (Order::STATUS_CANCELLED !== $to || Order::STATUS_PENDING !== $order->getStatus())) {
            throw new \DomainException('Customers can only cancel orders that are still pending.');
        }

        if (Order::STATUS_CANCELLED === $to && (null === $note || '' === trim($note))) {
            throw new \DomainException('A reason is required to cancel an order.');
        }

        $payment = $order->getPayment();
        if (Order::STATUS_PACKED === $to
            && Payment::METHOD_BANK === $payment?->getMethod()
            && Payment::STATUS_PAID !== $payment->getStatus()) {
            throw new \DomainException('A bank transfer order cannot be packed until its payment is approved.');
        }
    }

    private function restoreStock(Order $order, User $actor): void
    {
        $quantities = [];
        foreach ($order->getItems() as $item) {
            $id = $item->getProduct()->getId();
            $quantities[$id] = ($quantities[$id] ?? 0) + $item->getQuantity();
        }

        foreach ($this->products->findForUpdate(array_keys($quantities)) as $product) {
            $quantity = $quantities[$product->getId()];
            $product->setStock($product->getStock() + $quantity);

            $this->entityManager->persist((new StockMovement())
                ->setProduct($product)
                ->setAdmin(in_array('ROLE_ADMIN', $actor->getRoles(), true) ? $actor : null)
                ->setQuantityChange($quantity)
                ->setReason('order')
                ->setNote('Cancelled order '.$order->getOrderNumber())
                ->setCreatedAt(new \DateTimeImmutable()));
        }
    }

    private function recordHistory(Order $order, ?string $from, string $to, User $actor, ?string $note): void
    {
        $this->entityManager->persist((new OrderStatusHistory())
            ->setOrder($order)
            ->setFromStatus($from)
            ->setToStatus($to)
            ->setNote($note)
            ->setChangedBy($actor)
            ->setCreatedAt(new \DateTimeImmutable()));
    }
}
