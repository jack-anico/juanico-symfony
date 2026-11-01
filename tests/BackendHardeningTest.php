<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\Brand;
use App\Entity\CartItem;
use App\Entity\Category;
use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PlatformSetting;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BackendHardeningTest extends WebTestCase
{
    private const PASSWORD = 'Secret123!';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->em = static::getContainer()->get('doctrine')->getManager();
        if (!$this->em->isOpen()) {
            static::getContainer()->get('doctrine')->resetManager();
            $this->em = static::getContainer()->get('doctrine')->getManager();
        }
        $this->purge();
        parent::tearDown();
    }

    // ---- G1 validation

    public function testProductFormRejectsBadValues(): void
    {
        $existing = $this->product('Existing');
        $this->em->flush();
        $sku = $existing->getSku();
        $slug = $existing->getSlug();
        $category = (string) $existing->getCategory()->getId();
        $this->client->loginUser($this->admin());

        $valid = [
            'product[name]' => 'New Saw', 'product[sku]' => 'NEW-1', 'product[slug]' => 'new-saw', 'product[description]' => 'A saw',
            'product[price]' => '100.00', 'product[costPrice]' => '50.00', 'product[unit]' => 'pc', 'product[stock]' => '5',
            'product[lowStockThreshold]' => '1', 'product[status]' => Product::STATUS_ACTIVE, 'product[category]' => $category,
        ];

        foreach ([
            'blank name' => ['product[name]' => ''],
            'zero price' => ['product[price]' => '0'],
            'negative price' => ['product[price]' => '-5'],
            'negative cost' => ['product[costPrice]' => '-1'],
            'negative stock' => ['product[stock]' => '-1'],
            'blank stock' => ['product[stock]' => ''],
            'sale above price' => ['product[salePrice]' => '150.00'],
            'sale equal to price' => ['product[salePrice]' => '100.00'],
            'duplicate sku' => ['product[sku]' => $sku],
            'duplicate slug' => ['product[slug]' => $slug],
            'bad slug' => ['product[slug]' => 'Not A Slug'],
            'name too long' => ['product[name]' => str_repeat('x', 151)],
        ] as $case => $change) {
            $this->client->request('GET', '/admin/product/new');
            $this->client->submitForm('Save', $change + $valid);
            $this->assertResponseStatusCodeSame(422, $case);
        }

        $this->client->request('GET', '/admin/product/new');
        $this->client->submitForm('Save', ['product[salePrice]' => '99.99'] + $valid);
        $this->assertResponseRedirects('/admin/product');
    }

    public function testDiscountCodeFormRejectsBadValues(): void
    {
        $this->em->persist((new DiscountCode())->setCode('TAKEN')->setType('percent')->setValue('5.00')->setTimesUsed(0)->setIsActive(true));
        $this->em->flush();
        $this->client->loginUser($this->admin());

        foreach ([
            'over 100 percent' => ['discount_code[code]' => 'A', 'discount_code[type]' => 'percent', 'discount_code[value]' => '101'],
            'negative' => ['discount_code[code]' => 'B', 'discount_code[type]' => 'fixed', 'discount_code[value]' => '-1'],
            'duplicate' => ['discount_code[code]' => 'TAKEN', 'discount_code[type]' => 'fixed', 'discount_code[value]' => '10'],
        ] as $case => $values) {
            $this->client->request('GET', '/admin/discount/code/new');
            $this->client->submitForm('Save', $values);
            $this->assertResponseStatusCodeSame(422, $case);
        }

        $this->client->request('GET', '/admin/discount/code/new');
        $this->client->submitForm('Save', ['discount_code[code]' => 'BIGFIXED', 'discount_code[type]' => 'fixed', 'discount_code[value]' => '500']);
        $this->assertResponseRedirects();
    }

    public function testRegistrationRejectsDuplicateAndInvalidEmail(): void
    {
        $this->user('taken@example.test');
        $this->em->flush();

        foreach (['taken@example.test', 'not-an-email'] as $email) {
            $this->client->request('GET', '/register');
            $this->client->submitForm('Register', [
                'registration_form[fullName]' => 'Someone',
                'registration_form[email]' => $email,
                'registration_form[plainPassword]' => self::PASSWORD,
                'registration_form[agreeTerms]' => true,
            ]);
            $this->assertResponseStatusCodeSame(422, $email);
        }
    }

    public function testDatabaseRejectsDuplicateCartRows(): void
    {
        $user = $this->user('customer@example.test');
        $product = $this->product('Saw');
        foreach ([1, 2] as $quantity) {
            $this->em->persist((new CartItem())->setOwner($user)->setProduct($product)->setQuantity($quantity)
                ->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable()));
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    // ---- G5 pagination

    public function testCatalogPaginatesAndKeepsItsSortFilter(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->product(sprintf('Saw %02d', $i), price: sprintf('%d.00', 100 + $i));
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/catalog?sort=price_desc');
        $this->assertResponseIsSuccessful();
        $this->assertCount(20, $crawler->filter('tbody tr'));
        $this->assertStringContainsString('Saw 25', $crawler->filter('tbody tr')->first()->text());

        $crawler = $this->client->request('GET', '/catalog?sort=price_desc&page=2');
        $this->assertCount(5, $crawler->filter('tbody tr'));
        $this->assertStringContainsString('Saw 05', $crawler->filter('tbody tr')->first()->text());

        // Newest-first would start page 2 at Saw 05, so this proves the sort survives paging
        $crawler = $this->client->request('GET', '/catalog?sort=price_asc&page=2');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Saw 21', $crawler->filter('tbody tr')->first()->text());

        $crawler = $this->client->request('GET', '/catalog?page=-3');
        $this->assertResponseIsSuccessful();
        $this->assertCount(20, $crawler->filter('tbody tr'));

        $crawler = $this->client->request('GET', '/catalog?page=99');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('tbody tr'), 'The empty message row');
    }

    public function testAdminTablesPaginate(): void
    {
        for ($i = 1; $i <= 22; ++$i) {
            $this->product('Saw '.$i);
            $this->user(sprintf('user%d@example.test', $i));
        }
        $this->em->flush();
        $this->client->loginUser($this->admin());

        foreach (['/admin/product', '/admin/user'] as $path) {
            $crawler = $this->client->request('GET', $path);
            $this->assertResponseIsSuccessful();
            $this->assertCount(20, $crawler->filter('tbody tr'), $path);
        }
        foreach (['/admin/order', '/admin/payment', '/admin/inquiry', '/admin/audit/log', '/orders'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseIsSuccessful($path);
        }
    }

    // ---- G6 login hardening

    public function testDeactivatedAccountCannotLogIn(): void
    {
        $user = $this->user('off@example.test', password: self::PASSWORD);
        $user->setIsActive(false);
        $this->em->flush();

        $this->logIn('off@example.test');
        $this->assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'deactivated');

        $this->client->request('GET', '/account');
        $this->assertResponseRedirects('/login');
    }

    public function testDeactivatingEndsAnOpenSession(): void
    {
        $user = $this->user('session@example.test', password: self::PASSWORD);
        $this->em->flush();
        $this->logIn('session@example.test');
        $this->client->request('GET', '/account');
        $this->assertResponseIsSuccessful();

        $this->em->clear();
        $this->em->find(User::class, $user->getId())->setIsActive(false);
        $this->em->flush();

        $this->client->request('GET', '/account');
        $this->assertResponseRedirects('/login');
        $this->client->request('GET', '/account');
        $this->assertResponseRedirects('/login');
    }

    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $email = sprintf('throttle%s@example.test', bin2hex(random_bytes(4)));
        $this->user($email, password: self::PASSWORD);
        $this->em->flush();

        for ($i = 0; $i < 5; ++$i) {
            $this->logIn($email, 'wrong-password');
        }

        $this->logIn($email);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Too many failed login attempts');
    }

    public function testAdminHasCustomerAccessThroughRoleHierarchy(): void
    {
        $this->client->loginUser($this->admin());

        $this->client->request('GET', '/cart');

        $this->assertResponseIsSuccessful();
        $this->assertTrue(static::getContainer()->get('security.role_hierarchy')->getReachableRoleNames(['ROLE_ADMIN']) === ['ROLE_ADMIN', 'ROLE_USER']);
    }

    public function testRememberMeAndLogout(): void
    {
        $this->user('remember@example.test', password: self::PASSWORD);
        $this->em->flush();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => 'remember@example.test', 'password' => self::PASSWORD, '_remember_me' => true]);
        $this->assertNotNull($this->client->getCookieJar()->get('REMEMBERME'));

        // A browser restart loses the session cookie but keeps the remember-me cookie.
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/account');
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/logout');
        $this->assertResponseRedirects();
        $this->assertNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->client->request('GET', '/account');
        $this->assertResponseRedirects('/login');
    }

    public function testLoginWithoutRememberMeSetsNoCookie(): void
    {
        $this->user('plain@example.test', password: self::PASSWORD);
        $this->em->flush();

        $this->logIn('plain@example.test');

        $this->assertNull($this->client->getCookieJar()->get('REMEMBERME'));
    }

    public function testEmailVerificationLinkVerifiesAccount(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'registration_form[fullName]' => 'New Customer',
            'registration_form[email]' => 'verify@example.test',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]);
        $this->assertEmailCount(1);

        preg_match('/href="([^"]*verify[^"]*)"/', $this->getMailerMessage()->getHtmlBody(), $match);
        $this->assertNotEmpty($match, 'The email contains a verification link');
        $link = html_entity_decode($match[1]);

        // A tampered signature must not verify anything.
        $this->client->request('GET', $link.'x');
        $this->em->clear();
        $this->assertFalse($this->em->getRepository(User::class)->findOneBy(['email' => 'verify@example.test'])->isVerified());

        $this->client->request('GET', $link);
        $this->assertResponseRedirects('/login');
        $this->em->clear();
        $this->assertTrue($this->em->getRepository(User::class)->findOneBy(['email' => 'verify@example.test'])->isVerified());
    }

    // ---- G8 and G10

    public function testTimezoneIsManila(): void
    {
        $this->assertSame('Asia/Manila', date_default_timezone_get());
    }

    public function testStockCheckCommandReportsLowAndOutOfStock(): void
    {
        $this->product('Low Saw', stock: 1, threshold: 5);
        $this->product('Gone Saw', stock: 0, threshold: 5);
        $this->product('Plenty Saw', stock: 50, threshold: 5);
        $this->em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:stock:check'));
        $this->assertSame(1, $tester->execute([]), 'A non-zero exit lets a scheduler raise an alert');

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Low Saw', $output);
        $this->assertStringContainsString('Gone Saw', $output);
        $this->assertStringNotContainsString('Plenty Saw', $output);
    }

    public function testStockCheckCommandSucceedsWhenAllIsWell(): void
    {
        $this->product('Plenty Saw', stock: 50, threshold: 5);
        $this->em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:stock:check'));

        $this->assertSame(0, $tester->execute([]));
    }

    public function testMoneyHelpersDoNotDriftLikeFloats(): void
    {
        $product = $this->product('Nails', price: '0.10');
        $line = (new OrderItem())->setProduct($product)->setQuantity(3)->setUnitPrice('0.10');
        $cart = (new CartItem())->setProduct($product)->setQuantity(3);

        $this->assertSame('0.30', $line->getLineTotal());
        $this->assertSame('0.30', $cart->getLineTotal());
    }

    private function logIn(string $email, string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => $password]);
    }

    private function admin(): User
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $this->em->flush();

        return $admin;
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = [], string $password = 'unused'): User
    {
        $user = (new User())->setEmail($email)->setFullName('Test User')->setRoles($roles)->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable());
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em->persist($user);

        return $user;
    }

    private function product(string $name, string $price = '100.00', int $stock = 10, int $threshold = 2): Product
    {
        $id = ++$this->sequence;
        $category = (new Category())->setName('Category '.$id)->setSlug('category-'.$id)->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($category);

        $product = (new Product())->setName($name)->setSku('SKU-'.$id)->setSlug('product-'.$id)->setDescription('Description')
            ->setPrice($price)->setCostPrice('1.00')->setUnit('pc')->setStock($stock)->setLowStockThreshold($threshold)
            ->setStatus(Product::STATUS_ACTIVE)->setIsFeatured(false)
            ->setCreatedAt(new \DateTimeImmutable('2026-01-01')->modify(sprintf('+%d minutes', $id)))->setCategory($category);
        $this->em->persist($product);

        return $product;
    }

    private function purge(): void
    {
        $this->em->clear();
        foreach ([
            AuditLog::class, CartItem::class, OrderItem::class, Order::class, Product::class, Brand::class, DiscountCode::class, PlatformSetting::class, User::class,
        ] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        $this->em->createQuery('DELETE FROM '.Category::class)->execute();
        $this->em->persist((new PlatformSetting())->setSettingKey('pending_order_escalation_hours')->setSettingValue('24'));
        $this->em->flush();
    }
}
