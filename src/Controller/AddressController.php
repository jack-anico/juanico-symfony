<?php

namespace App\Controller;

use App\Entity\Address;
use App\Entity\User;
use App\Form\AddressType;
use App\Repository\AddressRepository;
use App\Repository\OrderRepository;
use App\Security\Voter\AddressVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/account/address')]
final class AddressController extends AbstractController
{
    #[Route(name: 'app_address_index', methods: ['GET'])]
    public function index(AddressRepository $addressRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('address/index.html.twig', [
            'addresses' => $addressRepository->findByOwner($user),
        ]);
    }

    #[Route('/new', name: 'app_address_new', methods: ['GET', 'POST'])]
    public function new(Request $request, AddressRepository $addressRepository, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $address = (new Address())->setOwner($user);
        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($address);
            $entityManager->flush();
            $this->keepSingleDefault($address, $addressRepository);

            return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('address/new.html.twig', [
            'address' => $address,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_address_show', methods: ['GET'])]
    public function show(Address $address): Response
    {
        $this->denyAccessUnlessGranted(AddressVoter::VIEW, $address);

        return $this->render('address/show.html.twig', [
            'address' => $address,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_address_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Address $address,
        AddressRepository $addressRepository,
        OrderRepository $orderRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted(AddressVoter::EDIT, $address);

        // Orders point at this row, so changing it would rewrite where past orders were shipped.
        if ($orderRepository->isAddressUsed($address)) {
            $this->addFlash('error', 'This address is on an existing order, so it cannot be changed. Please add a new one.');

            return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->keepSingleDefault($address, $addressRepository);

            return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('address/edit.html.twig', [
            'address' => $address,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_address_delete', methods: ['POST'])]
    public function delete(Request $request, Address $address, OrderRepository $orderRepository, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(AddressVoter::EDIT, $address);

        if ($this->isCsrfTokenValid('delete'.$address->getId(), $request->getPayload()->getString('_token'))) {
            if ($orderRepository->isAddressUsed($address)) {
                $this->addFlash('error', 'This address is on an existing order, so it cannot be deleted.');
            } else {
                $entityManager->remove($address);
                $entityManager->flush();
            }
        }

        return $this->redirectToRoute('app_address_index', [], Response::HTTP_SEE_OTHER);
    }

    private function keepSingleDefault(Address $address, AddressRepository $addressRepository): void
    {
        if ($address->isDefault()) {
            $addressRepository->clearDefaultExcept($address);
        }
    }
}
