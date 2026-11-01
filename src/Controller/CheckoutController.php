<?php

namespace App\Controller;

use App\Entity\CartItem;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Form\CheckoutType;
use App\Repository\AddressRepository;
use App\Repository\CartItemRepository;
use App\Repository\DiscountCodeRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'app_checkout', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        CartItemRepository $cartItems,
        AddressRepository $addresses,
        DiscountCodeRepository $discountCodes,
        ProductRepository $products,
        EntityManagerInterface $entityManager,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $items = $cartItems->findByOwner($user);

        if ([] === $items) {
            $this->addFlash('error', 'Your cart is empty.');

            return $this->redirectToRoute('app_cart');
        }

        $defaultAddress = $addresses->findByOwner($user)[0] ?? null;
        if (null === $defaultAddress) {
            $this->addFlash('error', 'Please add a delivery address first.');

            return $this->redirectToRoute('app_address_new');
        }

        $subtotalCents = $this->subtotalCents($items);
        $form = $this->createForm(CheckoutType::class, ['address' => $defaultAddress], ['user' => $user]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $discount = null;
            if (null !== $data['discountCode'] && '' !== trim($data['discountCode'])) {
                $discount = $discountCodes->findValidByCode(trim($data['discountCode']), $this->money($subtotalCents));
                if (null === $discount) {
                    $form->get('discountCode')->addError(new FormError('This code is not valid for your order.'));
                }
            }
            if (Order::PAYMENT_COD !== $data['paymentMethod'] && null === $data['paymentProof']) {
                $form->get('paymentProof')->addError(new FormError('Please upload your proof of payment.'));
            }

            if ($form->isValid()) {
                $order = $this->placeOrder($user, $items, $data, $subtotalCents, $discount, $products, $discountCodes, $entityManager);
                if (null !== $order) {
                    $this->addFlash('success', sprintf('Order %s placed. Thank you!', $order->getOrderNumber()));

                    return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
                }

                return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('checkout/index.html.twig', [
            'form' => $form,
            'items' => $items,
            'subtotal' => $this->money($subtotalCents),
        ]);
    }

    /**
     * Stock, the discount, the order and the emptied cart succeed or fail together.
     *
     * @param CartItem[]           $items
     * @param array<string, mixed> $data
     */
    private function placeOrder(
        User $user,
        array $items,
        array $data,
        int $subtotalCents,
        ?DiscountCode $discount,
        ProductRepository $products,
        DiscountCodeRepository $discountCodes,
        EntityManagerInterface $entityManager,
    ): ?Order {
        $shippingCents = $this->cents(Order::SHIPPING_FEES[$data['shippingMethod']]);
        $discountCents = null === $discount ? 0 : $this->discountCents($discount, $subtotalCents);
        $now = new \DateTimeImmutable();

        $order = (new Order())
            ->setOrderNumber(sprintf('JNC-%s-%s', $now->format('Ymd'), strtoupper(bin2hex(random_bytes(4)))))
            ->setStatus(Order::STATUS_PENDING)
            ->setPaymentMethod($data['paymentMethod'])
            ->setPaymentStatus(Order::PAYMENT_UNPAID)
            ->setShippingMethod($data['shippingMethod'])
            ->setSubtotal($this->money($subtotalCents))
            ->setShippingFee($this->money($shippingCents))
            ->setDiscount($this->money($discountCents))
            ->setTotal($this->money($subtotalCents - $discountCents + $shippingCents))
            ->setNotes($data['notes'])
            ->setCreatedAt($now)
            ->setCustomer($user)
            ->setShippingAddress($data['address'])
            ->setDiscountCode($discount);

        $proofPath = $this->storePaymentProof($data['paymentProof'], $order);

        // Locking rows in a fixed order prevents deadlocks between simultaneous checkouts.
        usort($items, static fn (CartItem $a, CartItem $b): int => $a->getProduct()->getId() <=> $b->getProduct()->getId());

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            foreach ($items as $item) {
                $product = $item->getProduct();
                if (Product::STATUS_ACTIVE !== $product->getStatus() || !$products->decrementStock($product, $item->getQuantity())) {
                    throw new \DomainException(sprintf('Sorry, %s no longer has enough stock.', $product->getName()));
                }

                $line = (new OrderItem())
                    ->setProduct($product)
                    ->setQuantity($item->getQuantity())
                    ->setUnitPrice($product->getEffectivePrice());
                $order->addItem($line);
                $entityManager->persist($line);

                $entityManager->persist((new StockMovement())
                    ->setProduct($product)
                    ->setQuantityChange(-$item->getQuantity())
                    ->setReason('order')
                    ->setNote('Order '.$order->getOrderNumber())
                    ->setCreatedAt($now));

                $entityManager->remove($item);
            }

            if (null !== $discount && !$discountCodes->claimUse($discount)) {
                throw new \DomainException('Sorry, that discount code has just run out.');
            }

            $entityManager->persist($order);
            $entityManager->flush();
            $connection->commit();

            return $order;
        } catch (\DomainException $exception) {
            $connection->rollBack();
            $this->removeFile($proofPath);
            $this->addFlash('error', $exception->getMessage());

            return null;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->removeFile($proofPath);

            throw $exception;
        }
    }

    /** @param CartItem[] $items */
    private function subtotalCents(array $items): int
    {
        $cents = 0;
        foreach ($items as $item) {
            $cents += $this->cents($item->getProduct()->getEffectivePrice()) * $item->getQuantity();
        }

        return $cents;
    }

    private function discountCents(DiscountCode $discount, int $subtotalCents): int
    {
        $value = $this->cents($discount->getValue());
        $amount = 'percent' === $discount->getType() ? (int) round($subtotalCents * $value / 10000) : $value;

        return min($amount, $subtotalCents);
    }

    private function storePaymentProof(?UploadedFile $file, Order $order): ?string
    {
        if (null === $file) {
            return null;
        }

        $directory = $this->getParameter('kernel.project_dir').'/public/uploads/payment_proofs';
        $filename = bin2hex(random_bytes(16)).'.'.$file->guessExtension();
        $file->move($directory, $filename);
        $order->setPaymentProof($filename);

        return $directory.'/'.$filename;
    }

    private function removeFile(?string $path): void
    {
        if (null !== $path && is_file($path)) {
            unlink($path);
        }
    }

    /** Money is calculated in whole centavos so totals never pick up floating point errors. */
    private function cents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
