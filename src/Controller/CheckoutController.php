<?php

namespace App\Controller;

use App\Entity\Address;
use App\Entity\CartItem;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\EventSubscriber\OrderPlacedSubscriber;
use App\Form\CheckoutType;
use App\Repository\AddressRepository;
use App\Repository\CartItemRepository;
use App\Repository\DiscountCodeRepository;
use App\Repository\OrderRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Preview and submit share quote(), so the numbers shown are the numbers charged.
 * Everything is recomputed from the database; nothing the client sends is trusted.
 */
final class CheckoutController extends AbstractController
{
    private const SESSION_KEY = 'checkout_idempotency';

    public function __construct(
        private CartItemRepository $cartItems,
        private AddressRepository $addresses,
        private DiscountCodeRepository $discountCodes,
        private PlatformSettingRepository $settings,
        private ProductRepository $products,
        private OrderRepository $orders,
        private OrderTransitionService $orderTransitions,
        private PaymentTransitionService $paymentTransitions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/checkout/preview', name: 'app_checkout_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $payload = $request->getPayload();
        $user = $this->currentUser();

        $address = $this->addresses->findOneBy(['id' => $payload->getInt('addressId'), 'owner' => $user]);
        $quote = $this->quote(
            $user,
            $address,
            $payload->getString('shippingMethod', Order::SHIPPING_STANDARD),
            $payload->getString('voucherCode'),
        );

        $messages = $quote['errors'];
        if (!in_array($payload->getString('paymentMethod'), [Payment::METHOD_COD, Payment::METHOD_GCASH, Payment::METHOD_CARD, Payment::METHOD_BANK], true)) {
            $messages[] = 'Please choose a payment method.';
        }

        return $this->json([
            'subtotal' => $quote['subtotal'],
            'shippingFee' => $quote['shippingFee'],
            'discount' => $quote['discount'],
            'total' => $quote['total'],
            'messages' => $messages,
        ]);
    }

