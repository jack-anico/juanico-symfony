<?php

namespace App\Controller\Admin;

use App\Entity\DeliveryZone;
use App\Entity\User;
use App\Form\DeliveryZoneType;
use App\Repository\AuditLogRepository;
use App\Repository\DeliveryZoneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/delivery/zone')]
final class DeliveryZoneController extends AbstractController
{
    #[Route(name: 'app_admin_delivery_zone_index', methods: ['GET'])]
    public function index(DeliveryZoneRepository $deliveryZoneRepository): Response
    {
        return $this->render('admin/delivery_zone/index.html.twig', [
            'delivery_zones' => $deliveryZoneRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_delivery_zone_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, AuditLogRepository $auditLog): Response
    {
        $deliveryZone = new DeliveryZone();
        $form = $this->createForm(DeliveryZoneType::class, $deliveryZone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->wrapInTransaction(function () use ($entityManager, $deliveryZone, $auditLog): void {
                $entityManager->persist($deliveryZone);
                $entityManager->flush();
                $auditLog->record($this->admin(), 'zone_created', 'DeliveryZone', $deliveryZone->getId(), ['fee' => $deliveryZone->getShippingFee()]);
                $entityManager->flush();
            });

            return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/delivery_zone/new.html.twig', [
            'delivery_zone' => $deliveryZone,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_delivery_zone_show', methods: ['GET'])]
    public function show(DeliveryZone $deliveryZone): Response
    {
        return $this->render('admin/delivery_zone/show.html.twig', [
            'delivery_zone' => $deliveryZone,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_delivery_zone_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DeliveryZone $deliveryZone, EntityManagerInterface $entityManager, AuditLogRepository $auditLog): Response
    {
        $previousFee = $deliveryZone->getShippingFee();
        $previousActive = $deliveryZone->isActive();
        $form = $this->createForm(DeliveryZoneType::class, $deliveryZone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Both rows are saved by this one flush, which runs in a single transaction.
            $auditLog->record($this->admin(), 'zone_updated', 'DeliveryZone', $deliveryZone->getId(), [
                'fee_from' => $previousFee,
                'fee_to' => $deliveryZone->getShippingFee(),
                'active_from' => $previousActive,
                'active_to' => $deliveryZone->isActive(),
            ]);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/delivery_zone/edit.html.twig', [
            'delivery_zone' => $deliveryZone,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_delivery_zone_delete', methods: ['POST'])]
    public function delete(Request $request, DeliveryZone $deliveryZone, EntityManagerInterface $entityManager, AuditLogRepository $auditLog): Response
    {
        if ($this->isCsrfTokenValid('delete'.$deliveryZone->getId(), $request->getPayload()->getString('_token'))) {
            $auditLog->record($this->admin(), 'zone_deleted', 'DeliveryZone', $deliveryZone->getId(), [
                'name' => $deliveryZone->getName(),
                'fee' => $deliveryZone->getShippingFee(),
            ]);
            $entityManager->remove($deliveryZone);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
    }

    private function admin(): User
    {
        /** @var User $admin */
        $admin = $this->getUser();

        return $admin;
    }
}
