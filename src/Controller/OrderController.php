<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders')]
final class OrderController extends AbstractController
{
    #[Route('', name: 'app_order_index', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $status = $request->query->getString('status');
        $filter = array_key_exists($status, Order::TRANSITIONS) ? $status : null;

        return $this->render('order/index.html.twig', [
            'orders' => $orders->findForCustomer($user, $filter)->getResult(),
            'statuses' => array_keys(Order::TRANSITIONS),
            'current_status' => $filter,
        ]);
    }

    #[Route('/{id}', name: 'app_order_show', methods: ['GET'])]
    public function show(Order $order): Response
    {
        $this->denyUnlessOwner($order);

        return $this->render('order/show.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/{id}/cancel', name: 'app_order_cancel', methods: ['POST'])]
    public function cancel(Request $request, Order $order, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($order);

        if (!$this->isCsrfTokenValid('cancel'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');
        } elseif (Order::STATUS_PENDING !== $order->getStatus()) {
            $this->addFlash('error', 'This order can no longer be cancelled.');
        } else {
            $reason = trim($request->getPayload()->getString('reason'));
            // The transaction keeps the stock returned by OrderStockSubscriber tied to the status change.
            $entityManager->wrapInTransaction(static function () use ($order, $reason, $entityManager): void {
                $order->setStatus(Order::STATUS_CANCELLED)->setCancelReason('' !== $reason ? $reason : 'Cancelled by customer');
                $entityManager->flush();
            });
            $this->addFlash('success', 'Your order was cancelled.');
        }

        return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Responds 404 rather than 403 so order ids cannot be probed. */
    private function denyUnlessOwner(Order $order): void
    {
        if ($order->getCustomer() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
    }
}
