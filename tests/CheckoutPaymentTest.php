<?php

namespace App\Tests;

use App\Entity\Address;
use App\Entity\AuditLog;
use App\Entity\Brand;
use App\Entity\CartItem;
use App\Entity\Category;
use App\Entity\DiscountCode;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use App\Entity\PlatformSetting;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

class CheckoutPaymentTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $customer;
    private User $admin;
    private Address $address;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();

        foreach (['min_order_amount' => '500', 'shipping_fee' => '150', 'free_shipping_threshold' => '5000', 'pending_order_escalation_hours' => '24', 'shop_open' => '1'] as $key => $value) {
            $this->em->persist((new PlatformSetting())->setSettingKey($key)->setSettingValue($value));
        }

        $this->customer = $this->user('customer@example.test');
        $this->admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $this->address = $this->address($this->customer, 'Metro Manila');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->purge();
        parent::tearDown();
    }

    // ---- Preview and totals

    public function testPreviewMatchesSubmitAndChangesNothing(): void
    {
        $saw = $this->product('Saw', price: '500.00', salePrice: '400.50', stock: 10);
        $nail = $this->product('Nails', price: '19.99', stock: 5);
        $this->cart($saw, 2);
        $this->cart($nail, 3);
        $code = $this->voucher('SAVE10', 'percent', '10.00');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $preview = $this->preview(['voucherCode' => 'SAVE10']);
        // 2 x 400.50 + 3 x 19.99 = 860.97; 10% is 86.097, which rounds to 86.10; shipping 150
        $this->assertSame(['subtotal' => '860.97', 'shippingFee' => '150.00', 'discount' => '86.10', 'total' => '924.87', 'messages' => []], $preview);

        $this->em->clear();
        $this->assertSame(10, $this->em->find(Product::class, $saw->getId())->getStock());
        $this->assertSame(2, $this->em->getRepository(CartItem::class)->count([]));
        $this->assertSame(0, $this->em->find(DiscountCode::class, $code->getId())->getTimesUsed());
        $this->assertSame(0, $this->em->getRepository(Order::class)->count([]));

        $order = $this->checkout(Payment::METHOD_COD, ['checkout[discountCode]' => 'SAVE10', 'checkout[notes]' => 'Leave at the gate']);

        $this->assertSame($preview['subtotal'], $order->getSubtotal());
        $this->assertSame($preview['shippingFee'], $order->getShippingFee());
        $this->assertSame($preview['discount'], $order->getDiscount());
        $this->assertSame($preview['total'], $order->getTotal());
        $this->assertSame($preview['total'], $order->getPayment()->getAmount());
        $this->assertSame('Leave at the gate', $order->getNotes());
        $this->assertMatchesRegularExpression('/^JNC-\d{8}-[0-9A-F]{8}$/', $order->getOrderNumber());

        $prices = [];
        foreach ($order->getItems() as $line) {
            $prices[$line->getProduct()->getName()] = $line->getUnitPrice();
        }
        $this->assertSame(['Saw' => '400.50', 'Nails' => '19.99'], $prices, 'Lines snapshot the price paid');

        $this->assertSame(8, $this->em->find(Product::class, $saw->getId())->getStock());
        $this->assertSame(2, $this->em->find(Product::class, $nail->getId())->getStock());
        $this->assertSame(0, $this->em->getRepository(CartItem::class)->count([]));
        $this->assertSame(1, $this->em->find(DiscountCode::class, $code->getId())->getTimesUsed());
        $this->assertSame(2, $this->em->getRepository(StockMovement::class)->count(['reason' => 'order']));

        $history = static::getContainer()->get(OrderRepository::class)->statusHistory($order);
        $this->assertCount(1, $history);
        $this->assertNull($history[0]->getFromStatus());
        $this->assertSame(1, $this->em->getRepository(PaymentStatusHistory::class)->count([]));
    }

    public function testTamperedClientPriceIsIgnored(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form(['checkout[shippingMethod]' => 'standard', 'checkout[paymentMethod]' => 'cod']);
        $values = $form->getPhpValues();
        $values['checkout']['price'] = '0.01';
        $values['checkout']['total'] = '0.01';
        $values['checkout']['subtotal'] = '0.01';
        $values['total'] = '0.01';
        $this->client->request('POST', '/checkout', $values);
        $this->assertResponseRedirects();

        $order = $this->onlyOrder();
        $this->assertSame('600.00', $order->getSubtotal());
        $this->assertSame('750.00', $order->getTotal());
    }

    public function testPickupShipsFree(): void
    {
        $this->cart($this->product('Hammer', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->assertSame('150.00', $this->preview()['shippingFee']);
        $this->assertSame('0.00', $this->preview(['shippingMethod' => 'pickup'])['shippingFee']);
    }

    public function testFreeShippingThresholdComesFromSettings(): void
    {
        $this->cart($this->product('Saw', price: '5000.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->assertSame('0.00', $this->preview()['shippingFee']);

        $this->setting('free_shipping_threshold', '6000');
        $this->assertSame('150.00', $this->preview()['shippingFee']);
    }

    public function testFixedVoucherNeverMakesTotalNegative(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->voucher('BIG', 'fixed', '9000.00');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $preview = $this->preview(['voucherCode' => 'BIG', 'shippingMethod' => 'pickup']);

        $this->assertSame('600.00', $preview['discount']);
        $this->assertSame('0.00', $preview['total']);
    }

    // ---- Rejections

    public function testAnyAddressIsAccepted(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->address->setProvince('Palawan')->setCity('Puerto Princesa');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->assertSame([], $this->preview()['messages']);
        $order = $this->checkout(Payment::METHOD_COD);

        $this->assertSame('150.00', $order->getShippingFee());
    }

    public function testShippingFeeComesFromSettings(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->setting('shipping_fee', '99.50');
        $this->client->loginUser($this->customer);

        $preview = $this->preview();

        $this->assertSame('99.50', $preview['shippingFee']);
        $this->assertSame('699.50', $preview['total']);
    }

    public function testBelowMinimumOrderIsRejected(): void
    {
        $this->cart($this->product('Nails', price: '499.99'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_COD);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('body', 'minimum order');
        $this->assertNoOrders();
    }

    public function testOutOfStockIsRejected(): void
    {
        $product = $this->product('Saw', price: '600.00', stock: 2);
        $this->cart($product, 3);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_COD);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();
        $this->em->clear();
        $this->assertSame(2, $this->em->find(Product::class, $product->getId())->getStock());
    }

    public function testInactiveProductIsRejected(): void
    {
        $this->cart($this->product('Saw', price: '600.00', status: Product::STATUS_DRAFT), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_COD);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();
    }

    public function testClosedShopIsRejected(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->setting('shop_open', '0');
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_COD);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();
    }

    public function testInvalidVoucherIsRejected(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_COD, ['checkout[discountCode]' => 'NOPE']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();
    }

    public function testAnotherUsersAddressIsRejected(): void
    {
        $other = $this->user('other@example.test');
        $foreign = $this->address($other, 'Metro Manila');
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->assertNotSame([], $this->preview(['addressId' => $foreign->getId()])['messages']);

        $crawler = $this->client->request('GET', '/checkout');
        $values = $crawler->selectButton('Place order')->form()->getPhpValues();
        $values['checkout']['address'] = (string) $foreign->getId();
        $this->client->request('POST', '/checkout', $values);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();
    }

    // ---- Idempotency, atomicity and concurrency

    public function testDoubleSubmitWithSameKeyCreatesOneOrder(): void
    {
        $product = $this->product('Saw', price: '600.00', stock: 5);
        $this->cart($product, 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form(['checkout[shippingMethod]' => 'standard', 'checkout[paymentMethod]' => 'cod']);

        $this->client->submit($form);
        $first = $this->client->getResponse()->headers->get('Location');
        $this->client->submit($form);
        $this->assertResponseRedirects($first);

        $this->em->clear();
        $this->assertSame(1, $this->em->getRepository(Order::class)->count([]));
        $this->assertSame(4, $this->em->find(Product::class, $product->getId())->getStock());
    }

    public function testCheckoutKeyIsStableUntilCartChanges(): void
    {
        $product = $this->product('Saw', price: '600.00', stock: 5);
        $item = $this->cart($product, 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $key = fn (): string => $this->client->request('GET', '/checkout')->filter('[name="checkout[idempotencyKey]"]')->attr('value');
        $first = $key();

        $this->assertSame($first, $key());

        $this->em->clear();
        $this->em->find(CartItem::class, $item->getId())->setQuantity(2);
        $this->em->flush();
        $this->assertNotSame($first, $key());
    }

    public function testAnotherCustomersKeyCannotExposeTheirOrder(): void
    {
        $other = $this->user('other@example.test');
        $order = $this->order($other, $this->product('Saw'));
        $order->setIdempotencyKey('11111111-1111-4111-8111-111111111111');
        $this->cart($this->product('Hammer', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_COD, ['checkout[idempotencyKey]' => '11111111-1111-4111-8111-111111111111']);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testFailureDuringCommitRollsBackEverythingAndSendsNoEmail(): void
    {
        $product = $this->product('Saw', price: '600.00', stock: 5);
        $this->cart($product, 2);
        $code = $this->voucher('SAVE10', 'percent', '10.00');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form([
            'checkout[shippingMethod]' => 'standard',
            'checkout[paymentMethod]' => 'gcash',
            'checkout[discountCode]' => 'SAVE10',
        ]);

        $failure = new class {
            public function onFlush(): void
            {
                throw new \DomainException('Simulated failure while saving.');
            }
        };
        $events = $this->em->getEventManager();
        $events->addEventListener(Events::onFlush, $failure);
        $this->client->submit($form);
        $events->removeEventListener(Events::onFlush, $failure);

        $this->assertEmailCount(0);
        $this->assertResponseRedirects('/cart');

        $this->em->clear();
        $this->assertNoOrders();
        $this->assertSame(0, $this->em->getRepository(Payment::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(OrderStatusHistory::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(StockMovement::class)->count([]));
        $this->assertSame(5, $this->em->find(Product::class, $product->getId())->getStock());
        $this->assertSame(0, $this->em->find(DiscountCode::class, $code->getId())->getTimesUsed());
        $this->assertSame(1, $this->em->getRepository(CartItem::class)->count([]));
    }

    public function testTwoConcurrentBuyersCannotOversellTheLastUnit(): void
    {
        $product = $this->product('Last Saw', price: '600.00', stock: 1);
        $other = $this->user('other@example.test');
        $this->address($other, 'Metro Manila');
        $this->cart($product, 1);
        $this->cart($product, 1, $other);
        $this->em->flush();

        $directory = sys_get_temp_dir().'/race-'.bin2hex(random_bytes(6));
        mkdir($directory);

        $processes = [];
        foreach ([$this->customer->getId(), $other->getId()] as $index => $userId) {
            $process = new Process([PHP_BINARY, '-r', self::RACE_SCRIPT, (string) $userId, $directory, (string) $index], static::getContainer()->getParameter('kernel.project_dir'), ['APP_ENV' => 'test'], null, 180);
            $process->start();
            $processes[] = $process;
        }

        // Release both buyers together, after each has opened checkout in its own PHP process.
        $deadline = time() + 150;
        while (!(is_file($directory.'/ready0') && is_file($directory.'/ready1')) && time() < $deadline) {
            usleep(20000);
            foreach ($processes as $process) {
                if (!$process->isRunning() && !$process->isSuccessful()) {
                    $this->fail($process->getOutput().$process->getErrorOutput());
                }
            }
        }
        touch($directory.'/go');

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);

        $this->em->clear();
        $this->assertSame(1, $this->em->getRepository(Order::class)->count([]), 'Results: '.implode(' | ', $results));
        $this->assertSame(0, $this->em->find(Product::class, $product->getId())->getStock());

        sort($results);
        $this->assertStringContainsString('/cart', $results[0], 'The losing buyer goes back to the cart');
        $this->assertStringContainsString('/orders/', $results[1], 'The winning buyer reaches the order');
    }

    private const RACE_SCRIPT = <<<'PHP'
        require 'vendor/autoload.php';
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
        [, $userId, $directory, $index] = $argv;
        $kernel = new App\Kernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();
        $client = $container->get('test.client');
        $client->disableReboot();
        $user = $container->get('doctrine')->getManager()->find(App\Entity\User::class, (int) $userId);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form(['checkout[shippingMethod]' => 'standard', 'checkout[paymentMethod]' => 'cod']);
        touch($directory.'/ready'.$index);
        while (!is_file($directory.'/go')) { usleep(500); }
        $client->submit($form);
        echo $client->getResponse()->getStatusCode().' '.$client->getResponse()->headers->get('Location');
        PHP;

    // ---- Payment methods

    /** @return iterable<array{string, string, bool}> */
    public static function paymentOutcomes(): iterable
    {
        yield 'cod' => [Payment::METHOD_COD, Payment::STATUS_PENDING, false];
        yield 'gcash' => [Payment::METHOD_GCASH, Payment::STATUS_PAID, true];
        yield 'card' => [Payment::METHOD_CARD, Payment::STATUS_PAID, true];
    }

    #[DataProvider('paymentOutcomes')]
    public function testPaymentStatusAfterCheckout(string $method, string $status, bool $simulated): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $payment = $this->checkout($method)->getPayment();

        $this->assertSame($status, $payment->getStatus());
        $this->assertSame($simulated, null !== $payment->getPaidAt());
        $this->assertSame($simulated, str_starts_with((string) $payment->getReference(), 'SIM-'));
        $this->assertSame($simulated ? 2 : 1, $this->em->getRepository(PaymentStatusHistory::class)->count([]));
    }

    public function testBankTransferRequiresProofAndStaysPending(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->submitCheckout(Payment::METHOD_BANK);
        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();

        $payment = $this->placeBankOrder();

        $this->assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $payment->getProofFile());
        $this->removeProof($payment);
    }

    public function testNonImageProofIsRejected(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, '<?php echo "x";');
        $this->submitCheckout(Payment::METHOD_BANK, ['checkout[paymentProof]' => new UploadedFile($path, 'proof.png', 'image/png', null, true)]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNoOrders();
    }

    public function testAdminApprovesBankProofAndOrderCanThenBePacked(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);
        $payment = $this->placeBankOrder();
        $order = $payment->getOrder();

        $this->client->loginUser($this->freshAdmin());
        $this->adminAction($payment, 'approve');
        $this->em->clear();
        $saved = $this->em->find(Payment::class, $payment->getId());
        $this->assertSame(Payment::STATUS_PAID, $saved->getStatus());
        $this->assertNotNull($saved->getPaidAt());
        $this->assertAudited('proof_approved');

        $saved = $this->em->find(Payment::class, $payment->getId());
        $services = static::getContainer()->get(OrderTransitionService::class);
        $admin = $this->freshAdmin();
        $services->transition($saved->getOrder(), Order::STATUS_CONFIRMED, $admin);
        $services->transition($saved->getOrder(), Order::STATUS_PACKED, $admin);
        $this->assertSame(Order::STATUS_PACKED, $saved->getOrder()->getStatus());
        $this->removeProof($saved);
    }

    public function testBankOrderCannotBePackedBeforePayment(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), Order::STATUS_CONFIRMED, Payment::METHOD_BANK);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        static::getContainer()->get(OrderTransitionService::class)->transition($order, Order::STATUS_PACKED, $this->admin);
    }

    public function testRejectRequiresNoteThenCustomerCanRetry(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);
        $payment = $this->placeBankOrder();
        $firstProof = $payment->getProofFile();
        $orderId = $payment->getOrder()->getId();

        $this->client->loginUser($this->freshAdmin());
        $this->adminAction($payment, 'reject');
        $this->em->clear();
        $this->assertSame(Payment::STATUS_PENDING, $this->em->find(Payment::class, $payment->getId())->getStatus(), 'A note is required');

        $this->adminAction($payment, 'reject', 'Amount does not match');
        $this->em->clear();
        $this->assertSame(Payment::STATUS_FAILED, $this->em->find(Payment::class, $payment->getId())->getStatus());
        $this->assertAudited('proof_rejected');

        $this->client->loginUser($this->em->find(User::class, $this->customer->getId()));
        $this->client->request('GET', '/orders/'.$orderId);
        $this->client->submitForm('Upload new proof', ['payment_proof[proof]' => $this->pngUpload()]);

        $this->em->clear();
        $saved = $this->em->find(Payment::class, $payment->getId());
        $this->assertSame(Payment::STATUS_PENDING, $saved->getStatus());
        $this->assertNotSame($firstProof, $saved->getProofFile());

        $this->removeProof($saved);
        @unlink(static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/proofs/'.$firstProof);
    }

    public function testCodCollectionOnlyAfterDelivery(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), Order::STATUS_SHIPPED);
        $this->em->flush();
        $payments = static::getContainer()->get(PaymentRepository::class);
        $this->assertSame([], $payments->findCollectableCod());
        $this->client->loginUser($this->admin);

        $this->adminAction($order->getPayment(), 'collect');
        $this->em->clear();
        $this->assertSame(Payment::STATUS_PENDING, $this->em->find(Payment::class, $order->getPayment()->getId())->getStatus());

        $saved = $this->em->find(Order::class, $order->getId());
        static::getContainer()->get(OrderTransitionService::class)->transition($saved, Order::STATUS_DELIVERED, $this->freshAdmin());
        $this->assertNotNull($saved->getDeliveredAt());
        $this->assertCount(1, $payments->findCollectableCod());

        $this->adminAction($saved->getPayment(), 'collect');
        $this->em->clear();
        $paid = $this->em->find(Payment::class, $saved->getPayment()->getId());
        $this->assertSame(Payment::STATUS_PAID, $paid->getStatus());
        $this->assertNotNull($paid->getPaidAt());
        $this->assertSame([], $payments->findCollectableCod());
        $this->assertAudited('cod_collected');
    }

    public function testRefundOnlyOnCancelledOrders(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), Order::STATUS_PENDING, Payment::METHOD_GCASH, Payment::STATUS_PAID);
        $this->em->flush();
        $this->client->loginUser($this->admin);

        $this->adminAction($order->getPayment(), 'refund');
        $this->em->clear();
        $this->assertSame(Payment::STATUS_PAID, $this->em->find(Payment::class, $order->getPayment()->getId())->getStatus());

        $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submitForm('Cancel order', ['reason' => 'Out of stock at supplier']);
        $this->assertAudited('order_cancelled_paid');
        $this->em->clear();
        $this->assertCount(1, static::getContainer()->get(PaymentRepository::class)->findAwaitingRefund());

        $this->adminAction($order->getPayment(), 'refund');
        $this->em->clear();
        $this->assertSame(Payment::STATUS_REFUNDED, $this->em->find(Payment::class, $order->getPayment()->getId())->getStatus());
        $this->assertAudited('payment_refunded');
    }

    public function testAdminMoneyActionsRejectMissingCsrfToken(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), Order::STATUS_DELIVERED);
        $this->em->flush();
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/admin/payment/'.$order->getPayment()->getId().'/collect');

        $this->em->clear();
        $this->assertSame(Payment::STATUS_PENDING, $this->em->find(Payment::class, $order->getPayment()->getId())->getStatus());
        $this->assertSame(0, $this->em->getRepository(AuditLog::class)->count([]));
    }

    public function testCustomersCannotReachPaymentAdmin(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), Order::STATUS_DELIVERED);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        foreach (['/admin/payment', '/admin/audit/log', '/admin/platform/setting'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseStatusCodeSame(403, $path);
        }
        $this->client->request('POST', '/admin/payment/'.$order->getPayment()->getId().'/collect');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testPaymentListFiltersAndIsReadOnly(): void
    {
        $this->order($this->customer, $this->product('Saw'), Order::STATUS_PENDING, Payment::METHOD_GCASH, Payment::STATUS_PAID);
        $this->order($this->customer, $this->product('Hammer'));
        $this->em->flush();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/payment?method=gcash&status=paid');
        $this->assertCount(1, $crawler->filter('#all-payments tbody tr'));
        $this->assertSelectorTextContains('#all-payments', 'GCASH');

        $this->client->request('GET', '/admin/payment/new');
        $this->assertResponseStatusCodeSame(404);
    }

    // ---- State management

    public function testStockRestoredOnCancelAndCustomerRules(): void
    {
        $product = $this->product('Saw', stock: 4);
        $order = $this->order($this->customer, $product, Order::STATUS_PENDING, quantity: 3);
        $confirmed = $this->order($this->customer, $product, Order::STATUS_CONFIRMED);
        $this->em->flush();
        $service = static::getContainer()->get(OrderTransitionService::class);

        try {
            $service->transition($order, Order::STATUS_CANCELLED, $this->customer, '  ');
            $this->fail('A reason is required');
        } catch (\DomainException) {
        }

        try {
            $service->transition($confirmed, Order::STATUS_CANCELLED, $this->customer, 'Too late');
            $this->fail('Customers can only cancel pending orders');
        } catch (\DomainException) {
        }

        $service->transition($order, Order::STATUS_CANCELLED, $this->customer, 'Changed my mind');
        $this->assertSame(7, $product->getStock());
        $this->assertSame('Changed my mind', $order->getCancelReason());

        try {
            $service->transition($order, Order::STATUS_CANCELLED, $this->admin, 'Again');
            $this->fail('A cancelled order is final');
        } catch (\DomainException) {
        }
        $this->em->refresh($product);
        $this->assertSame(7, $product->getStock(), 'Stock is not returned twice');

        $service->transition($confirmed, Order::STATUS_CANCELLED, $this->admin, 'Supplier problem');
        $this->assertSame(Order::STATUS_CANCELLED, $confirmed->getStatus());
    }

    /** @return iterable<array{string, string}> */
    public static function invalidOrderTransitions(): iterable
    {
        yield [Order::STATUS_PENDING, Order::STATUS_DELIVERED];
        yield [Order::STATUS_PENDING, Order::STATUS_PACKED];
        yield [Order::STATUS_PACKED, Order::STATUS_CANCELLED];
        yield [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED];
        yield [Order::STATUS_DELIVERED, Order::STATUS_PENDING];
        yield [Order::STATUS_CANCELLED, Order::STATUS_CONFIRMED];
    }

    #[DataProvider('invalidOrderTransitions')]
    public function testInvalidOrderTransitionIsRejected(string $from, string $to): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), $from);
        $this->em->flush();

        try {
            static::getContainer()->get(OrderTransitionService::class)->transition($order, $to, $this->admin, 'Reason');
            $this->fail('Should have been rejected');
        } catch (\DomainException) {
        }

        $this->assertSame($from, $order->getStatus());
        $this->assertSame(0, $this->em->getRepository(OrderStatusHistory::class)->count([]));
    }

    /** @return iterable<array{string, string}> */
    public static function invalidPaymentTransitions(): iterable
    {
        yield [Payment::STATUS_PENDING, Payment::STATUS_REFUNDED];
        yield [Payment::STATUS_PAID, Payment::STATUS_FAILED];
        yield [Payment::STATUS_FAILED, Payment::STATUS_PAID];
        yield [Payment::STATUS_REFUNDED, Payment::STATUS_PAID];
    }

    #[DataProvider('invalidPaymentTransitions')]
    public function testInvalidPaymentTransitionIsRejected(string $from, string $to): void
    {
        $order = $this->order($this->customer, $this->product('Saw'), Order::STATUS_CANCELLED, Payment::METHOD_GCASH, $from);
        $this->em->flush();

        try {
            static::getContainer()->get(PaymentTransitionService::class)->transition($order->getPayment(), $to, $this->admin, 'Note');
            $this->fail('Should have been rejected');
        } catch (\DomainException) {
        }

        $this->assertSame($from, $order->getPayment()->getStatus());
    }

    public function testHistoryKeepsOrderWhenRowsShareATimestamp(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'));
        $when = new \DateTimeImmutable('2026-01-01 10:00:00');
        foreach ([['pending', 'confirmed'], ['confirmed', 'packed'], ['packed', 'shipped']] as [$from, $to]) {
            $this->em->persist((new OrderStatusHistory())->setOrder($order)->setFromStatus($from)->setToStatus($to)->setCreatedAt($when));
            $this->em->flush();
        }

        $rows = static::getContainer()->get(OrderRepository::class)->statusHistory($order);

        $this->assertSame(['confirmed', 'packed', 'shipped'], array_map(static fn (OrderStatusHistory $row) => $row->getToStatus(), $rows));
    }

    /**
     * Fails if code outside the two services writes a status, including through a query or a mapped form field.
     * Inquiry and Product have their own unrelated status fields.
     */
    public function testOnlyTransitionServicesChangeOrderAndPaymentStatus(): void
    {
        $root = static::getContainer()->getParameter('kernel.project_dir').'/src';
        $violations = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' !== $file->getExtension() || str_contains($file->getPathname(), '/Migrations/')) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, 'Service/OrderTransitionService.php') || str_ends_with($path, 'Service/PaymentTransitionService.php')) {
                continue;
            }

            foreach (file($path) as $number => $line) {
                $writesStatus = str_contains($line, '->setStatus(') && !preg_match('/\$(inquiry|this)->setStatus\(|\$this->status|(Product|Inquiry)::STATUS_/', $line);
                $writesWithQuery = preg_match('/\bSET\s+\w+\.status\b|\bUPDATE\s+`?(order|payment)`?\b/i', $line);
                if ($writesStatus || $writesWithQuery) {
                    $violations[] = $path.':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $violations);

        foreach (['OrderStatusType', 'CheckoutType'] as $type) {
            $source = file_get_contents($root.'/Form/'.$type.'.php');
            if (str_contains($source, "add('status'")) {
                $this->assertMatchesRegularExpression("/add\('status'[^;]*'mapped' => false/s", $source, $type.' must not map status');
            }
        }

        $reflection = new \ReflectionMethod(Order::class, 'setStatus');
        $this->assertTrue($reflection->isPublic(), 'Guard test expects the generated setter');
    }

    // ---- Money and reporting

    public function testRevenueCountsOnlyDeliveredAndPaid(): void
    {
        $product = $this->product('Saw');
        $this->order($this->customer, $product, Order::STATUS_DELIVERED, Payment::METHOD_COD, Payment::STATUS_PAID, total: '1000.00');
        $this->order($this->customer, $product, Order::STATUS_DELIVERED, Payment::METHOD_COD, Payment::STATUS_PENDING, total: '2000.00');
        $this->order($this->customer, $product, Order::STATUS_SHIPPED, Payment::METHOD_GCASH, Payment::STATUS_PAID, total: '4000.00');
        $this->order($this->customer, $product, Order::STATUS_CANCELLED, Payment::METHOD_GCASH, Payment::STATUS_REFUNDED, total: '8000.00');
        $this->em->flush();

        $from = new \DateTimeImmutable('-1 day');
        $to = new \DateTimeImmutable('+1 day');
        $orders = static::getContainer()->get(OrderRepository::class);

        $this->assertSame('1000.00', $orders->totalRevenueBetween($from, $to));
        $this->assertSame('1000.00', $orders->averageOrderValue($from, $to));
        $this->assertEquals(1000, array_sum(array_column($orders->salesByDay($from, $to), 'revenue')));
    }

    public function testDashboardFlagsOrdersPastTheEscalationSetting(): void
    {
        $order = $this->order($this->customer, $this->product('Saw'));
        $order->setCreatedAt(new \DateTimeImmutable('-30 hours'));
        $this->em->flush();
        $number = $order->getOrderNumber();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin');
        $this->assertSelectorTextContains('body', 'Pending over 24 hours: '.$number);

        $this->setting('pending_order_escalation_hours', '48');
        $this->client->request('GET', '/admin');
        $this->assertStringNotContainsString('Pending over 48 hours: ', strip_tags($this->client->getResponse()->getContent()));
    }

    // ---- Audited settings and fees

    public function testChangingSettingWritesAuditAndRejectsNonNumbers(): void
    {
        $setting = $this->em->getRepository(PlatformSetting::class)->findOneBy(['settingKey' => 'min_order_amount']);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/platform/setting/'.$setting->getId().'/edit');
        $this->client->submitForm('Update', ['platform_setting[settingValue]' => 'cheap']);
        $this->assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/admin/platform/setting/'.$setting->getId().'/edit');
        $this->client->submitForm('Update', ['platform_setting[settingValue]' => '750']);
        $this->assertResponseRedirects('/admin/platform/setting');

        $log = $this->assertAudited('setting_changed');
        $this->assertEquals(['key' => 'min_order_amount', 'from' => '500', 'to' => '750'], $log->getDetail());
    }

    public function testSettingsCannotBeCreatedOrDeleted(): void
    {
        $setting = $this->em->getRepository(PlatformSetting::class)->findOneBy([]);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/platform/setting/new');
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/platform/setting/'.$setting->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAuditLogIsReadOnlyAndDisplays(): void
    {
        $this->em->persist((new AuditLog())->setAdmin($this->admin)->setAction('demo_action')->setEntityType('Payment')->setEntityId(1)->setCreatedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $id = $this->em->getRepository(AuditLog::class)->findOneBy([])->getId();
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/audit/log');
        $this->assertSelectorTextContains('body', 'demo_action');
        $this->client->request('GET', '/admin/audit/log/'.$id);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/audit/log/'.$id.'/edit');
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/admin/audit/log/'.$id);
        $this->assertResponseStatusCodeSame(405);
    }

    public function testOrderEmailsGoOutAfterPlacementAndStatusChange(): void
    {
        $this->cart($this->product('Saw', price: '600.00'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/checkout');
        $this->client->submit($crawler->selectButton('Place order')->form(['checkout[shippingMethod]' => 'standard', 'checkout[paymentMethod]' => 'cod']));

        $this->assertEmailCount(2);
        $this->assertEmailHeaderSame($this->getMailerMessage(0), 'To', 'customer@example.test');
        $this->assertEmailHeaderSame($this->getMailerMessage(1), 'To', 'admin@example.test');
    }

    // ---- Helpers

    /** @param array<string, mixed> $overrides */
    private function preview(array $overrides = []): array
    {
        $this->client->jsonRequest('POST', '/checkout/preview', $overrides + [
            'addressId' => $this->address->getId(),
            'shippingMethod' => 'standard',
            'paymentMethod' => 'cod',
        ]);
        $this->assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $fields */
    private function submitCheckout(string $method, array $fields = []): void
    {
        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form();
        $form->disableValidation();
        $form->setValues($fields + ['checkout[shippingMethod]' => 'standard', 'checkout[paymentMethod]' => $method]);
        $this->client->submit($form);
    }

    /** @param array<string, mixed> $fields */
    private function checkout(string $method, array $fields = []): Order
    {
        $this->submitCheckout($method, $fields);
        $this->assertResponseRedirects();

        return $this->onlyOrder();
    }

    private function placeBankOrder(): Payment
    {
        return $this->checkout(Payment::METHOD_BANK, ['checkout[paymentProof]' => $this->pngUpload()])->getPayment();
    }

    private function pngUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, 'receipt.png', 'image/png', null, true);
    }

    private function removeProof(Payment $payment): void
    {
        @unlink(static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/proofs/'.$payment->getProofFile());
    }

    private function adminAction(Payment $payment, string $action, string $note = ''): void
    {
        // Queue pages only show buttons for allowed actions, so refused actions need a token made directly.
        $this->client->request('GET', '/admin/payment/'.$payment->getId());
        $this->assertResponseIsSuccessful();
        $request = $this->client->getRequest();
        $stack = static::getContainer()->get('request_stack');
        $stack->push($request);
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('payment'.$payment->getId())->getValue();
        $request->getSession()->save();
        $stack->pop();

        $this->client->request('POST', sprintf('/admin/payment/%d/%s', $payment->getId(), $action), [
            '_token' => $token,
            'note' => $note,
        ]);
        $this->assertResponseRedirects('/admin/payment');
    }

    private function assertAudited(string $action): AuditLog
    {
        $this->em->clear();
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => $action], ['id' => 'DESC']);
        $this->assertNotNull($log, 'Missing audit row for '.$action);
        $this->assertSame('admin@example.test', $log->getAdmin()->getEmail());

        return $log;
    }

    private function assertNoOrders(): void
    {
        $this->assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }

    private function onlyOrder(): Order
    {
        $this->em->clear();
        $orders = $this->em->getRepository(Order::class)->findAll();
        $this->assertCount(1, $orders);

        return $orders[0];
    }

    private function freshAdmin(): User
    {
        return $this->em->find(User::class, $this->admin->getId());
    }

    private function setting(string $key, string $value): void
    {
        $this->em->clear();
        $this->em->getRepository(PlatformSetting::class)->findOneBy(['settingKey' => $key])->setSettingValue($value);
        $this->em->flush();
    }

    private function purge(): void
    {
        $this->em->clear();
        foreach ([
            AuditLog::class, PaymentStatusHistory::class, Payment::class, OrderStatusHistory::class,
            OrderItem::class, Order::class, CartItem::class, WishlistItem::class, Review::class,
            StockMovement::class, Address::class, DiscountCode::class, Product::class, Brand::class,
            Inquiry::class, PlatformSetting::class, User::class,
        ] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        $this->em->createQuery('DELETE FROM '.Category::class)->execute();
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFullName('Test User')->setRoles($roles)->setPassword('hash')
            ->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function address(User $owner, string $province): Address
    {
        $address = (new Address())->setLabel('Home')->setRecipient('Recipient')->setPhone('0917')->setLine1('1 Street')
            ->setCity('Somewhere')->setProvince($province)->setPostalCode('1000')->setIsDefault(false)->setOwner($owner);
        $this->em->persist($address);

        return $address;
    }

    private function product(string $name, string $price = '100.00', ?string $salePrice = null, int $stock = 10, string $status = Product::STATUS_ACTIVE): Product
    {
        $id = ++$this->sequence;
        $category = (new Category())->setName('Category '.$id)->setSlug('category-'.$id)->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($category);

        $product = (new Product())->setName($name)->setSku('SKU-'.$id)->setSlug('product-'.$id)->setDescription('Description')
            ->setPrice($price)->setCostPrice('1.00')->setSalePrice($salePrice)->setUnit('pc')->setStock($stock)->setLowStockThreshold(1)
            ->setStatus($status)->setIsFeatured(false)->setCreatedAt(new \DateTimeImmutable())->setCategory($category);
        $this->em->persist($product);

        return $product;
    }

    private function cart(Product $product, int $quantity, ?User $owner = null): CartItem
    {
        $item = (new CartItem())->setOwner($owner ?? $this->customer)->setProduct($product)->setQuantity($quantity)
            ->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($item);

        return $item;
    }

    private function voucher(string $code, string $type, string $value): DiscountCode
    {
        $voucher = (new DiscountCode())->setCode($code)->setType($type)->setValue($value)->setTimesUsed(0)->setIsActive(true);
        $this->em->persist($voucher);

        return $voucher;
    }

    private function order(
        User $customer,
        Product $product,
        string $status = Order::STATUS_PENDING,
        string $method = Payment::METHOD_COD,
        string $paymentStatus = Payment::STATUS_PENDING,
        int $quantity = 1,
        string $total = '100.00',
    ): Order {
        $id = ++$this->sequence;
        $address = $customer === $this->customer ? $this->address : $this->address($customer, 'Metro Manila');
        $order = (new Order())->setOrderNumber('ORD-'.$id)->setIdempotencyKey('key-'.$id)->setStatus($status)->setShippingMethod('standard')
            ->setSubtotal($total)->setShippingFee('0.00')->setDiscount('0.00')->setTotal($total)
            ->setCreatedAt(new \DateTimeImmutable())->setCustomer($customer)->setShippingAddress($address);
        $line = (new OrderItem())->setProduct($product)->setQuantity($quantity)->setUnitPrice('100.00');
        $order->addItem($line);
        $this->em->persist($order);
        $this->em->persist($line);

        $payment = (new Payment())->setOrder($order)->setMethod($method)->setStatus($paymentStatus)->setAmount($total)->setCreatedAt(new \DateTimeImmutable());
        $order->setPayment($payment);
        $this->em->persist($payment);

        return $order;
    }
}
