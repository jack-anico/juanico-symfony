<?php

namespace App\Controller\Admin;

use App\Entity\DiscountCode;
use App\Form\DiscountCodeType;
use App\Repository\DiscountCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/discount/code')]
final class DiscountCodeController extends AbstractController
{
    #[Route(name: 'app_admin_discount_code_index', methods: ['GET'])]
    public function index(DiscountCodeRepository $discountCodeRepository): Response
    {
        return $this->render('admin/discount_code/index.html.twig', [
            'discount_codes' => $discountCodeRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_discount_code_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $discountCode = new DiscountCode();
        $form = $this->createForm(DiscountCodeType::class, $discountCode);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($discountCode);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_discount_code_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/discount_code/new.html.twig', [
            'discount_code' => $discountCode,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_discount_code_show', methods: ['GET'])]
    public function show(DiscountCode $discountCode): Response
    {
        return $this->render('admin/discount_code/show.html.twig', [
            'discount_code' => $discountCode,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_discount_code_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, DiscountCode $discountCode, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(DiscountCodeType::class, $discountCode);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_discount_code_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/discount_code/edit.html.twig', [
            'discount_code' => $discountCode,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_discount_code_delete', methods: ['POST'])]
    public function delete(Request $request, DiscountCode $discountCode, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$discountCode->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($discountCode);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_discount_code_index', [], Response::HTTP_SEE_OTHER);
    }
}
