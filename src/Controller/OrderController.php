<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\User;
use App\Form\PaymentProofType;
use App\Repository\OrderRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    public function show(Order $order, OrderRepository $orders): Response
    {
        $this->denyUnlessOwner($order);

        return $this->render('order/show.html.twig', [
            'order' => $order,
            'history' => $orders->statusHistory($order),
            'proof_form' => $this->canRetryProof($order) ? $this->createProofForm($order) : null,
        ]);
    }

    #[Route('/{id}/cancel', name: 'app_order_cancel', methods: ['POST'])]
    public function cancel(Request $request, Order $order, OrderTransitionService $transitions): Response
    {
        $this->denyUnlessOwner($order);
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('cancel'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');
        } else {
            $reason = trim($request->getPayload()->getString('reason'));

            try {
                $transitions->transition($order, Order::STATUS_CANCELLED, $user, '' !== $reason ? $reason : 'Cancelled by customer');
                $this->addFlash('success', 'Your order was cancelled.');
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Lets a customer send a new bank transfer proof after an admin rejected the last one. */
    #[Route('/{id}/payment-proof', name: 'app_order_payment_proof', methods: ['POST'])]
    public function retryProof(Request $request, Order $order, PaymentTransitionService $transitions): Response
    {
        $this->denyUnlessOwner($order);
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createProofForm($order);
        $form->handleRequest($request);

        if (!$this->canRetryProof($order)) {
            $this->addFlash('error', 'This payment is not waiting for a new proof.');
        } elseif (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Please upload a JPEG, PNG or WebP image up to 2 MB.');
        } else {
            /** @var UploadedFile $file */
            $file = $form->get('proof')->getData();
            $payment = $order->getPayment();
            $filename = bin2hex(random_bytes(16)).'.'.$file->guessExtension();
            $directory = $this->getParameter('kernel.project_dir').'/public/uploads/proofs';
            $file->move($directory, $filename);

            try {
                $transitions->transition($payment, Payment::STATUS_PENDING, $user, 'New proof uploaded', static function () use ($payment, $filename): void {
                    $payment->setProofFile($filename);
                });
                $this->addFlash('success', 'Thanks! We will review your new proof.');
            } catch (\DomainException $exception) {
                unlink($directory.'/'.$filename);
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    private function canRetryProof(Order $order): bool
    {
        $payment = $order->getPayment();

        return Payment::METHOD_BANK === $payment?->getMethod()
            && Payment::STATUS_FAILED === $payment->getStatus()
            && Order::STATUS_CANCELLED !== $order->getStatus();
    }

    private function createProofForm(Order $order): FormInterface
    {
        return $this->createForm(PaymentProofType::class, null, [
            'action' => $this->generateUrl('app_order_payment_proof', ['id' => $order->getId()]),
        ]);
    }

    /** Responds 404 rather than 403 so order ids cannot be probed. */
    private function denyUnlessOwner(Order $order): void
    {
        if ($order->getCustomer() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }
    }
}
