<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Form\OrderStatusType;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Orders are created only by checkout and kept for reporting, so there is no create, edit or delete here.
 */
#[Route('/admin/order')]
final class OrderController extends AbstractController
{
    #[Route(name: 'app_admin_order_index', methods: ['GET'])]
    public function index(OrderRepository $orderRepository): Response
    {
        return $this->render('admin/order/index.html.twig', [
            'orders' => $orderRepository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_order_show', methods: ['GET', 'POST'])]
    public function show(Request $request, Order $order, EntityManagerInterface $entityManager): Response
    {
        $previousPayment = $order->getPaymentStatus();
        $form = $this->createForm(OrderStatusType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (Order::STATUS_DELIVERED === $order->getStatus() && null === $order->getDeliveredAt()) {
                $order->setDeliveredAt(new \DateTimeImmutable());
            }
            if (Order::PAYMENT_PAID === $order->getPaymentStatus() && Order::PAYMENT_PAID !== $previousPayment) {
                $order->setPaidAt(new \DateTimeImmutable());
            }
            $entityManager->flush();
            $this->addFlash('success', 'Order updated.');

            return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/order/show.html.twig', [
            'order' => $order,
            'form' => $form,
            'can_cancel' => $order->canTransitionTo(Order::STATUS_CANCELLED),
        ]);
    }

    #[Route('/{id}/cancel', name: 'app_admin_order_cancel', methods: ['POST'])]
    public function cancel(Request $request, Order $order, EntityManagerInterface $entityManager): Response
    {
        $reason = trim($request->getPayload()->getString('reason'));

        if (!$this->isCsrfTokenValid('cancel'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');
        } elseif (!$order->canTransitionTo(Order::STATUS_CANCELLED)) {
            $this->addFlash('error', 'This order can no longer be cancelled.');
        } elseif ('' === $reason) {
            $this->addFlash('error', 'Please give a reason for cancelling.');
        } else {
            $order->setStatus(Order::STATUS_CANCELLED)->setCancelReason($reason);
            $entityManager->flush();
            $this->addFlash('success', 'Order cancelled.');
        }

        return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/invoice', name: 'app_admin_order_invoice', methods: ['GET'])]
    public function invoice(Order $order): Response
    {
        return $this->render('admin/order/invoice.html.twig', [
            'order' => $order,
        ]);
    }
}
