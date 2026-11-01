<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Repository\WishlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/wishlist')]
final class WishlistController extends AbstractController
{
    #[Route('', name: 'app_wishlist', methods: ['GET'])]
    public function index(WishlistItemRepository $wishlist): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('wishlist/index.html.twig', [
            'items' => $wishlist->findByOwner($user),
        ]);
    }

    #[Route('/add/{id}', name: 'app_wishlist_add', methods: ['POST'])]
    public function add(Request $request, Product $product, WishlistItemRepository $wishlist, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->isCsrfTokenValid('wishlist', $request->getPayload()->getString('_token')) && !$wishlist->existsFor($user, $product)) {
            $entityManager->persist((new WishlistItem())->setOwner($user)->setProduct($product)->setCreatedAt(new \DateTimeImmutable()));
            $entityManager->flush();
            $this->addFlash('success', 'Added to your wishlist.');
        }

        return $this->redirectToRoute('app_wishlist', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/remove', name: 'app_wishlist_remove', methods: ['POST'])]
    public function remove(Request $request, WishlistItem $item, EntityManagerInterface $entityManager): Response
    {
        if ($item->getOwner() !== $this->getUser()) {
            throw $this->createNotFoundException();
        }

        if ($this->isCsrfTokenValid('wishlist', $request->getPayload()->getString('_token'))) {
            $entityManager->remove($item);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_wishlist', [], Response::HTTP_SEE_OTHER);
    }
}
