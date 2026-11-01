<?php

namespace App\EventSubscriber;

use App\Entity\Order;
use App\Entity\Product;
use App\Entity\StockMovement;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Returns stock whenever an order becomes cancelled, whichever screen cancelled it.
 * Stock is deducted by checkout, where a shortage has to be able to reject the order.
 */
#[AsDoctrineListener(event: Events::onFlush)]
class OrderStockSubscriber
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $products = $entityManager->getRepository(Product::class);

        foreach ($unitOfWork->getScheduledEntityUpdates() as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            $status = $unitOfWork->getEntityChangeSet($order)['status'] ?? null;
            if (null === $status || Order::STATUS_CANCELLED !== $status[1] || Order::STATUS_CANCELLED === $status[0]) {
                continue;
            }

            foreach ($order->getItems() as $item) {
                $products->incrementStock($item->getProduct(), $item->getQuantity());

                $movement = (new StockMovement())
                    ->setProduct($item->getProduct())
                    ->setQuantityChange($item->getQuantity())
                    ->setReason('order')
                    ->setNote('Cancelled order '.$order->getOrderNumber())
                    ->setCreatedAt(new \DateTimeImmutable());
                $entityManager->persist($movement);
                $unitOfWork->computeChangeSet($entityManager->getClassMetadata(StockMovement::class), $movement);
            }
        }
    }
}