    #[Route('/checkout', name: 'app_checkout', methods: ['GET', 'POST'])]
    public function index(Request $request, LockFactory $lockFactory, EventDispatcherInterface $dispatcher, ManagerRegistry $registry): Response
    {
        $user = $this->currentUser();
        $items = $this->cartItems->findByOwner($user);

        if ($request->isMethod('GET')) {
            if ([] === $items) {
                $this->addFlash('error', 'Your cart is empty.');

                return $this->redirectToRoute('app_cart');
            }
            if ([] === $this->addresses->findByOwner($user)) {
                $this->addFlash('error', 'Please add a delivery address first.');

                return $this->redirectToRoute('app_address_new');
            }
        }

        $form = $this->createForm(CheckoutType::class, [
            'address' => $this->addresses->findByOwner($user)[0] ?? null,
            'idempotencyKey' => $this->idempotencyKeyFor($request, $items),
        ], ['user' => $user]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('checkout/index.html.twig', ['form' => $form, 'items' => $items]);
        }

        $data = $form->getData();
        $key = strtolower($data['idempotencyKey']);

        $lock = $lockFactory->createLock('checkout_'.$key);
        $lock->acquire(true);

        try {
            // A repeat submit returns the order it already created.
            $existing = $this->orders->findByIdempotencyKey($key);
            if (null !== $existing) {
                if ($existing->getCustomer() !== $user) {
                    throw $this->createNotFoundException();
                }

                return $this->redirectToRoute('app_order_show', ['id' => $existing->getId()], Response::HTTP_SEE_OTHER);
            }

            if (Payment::METHOD_BANK === $data['paymentMethod'] && null === $data['paymentProof']) {
                $form->get('paymentProof')->addError(new FormError('Please upload your proof of payment.'));
            }

            $quote = $this->quote($user, $data['address'], $data['shippingMethod'], $data['discountCode']);
            foreach ($quote['errors'] as $error) {
                $form->addError(new FormError($error));
            }

            if (!$form->isValid()) {
                return $this->render('checkout/index.html.twig', ['form' => $form, 'items' => $items]);
            }

            try {
                $order = $this->placeOrder($user, $data, $key);
            } catch (UniqueConstraintViolationException $exception) {
                // Another server got past the lock; the unique index stopped a second order.
                $registry->resetManager();
                $winner = $registry->getRepository(Order::class)->findOneBy(['idempotencyKey' => $key]) ?? throw $exception;

                return $this->redirectToRoute('app_order_show', ['id' => $winner->getId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('app_cart', [], Response::HTTP_SEE_OTHER);
            }
        } finally {
            $lock->release();
        }

        $request->getSession()->remove(self::SESSION_KEY);

        // Only reached after the commit succeeded.
        $dispatcher->dispatch(new GenericEvent($order), OrderPlacedSubscriber::EVENT);

        $this->addFlash('success', sprintf('Order %s placed. Thank you!', $order->getOrderNumber()));

        return $this->redirectToRoute('app_order_show', ['id' => $order->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{subtotal: string, shippingFee: string, discount: string, total: string, errors: list<string>, voucher: ?DiscountCode}
     */
    private function quote(User $user, ?Address $address, string $shippingMethod, ?string $voucherCode): array
    {
        $errors = [];

        if (1 !== $this->settings->getInt('shop_open')) {
            $errors[] = 'The shop is not accepting orders right now.';
        }

        $items = $this->cartItems->findByOwner($user);
        if ([] === $items) {
            $errors[] = 'Your cart is empty.';
        }

        $subtotal = '0.00';
        foreach ($items as $item) {
            $product = $item->getProduct();
            if (Product::STATUS_ACTIVE !== $product->getStatus()) {
                $errors[] = sprintf('%s is no longer available.', $product->getName());
            } elseif ($product->getStock() < $item->getQuantity()) {
                $errors[] = sprintf('%s only has %d left.', $product->getName(), $product->getStock());
            }
            $subtotal = bcadd($subtotal, bcmul((string) $product->getEffectivePrice(), (string) $item->getQuantity(), 2), 2);
        }

        $minimum = $this->settings->getDecimal('min_order_amount');
        if (bccomp($subtotal, $minimum, 2) < 0) {
            $errors[] = sprintf('The minimum order is PHP %s.', $minimum);
        }

        if (null === $address || $address->getOwner() !== $user) {
            $errors[] = 'Please choose one of your delivery addresses.';
        }

        if (!in_array($shippingMethod, [Order::SHIPPING_STANDARD, Order::SHIPPING_EXPRESS, Order::SHIPPING_PICKUP], true)) {
            $errors[] = 'Please choose a shipping method.';
        }

        $isFree = Order::SHIPPING_PICKUP === $shippingMethod
            || bccomp($subtotal, $this->settings->getDecimal('free_shipping_threshold'), 2) >= 0;
        $shippingFee = $isFree ? '0.00' : $this->settings->getDecimal('shipping_fee');

        $voucher = null;
        $discount = '0.00';
        if (null !== $voucherCode && '' !== trim($voucherCode)) {
            $voucher = $this->discountCodes->findValidByCode(trim($voucherCode), $subtotal);
            if (null === $voucher) {
                $errors[] = 'This discount code is not valid for your order.';
            } else {
                $discount = $this->discountAmount($voucher, $subtotal);
            }
        }

        $total = bcsub(bcadd($subtotal, $shippingFee, 2), $discount, 2);
        if (bccomp($total, '0', 2) < 0) {
            $total = '0.00';
        }

        return [
            'subtotal' => $subtotal,
            'shippingFee' => $shippingFee,
            'discount' => $discount,
            'total' => $total,
            'errors' => $errors,
            'voucher' => $voucher,
        ];
    }

    /**
     * Order, items, payment, histories, stock, voucher use and the cleared cart all commit or all roll back.
     *
     * @param array<string, mixed> $data
     *
     * @throws \DomainException when stock or a voucher ran out after the form was checked
     */
    private function placeOrder(User $user, array $data, string $key): Order
    {
        $proofPath = null;
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Lock first and only then quote, so the check and the deduction see the same stock.
            $items = $this->cartItems->findByOwner($user);
            $this->products->findForUpdate(array_map(
                static fn (CartItem $item): int => $item->getProduct()->getId(),
                $items,
            ) ?: [0]);

            $quote = $this->quote($user, $data['address'], $data['shippingMethod'], $data['discountCode']);
            if ([] !== $quote['errors']) {
                throw new \DomainException($quote['errors'][0]);
            }

            $now = new \DateTimeImmutable();
            $order = (new Order())
                ->setOrderNumber(sprintf('JNC-%s-%s', $now->format('Ymd'), strtoupper(bin2hex(random_bytes(4)))))
                ->setIdempotencyKey($key)
                ->setShippingMethod($data['shippingMethod'])
                ->setSubtotal($quote['subtotal'])
                ->setShippingFee($quote['shippingFee'])
                ->setDiscount($quote['discount'])
                ->setTotal($quote['total'])
                ->setNotes($data['notes'])
                ->setCreatedAt($now)
                ->setCustomer($user)
                ->setShippingAddress($data['address'])
                ->setDiscountCode($quote['voucher']);
            $this->orderTransitions->start($order, $user);
            $this->entityManager->persist($order);

            foreach ($items as $item) {
                $product = $item->getProduct();
                $product->setStock($product->getStock() - $item->getQuantity());

                $line = (new OrderItem())
                    ->setProduct($product)
                    ->setQuantity($item->getQuantity())
                    ->setUnitPrice((string) $product->getEffectivePrice());
                $order->addItem($line);
                $this->entityManager->persist($line);

                $this->entityManager->persist((new StockMovement())
                    ->setProduct($product)
                    ->setQuantityChange(-$item->getQuantity())
                    ->setReason('order')
                    ->setNote('Order '.$order->getOrderNumber())
                    ->setCreatedAt($now));

                $this->entityManager->remove($item);
            }

            $payment = (new Payment())
                ->setMethod($data['paymentMethod'])
                ->setAmount($quote['total'])
                ->setCreatedAt($now)
                ->setOrder($order);
            $order->setPayment($payment);
            $this->paymentTransitions->start($payment, $user);
            $this->entityManager->persist($payment);

            if (Payment::METHOD_BANK === $data['paymentMethod']) {
                $proofPath = $this->storeProof($data['paymentProof'], $payment);
            } elseif (in_array($data['paymentMethod'], [Payment::METHOD_GCASH, Payment::METHOD_CARD], true)) {
                $this->chargeSimulatedGateway($payment, $user);
            }

            if (null !== $quote['voucher'] && !$this->discountCodes->claimUse($quote['voucher'])) {
                throw new \DomainException('Sorry, that discount code has just run out.');
            }

            $this->entityManager->flush();
            $connection->commit();

            return $order;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if (null !== $proofPath && is_file($proofPath)) {
                unlink($proofPath);
            }
            // Drop the half-built order and stock changes so nothing can be flushed by accident.
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            throw $exception;
        }
    }

    /**
     * The only code that knows GCash and card are simulated. No gateway is called and no card data is
     * collected or stored. A real gateway replaces this with create intent, confirm and webhook.
     */
    private function chargeSimulatedGateway(Payment $payment, User $user): void
    {
        $payment->setReference('SIM-'.strtoupper(bin2hex(random_bytes(12))));
        $this->paymentTransitions->apply($payment, Payment::STATUS_PAID, $user, 'Simulated payment');
    }

    private function discountAmount(DiscountCode $voucher, string $subtotal): string
    {
        $amount = 'percent' === $voucher->getType()
            // Adding half a centavo before truncating to two decimals rounds to the nearest centavo.
            ? bcadd(bcdiv(bcmul($subtotal, (string) $voucher->getValue(), 4), '100', 4), '0.005', 2)
            : (string) $voucher->getValue();

        return bccomp($amount, $subtotal, 2) > 0 ? $subtotal : bcadd($amount, '0', 2);
    }

    private function storeProof(UploadedFile $file, Payment $payment): string
    {
        $directory = $this->getParameter('kernel.project_dir').'/public/uploads/proofs';
        $filename = bin2hex(random_bytes(16)).'.'.$file->guessExtension();
        $file->move($directory, $filename);
        $payment->setProofFile($filename);

        return $directory.'/'.$filename;
    }

    /**
     * Keeps the same key until the cart changes, so reloading checkout cannot create a second order.
     *
     * @param CartItem[] $items
     */
    private function idempotencyKeyFor(Request $request, array $items): string
    {
        $cart = implode(',', array_map(
            static fn (CartItem $item): string => $item->getProduct()->getId().':'.$item->getQuantity(),
            $items,
        ));

        $session = $request->getSession();
        $stored = $session->get(self::SESSION_KEY);
        if (is_array($stored) && $stored['cart'] === $cart) {
            return $stored['key'];
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);
        $key = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

        $session->set(self::SESSION_KEY, ['cart' => $cart, 'key' => $key]);

        return $key;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
