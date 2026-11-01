<?php

namespace App\Controller\Admin;

use Knp\Component\Pager\PaginatorInterface;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Form\OrderStatusType;
use App\Repository\AuditLogRepository;
use App\Repository\OrderRepository;
use App\Service\OrderTransitionService;
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
    public function index(Request $request, OrderRepository $orderRepository, PaginatorInterface $paginator): Response
    {
        return $this->render('admin/order/index.html.twig', [
            'orders' => $paginator->paginate($orderRepository->adminListQuery(), max(1, $request->query->getInt('page', 1)), 20),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_order_show', methods: ['GET', 'POST'])]
    public function show(Request $request, Order $order, OrderRepository $orderRepository, OrderTransitionService $transitions, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(OrderStatusType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Saved first because the transition reloads the order from the database.
            $entityManager->flush();

            $status = $form->get('status')->getData();
            if ($status === $order->getStatus()) {
                $this->addFlash('success', 'Order updated.');
            } else {
                try {
                    /** @var User $admin */
                    $admin = $this->getUser();
                    $transitions->transition($order, $status, $admin);
                    $this->addFlash('success', 'Order updated.');
                } catch (\DomainException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                }
            }

            return $this->redirectToRoute('app_admin_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/order/show.html.twig', [
            'order' => $order,
            'form' => $form,
            'history' => $orderRepository->statusHistory($order),
            'can_cancel' => $order->canTransitionTo(Order::STATUS_CANCELLED),
        ]);
    }

    #[Route('/{id}/cancel', name: 'app_admin_order_cancel', methods: ['POST'])]
    public function cancel(Request $request, Order $order, OrderTransitionService $transitions, AuditLogRepository $auditLog): Response
    {
        if (!$this->isCsrfTokenValid('cancel'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');
        } else {
            /** @var User $admin */
            $admin = $this->getUser();
            $reason = trim($request->getPayload()->getString('reason'));
            $paid = Payment::STATUS_PAID === $order->getPayment()?->getStatus();

            try {
                $transitions->transition($order, Order::STATUS_CANCELLED, $admin, $reason, static function () use ($paid, $auditLog, $admin, $order, $reason): void {
                    if ($paid) {
                        $auditLog->record($admin, 'order_cancelled_paid', 'Order', $order->getId(), [
                            'reason' => $reason,
                            'amount' => $order->getPayment()->getAmount(),
                        ]);
                    }
                });
                $this->addFlash('success', $paid ? 'Order cancelled. Its payment now needs a refund.' : 'Order cancelled.');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
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
