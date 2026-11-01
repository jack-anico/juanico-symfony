<?php

namespace App\Controller\Admin;

use App\Entity\Review;
use App\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/review')]
final class ReviewController extends AbstractController
{
    #[Route(name: 'app_admin_review_index', methods: ['GET'])]
    public function index(ReviewRepository $reviewRepository): Response
    {
        return $this->render('admin/review/index.html.twig', [
            'pending' => $reviewRepository->findPending(),
            'approved' => $reviewRepository->findApproved(),
        ]);
    }

    #[Route('/{id}/approve', name: 'app_admin_review_approve', methods: ['POST'])]
    public function approve(Request $request, Review $review, EntityManagerInterface $entityManager): Response
    {
        return $this->setApproved($request, $review, $entityManager, true);
    }

    #[Route('/{id}/hide', name: 'app_admin_review_hide', methods: ['POST'])]
    public function hide(Request $request, Review $review, EntityManagerInterface $entityManager): Response
    {
        return $this->setApproved($request, $review, $entityManager, false);
    }

    #[Route('/{id}/delete', name: 'app_admin_review_delete', methods: ['POST'])]
    public function delete(Request $request, Review $review, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('review'.$review->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($review);
            $entityManager->flush();
            $this->addFlash('success', 'Review deleted.');
        }

        return $this->redirectToRoute('app_admin_review_index', [], Response::HTTP_SEE_OTHER);
    }

    private function setApproved(Request $request, Review $review, EntityManagerInterface $entityManager, bool $approved): Response
    {
        if ($this->isCsrfTokenValid('review'.$review->getId(), $request->getPayload()->getString('_token'))) {
            $review->setIsApproved($approved);
            $entityManager->flush();
            $this->addFlash('success', $approved ? 'Review approved.' : 'Review hidden.');
        }

        return $this->redirectToRoute('app_admin_review_index', [], Response::HTTP_SEE_OTHER);
    }
}
