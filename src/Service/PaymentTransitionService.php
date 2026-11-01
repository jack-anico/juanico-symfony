<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only code allowed to change Payment.status.
 * Rejected changes throw \DomainException with a message safe to show the user.
 */
class PaymentTransitionService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** Puts a new payment into pending. The caller flushes as part of the checkout transaction. */
    public function start(Payment $payment, User $actor): void
    {
        $payment->setStatus(Payment::STATUS_PENDING);
        $this->recordHistory($payment, null, Payment::STATUS_PENDING, $actor, null);
    }

    /** Applies a change inside a transaction the caller already controls, such as checkout. */
    public function apply(Payment $payment, string $to, User $actor, ?string $note = null): void
    {
        $this->assertAllowed($payment, $to, $note);
        $from = $payment->getStatus();

        if (Payment::STATUS_PAID === $to) {
            $payment->setPaidAt(new \DateTimeImmutable());
        }

        $payment->setStatus($to);
        $this->recordHistory($payment, $from, $to, $actor, $note);
    }

    /**
     * @param \Closure|null $inTransaction Extra work that must commit or fail with this change, such as an audit row
     */
    public function transition(Payment $payment, string $to, User $actor, ?string $note = null, ?\Closure $inTransaction = null): void
    {
        $this->assertAllowed($payment, $to, $note);

        $this->entityManager->wrapInTransaction(function () use ($payment, $to, $actor, $note, $inTransaction): void {
            // Re-check under a row lock, so a double click cannot apply the same change twice.
            $this->entityManager->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            $this->apply($payment, $to, $actor, $note);

            if (null !== $inTransaction) {
                $inTransaction();
            }

            $this->entityManager->flush();
        });
    }

    private function assertAllowed(Payment $payment, string $to, ?string $note): void
    {
        if (!in_array($to, Payment::TRANSITIONS[$payment->getStatus()] ?? [], true)) {
            throw new \DomainException(sprintf('A payment cannot go from %s to %s.', $payment->getStatus(), $to));
        }

        if (Payment::STATUS_FAILED === $to && (null === $note || '' === trim($note))) {
            throw new \DomainException('A note is required to reject a payment.');
        }

        if (Payment::STATUS_REFUNDED === $to && Order::STATUS_CANCELLED !== $payment->getOrder()->getStatus()) {
            throw new \DomainException('Only payments on cancelled orders can be refunded.');
        }
    }

    private function recordHistory(Payment $payment, ?string $from, string $to, User $actor, ?string $note): void
    {
        $this->entityManager->persist((new PaymentStatusHistory())
            ->setPayment($payment)
            ->setFromStatus($from)
            ->setToStatus($to)
            ->setNote($note)
            ->setChangedBy($actor)
            ->setCreatedAt(new \DateTimeImmutable()));
    }
}
