<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\PaymentRepository;
use App\Repository\PaymentStatusHistoryRepository;
use App\Service\PaymentTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Payments are read-only here apart from the audited actions below.
 */
#[Route('/admin/payment')]
final class PaymentController extends AbstractController
{
    private const METHODS = [Payment::METHOD_COD, Payment::METHOD_GCASH, Payment::METHOD_CARD, Payment::METHOD_BANK];

    #[Route(name: 'app_admin_payment_index', methods: ['GET'])]
    public function index(Request $request, PaymentRepository $payments): Response
    {
        $method = $request->query->getString('method');
        $status = $request->query->getString('status');
        $method = in_array($method, self::METHODS, true) ? $method : null;
        $status = array_key_exists($status, Payment::TRANSITIONS) ? $status : null;

        return $this->render('admin/payment/index.html.twig', [
            'payments' => $payments->findByMethodAndStatus($method, $status),
            'bank_queue' => $payments->findPendingBankTransfers(),
            'cod_queue' => $payments->findCollectableCod(),
            'refund_queue' => $payments->findAwaitingRefund(),
            'methods' => self::METHODS,
            'statuses' => array_keys(Payment::TRANSITIONS),
            'current_method' => $method,
            'current_status' => $status,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_payment_show', methods: ['GET'])]
    public function show(Payment $payment, PaymentStatusHistoryRepository $history): Response
    {
        return $this->render('admin/payment/show.html.twig', [
            'payment' => $payment,
            'history' => $history->findForPayment($payment),
        ]);
    }

    #[Route('/{id}/approve', name: 'app_admin_payment_approve', methods: ['POST'])]
    public function approve(Request $request, Payment $payment, PaymentTransitionService $transitions, AuditLogRepository $auditLog): Response
    {
        return $this->act($request, $payment, $transitions, $auditLog, Payment::METHOD_BANK, Payment::STATUS_PAID, 'proof_approved', 'Payment approved.');
    }

    #[Route('/{id}/reject', name: 'app_admin_payment_reject', methods: ['POST'])]
    public function reject(Request $request, Payment $payment, PaymentTransitionService $transitions, AuditLogRepository $auditLog): Response
    {
        return $this->act($request, $payment, $transitions, $auditLog, Payment::METHOD_BANK, Payment::STATUS_FAILED, 'proof_rejected', 'Payment rejected.');
    }

    #[Route('/{id}/collect', name: 'app_admin_payment_collect', methods: ['POST'])]
    public function collect(Request $request, Payment $payment, PaymentTransitionService $transitions, AuditLogRepository $auditLog): Response
    {
        if (Order::STATUS_DELIVERED !== $payment->getOrder()->getStatus()) {
            $this->addFlash('error', 'Cash can only be marked collected after delivery.');

            return $this->redirectToRoute('app_admin_payment_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->act($request, $payment, $transitions, $auditLog, Payment::METHOD_COD, Payment::STATUS_PAID, 'cod_collected', 'Cash marked as collected.');
    }

    #[Route('/{id}/refund', name: 'app_admin_payment_refund', methods: ['POST'])]
    public function refund(Request $request, Payment $payment, PaymentTransitionService $transitions, AuditLogRepository $auditLog): Response
    {
        return $this->act($request, $payment, $transitions, $auditLog, null, Payment::STATUS_REFUNDED, 'payment_refunded', 'Payment refunded.');
    }

    private function act(
        Request $request,
        Payment $payment,
        PaymentTransitionService $transitions,
        AuditLogRepository $auditLog,
        ?string $requiredMethod,
        string $to,
        string $action,
        string $success,
    ): Response {
        if (!$this->isCsrfTokenValid('payment'.$payment->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');
        } elseif (null !== $requiredMethod && $requiredMethod !== $payment->getMethod()) {
            $this->addFlash('error', 'This action does not apply to that payment method.');
        } else {
            /** @var User $admin */
            $admin = $this->getUser();
            $note = trim($request->getPayload()->getString('note'));

            try {
                $transitions->transition($payment, $to, $admin, '' !== $note ? $note : null, static function () use ($auditLog, $admin, $action, $payment, $note): void {
                    $auditLog->record($admin, $action, 'Payment', $payment->getId(), [
                        'order' => $payment->getOrder()->getOrderNumber(),
                        'amount' => $payment->getAmount(),
                        'note' => $note,
                    ]);
                });
                $this->addFlash('success', $success);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_admin_payment_index', [], Response::HTTP_SEE_OTHER);
    }
}
