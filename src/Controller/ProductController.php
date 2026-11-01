<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Form\ReviewType;
use App\Repository\OrderItemRepository;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Repository\WishlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    #[Route('/product/{slug}', name: 'app_product_show', methods: ['GET', 'POST'])]
    public function show(
        string $slug,
        Request $request,
        ProductRepository $products,
        ReviewRepository $reviews,
        OrderItemRepository $orderItems,
        WishlistItemRepository $wishlist,
        EntityManagerInterface $entityManager,
    ): Response {
        $product = $products->findActiveBySlug($slug) ?? throw $this->createNotFoundException();

        $user = $this->getUser();
        $canReview = $user instanceof User && $this->canReview($user, $product, $orderItems, $reviews);

        $form = null;
        if ($canReview) {
            $review = new Review();
            $form = $this->createForm(ReviewType::class, $review);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                // Reviews stay hidden until an admin approves them.
                $review->setAuthor($user)->setProduct($product)->setIsApproved(false)->setCreatedAt(new \DateTimeImmutable());
                $entityManager->persist($review);
                $entityManager->flush();
                $this->addFlash('success', 'Thanks! Your review will appear once it is approved.');

                return $this->redirectToRoute('app_product_show', ['slug' => $slug], Response::HTTP_SEE_OTHER);
            }
        } elseif ($request->isMethod('POST')) {
            throw $this->createAccessDeniedException('Only customers who received this product can review it, once.');
        }

        return $this->render('product/index.html.twig', [
            'product' => $product,
            'related' => $products->findRelated($product),
            'reviews' => $reviews->findApprovedForProduct($product),
            'average_rating' => $reviews->averageRating($product),
            'in_wishlist' => $user instanceof User && $wishlist->existsFor($user, $product),
            'review_form' => $form,
        ]);
    }

    private function canReview(User $user, Product $product, OrderItemRepository $orderItems, ReviewRepository $reviews): bool
    {
        return $orderItems->hasReceived($user, $product) && !$reviews->existsFor($user, $product);
    }
}
