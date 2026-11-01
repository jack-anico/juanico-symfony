<?php

namespace App\Controller\Admin;

use App\Entity\Inquiry;
use App\Form\InquiryType;
use App\Repository\InquiryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/inquiry')]
final class InquiryController extends AbstractController
{
    #[Route(name: 'app_admin_inquiry_index', methods: ['GET'])]
    public function index(InquiryRepository $inquiryRepository): Response
    {
        return $this->render('admin/inquiry/index.html.twig', [
            'inquiries' => $inquiryRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_inquiry_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $inquiry = new Inquiry();
        $form = $this->createForm(InquiryType::class, $inquiry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($inquiry);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_inquiry_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/inquiry/new.html.twig', [
            'inquiry' => $inquiry,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_inquiry_show', methods: ['GET'])]
    public function show(Inquiry $inquiry): Response
    {
        return $this->render('admin/inquiry/show.html.twig', [
            'inquiry' => $inquiry,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_inquiry_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Inquiry $inquiry, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(InquiryType::class, $inquiry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_inquiry_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/inquiry/edit.html.twig', [
            'inquiry' => $inquiry,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_inquiry_delete', methods: ['POST'])]
    public function delete(Request $request, Inquiry $inquiry, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$inquiry->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($inquiry);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_inquiry_index', [], Response::HTTP_SEE_OTHER);
    }
}
