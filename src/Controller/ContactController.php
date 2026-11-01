<?php

namespace App\Controller;

use App\Entity\Inquiry;
use App\Entity\User;
use App\Form\InquiryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    #[Route('/contact', name: 'app_contact', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $inquiry = new Inquiry();
        $user = $this->getUser();
        if ($user instanceof User) {
            $inquiry->setName((string) $user->getFullName())->setEmail((string) $user->getEmail());
        }

        $form = $this->createForm(InquiryType::class, $inquiry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $inquiry->setStatus(Inquiry::STATUS_NEW)->setCreatedAt(new \DateTimeImmutable());
            $entityManager->persist($inquiry);
            $entityManager->flush();
            $this->addFlash('success', 'Thanks! We will get back to you soon.');

            return $this->redirectToRoute('app_contact', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('contact/index.html.twig', [
            'form' => $form,
        ]);
    }
}
