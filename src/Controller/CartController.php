<?php

namespace App\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\CartItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cart')]
final class CartController extends AbstractController
{
    #[Route('', name: 'app_cart', methods: ['GET'])]
    public function index(CartItemRepository $cartItems): Response
    {
        $items = $cartItems->findByOwner($this->currentUser());

        $subtotal = '0.00';
        foreach ($items as $item) {
            $subtotal = bcadd($subtotal, $item->getLineTotal(), 2);
        }

        return $this->render('cart/index.html.twig', [
            'items' => $items,
            'subtotal' => $subtotal,
        ]);
    }

    #[Route('/add/{id}', name: 'app_cart_add', methods: ['POST'])]
    public function add(Request $request, Product $product, CartItemRepository $cartItems, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('cart', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');

            return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
        }
        if (Product::STATUS_ACTIVE !== $product->getStatus()) {
            throw $this->createNotFoundException();
        }

        $quantity = $request->getPayload()->getInt('quantity', 1);
        $user = $this->currentUser();
        $item = $cartItems->findOneByOwnerAndProduct($user, $product);
        $total = ($item?->getQuantity() ?? 0) + $quantity;

        if ($quantity < 1 || $total > $product->getStock()) {
            $this->addFlash('error', sprintf('Only %d of %s available.', $product->getStock(), $product->getName()));

            return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
        }

        $now = new \DateTimeImmutable();
        if (null === $item) {
            $item = (new CartItem())->setOwner($user)->setProduct($product)->setCreatedAt($now);
            $entityManager->persist($item);
        }
        $item->setQuantity($total)->setUpdatedAt($now);

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A double click added the same product twice; the first request already saved it.
            return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', 'Added to your cart.');

        return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/update', name: 'app_cart_update', methods: ['POST'])]
    public function update(Request $request, CartItem $item, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($item);
        $quantity = $request->getPayload()->getInt('quantity');

        if (!$this->isCsrfTokenValid('cart', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');
        } elseif ($quantity < 1 || $quantity > $item->getProduct()->getStock()) {
            $this->addFlash('error', sprintf('Choose between 1 and %d.', $item->getProduct()->getStock()));
        } else {
            $item->setQuantity($quantity)->setUpdatedAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/remove', name: 'app_cart_remove', methods: ['POST'])]
    public function remove(Request $request, CartItem $item, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($item);

        if ($this->isCsrfTokenValid('cart', $request->getPayload()->getString('_token'))) {
            $entityManager->remove($item);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
    }

    private function denyUnlessOwner(CartItem $item): void
    {
        if ($item->getOwner() !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
