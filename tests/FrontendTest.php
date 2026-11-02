<?php

namespace App\Tests;

use App\Entity\CartItem;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FrontendTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testHomeFollowsTheMockup(): void
    {
        $hand = $this->category('Hand Tools', 'hand-tools');
        $this->product('Featured Hammer', $hand, featured: true);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'The right tools.');
        $this->assertSame(
            ['Power tools', 'Hand tools', 'Electrical', 'Plumbing', 'Paint & supplies', 'Shop all'],
            $crawler->filter('#categories .category-card span.font-bold')->each(fn ($node) => $node->text()),
        );
        $this->assertSame('/catalog?category='.$hand->getId(), $crawler->filter('#categories .category-card')->eq(1)->attr('href'));
        $this->assertSame('/catalog', $crawler->filter('#categories .category-card')->eq(2)->attr('href'), 'No such category yet, so it opens the whole catalog');
        $this->assertStringContainsString('Explore 1 items', $crawler->filter('#categories')->text());
        $this->assertCount(1, $crawler->filter('#shop article'));
    }

    public function testGuestCardLinksToLoginAndPriceIsInPesos(): void
    {
        $this->product('Guest Saw', $this->category('Saws', 'saws'), price: '1234.50');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/catalog');

        $this->assertStringContainsString('₱1,234.50', $crawler->filter('article')->text());
        $this->assertSame('/login', $crawler->filter('article a[aria-label^="Log in"]')->attr('href'));
        $this->assertCount(0, $crawler->filter('article form'));
    }

    public function testGuestHeaderOffersLogInAndRegister(): void
    {
        $crawler = $this->client->request('GET', '/');

        $this->assertGreaterThan(0, $crawler->filter('header a[href="/login"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('header a[href="/register"]')->count());
    }

    public function testDealsShowOnlySaleProducts(): void
    {
        $category = $this->category('Saws', 'saws');
        $this->product('Full Price Saw', $category);
        $this->product('Cheap Saw', $category, sale: '50.00');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/catalog?deals=1');

        $this->assertSelectorTextContains('h1', 'Deals');
        $this->assertCount(1, $crawler->filter('article'));
        $this->assertStringContainsString('Cheap Saw', $crawler->filter('article')->text());
    }

    public function testHeaderShowsCartCountForTheLoggedInCustomer(): void
    {
        $user = (new User())->setEmail('cart@example.test')->setFullName('Cart Owner')->setPassword('x')->setRoles([])->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);
        $product = $this->product('Cart Saw', $this->category('Saws', 'saws'));
        $this->em->persist((new CartItem())->setOwner($user)->setProduct($product)->setQuantity(3)->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable()));
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');

        $this->assertCount(0, $crawler->filter('header a[href="/register"]'));
        $this->assertSame('Cart with 1 items', $crawler->filter('header a[href="/cart"]')->attr('aria-label'));
        $this->assertCount(1, $crawler->filter('article form[action*="/cart/add/"]'), 'No featured products would mean no card');
    }

    private function category(string $name, string $slug): Category
    {
        $category = (new Category())->setName($name)->setSlug($slug)->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($category);

        return $category;
    }

    private function product(string $name, Category $category, string $price = '100.00', ?string $sale = null, bool $featured = true): Product
    {
        $slug = strtolower(str_replace(' ', '-', $name));
        $product = (new Product())->setName($name)->setSku($slug)->setSlug($slug)->setDescription('Description')
            ->setPrice($price)->setSalePrice($sale)->setCostPrice('1.00')->setUnit('pc')->setStock(10)->setLowStockThreshold(2)
            ->setStatus(Product::STATUS_ACTIVE)->setIsFeatured($featured)->setCreatedAt(new \DateTimeImmutable())->setCategory($category);
        $this->em->persist($product);

        return $product;
    }

    private function purge(): void
    {
        $this->em->clear();
        foreach ([CartItem::class, Product::class, Category::class, User::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
    }
}
