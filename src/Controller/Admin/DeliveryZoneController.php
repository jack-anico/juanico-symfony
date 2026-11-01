<?php

namespace App\Controller\Admin;

use App\Entity\DeliveryZone;
use App\Form\DeliveryZoneType;
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
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $deliveryZone = new DeliveryZone();
        $form = $this->createForm(DeliveryZoneType::class, $deliveryZone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($deliveryZone);
            $entityManager->flush();

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
    public function edit(Request $request, DeliveryZone $deliveryZone, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(DeliveryZoneType::class, $deliveryZone);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/delivery_zone/edit.html.twig', [
            'delivery_zone' => $deliveryZone,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_delivery_zone_delete', methods: ['POST'])]
    public function delete(Request $request, DeliveryZone $deliveryZone, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$deliveryZone->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($deliveryZone);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_delivery_zone_index', [], Response::HTTP_SEE_OTHER);
    }
}
