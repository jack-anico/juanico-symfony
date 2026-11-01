<?php

namespace App\DataFixtures;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\DiscountCode;
use App\Entity\Product;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Demo catalog. References are named product-0 to product-29 for DemoOrderFixtures.
 */
class CatalogFixtures extends Fixture
{
    private const CATEGORIES = [
        'Hand Tools' => ['Hammers', 'Saws', 'Chisels and Planes'],
        'Power Tools' => ['Drills', 'Sanders'],
        'Fasteners' => ['Nails', 'Screws'],
        'Building Materials' => ['Lumber', 'Cement', 'Plywood'],
        'Paint and Finishes' => ['Paint', 'Varnish'],
    ];

    private const BRANDS = ['Stanley', 'Makita', 'Bosch', 'Boysen', 'Holcim'];

    /** name, category, brand, price, cost, sale price, unit, stock, low-stock threshold, featured */
    private const PRODUCTS = [
        ['Claw Hammer 16oz', 'Hammers', 'Stanley', '450.00', '280.00', null, 'pc', 40, 5, true],
        ['Rubber Mallet', 'Hammers', 'Stanley', '320.00', '190.00', null, 'pc', 25, 5, false],
        ['Sledgehammer 4lb', 'Hammers', null, '890.00', '560.00', '790.00', 'pc', 12, 3, false],
        ['Crosscut Hand Saw 20in', 'Saws', 'Stanley', '620.00', '380.00', null, 'pc', 30, 5, true],
        ['Hacksaw Frame', 'Saws', 'Stanley', '380.00', '220.00', null, 'pc', 3, 5, false],
        ['Bench Chisel Set', 'Chisels and Planes', 'Stanley', '1250.00', '800.00', '1099.00', 'set', 15, 3, true],
        ['Block Plane', 'Chisels and Planes', 'Stanley', '980.00', '640.00', null, 'pc', 0, 3, false],
        ['Cordless Drill 18V', 'Drills', 'Makita', '6500.00', '4700.00', '5900.00', 'pc', 10, 2, true],
        ['Hammer Drill 13mm', 'Drills', 'Bosch', '4200.00', '3100.00', null, 'pc', 8, 2, false],
        ['Drill Bit Set 13pc', 'Drills', 'Bosch', '750.00', '450.00', null, 'set', 35, 5, false],
        ['Random Orbit Sander', 'Sanders', 'Makita', '3800.00', '2800.00', null, 'pc', 6, 2, false],
        ['Belt Sander', 'Sanders', 'Bosch', '5200.00', '3900.00', null, 'pc', 1, 2, false],
        ['Common Nails 2in', 'Nails', null, '85.00', '55.00', null, 'kg', 200, 30, false],
        ['Common Nails 3in', 'Nails', null, '85.00', '55.00', null, 'kg', 180, 30, false],
        ['Finishing Nails 1.5in', 'Nails', null, '110.00', '70.00', null, 'kg', 90, 20, false],
        ['Wood Screws Box 100pc', 'Screws', null, '165.00', '95.00', null, 'box', 120, 20, false],
        ['Drywall Screws Box 100pc', 'Screws', null, '145.00', '85.00', '125.00', 'box', 75, 20, false],
        ['Coco Lumber 2x2x8', 'Lumber', null, '130.00', '85.00', null, 'pc', 300, 50, false],
        ['Coco Lumber 2x3x10', 'Lumber', null, '245.00', '165.00', null, 'pc', 220, 40, true],
        ['Good Lumber 2x4x8', 'Lumber', null, '520.00', '370.00', null, 'pc', 60, 10, false],
        ['Portland Cement 40kg', 'Cement', 'Holcim', '265.00', '215.00', null, 'bag', 500, 80, true],
        ['Skim Coat 20kg', 'Cement', 'Holcim', '480.00', '360.00', null, 'bag', 70, 15, false],
        ['Marine Plywood 1/2in', 'Plywood', null, '1150.00', '850.00', null, 'sheet', 45, 10, true],
        ['Ordinary Plywood 1/4in', 'Plywood', null, '420.00', '300.00', '380.00', 'sheet', 80, 15, false],
        ['Latex Paint White 4L', 'Paint', 'Boysen', '780.00', '560.00', null, 'can', 55, 10, true],
        ['Enamel Paint Black 1L', 'Paint', 'Boysen', '340.00', '240.00', null, 'can', 4, 10, false],
        ['Flat Wall Paint 16L', 'Paint', 'Boysen', '2650.00', '1950.00', '2399.00', 'pail', 20, 5, false],
        ['Clear Varnish 1L', 'Varnish', 'Boysen', '420.00', '290.00', null, 'can', 30, 5, false],
        ['Wood Stain Walnut 1L', 'Varnish', 'Boysen', '460.00', '320.00', null, 'can', 18, 5, false],
        ['Discontinued Paint Roller', 'Paint', null, '150.00', '90.00', null, 'pc', 9, 2, false],
    ];

    public function load(ObjectManager $manager): void
    {
        $slugger = new AsciiSlugger();
        $categories = [];
        $order = 0;

        foreach (self::CATEGORIES as $parentName => $children) {
            $parent = $this->category($parentName, null, $order++, $manager, $slugger);
            foreach ($children as $index => $childName) {
                $categories[$childName] = $this->category($childName, $parent, $index, $manager, $slugger);
            }
        }

        $brands = [];
        foreach (self::BRANDS as $name) {
            $brands[$name] = (new Brand())->setName($name)->setIsActive(true);
            $manager->persist($brands[$name]);
        }

        foreach (self::PRODUCTS as $index => [$name, $category, $brand, $price, $cost, $sale, $unit, $stock, $threshold, $featured]) {
            $product = (new Product())
                ->setName($name)
                ->setSku(sprintf('JNC-%04d', $index + 1))
                ->setSlug($slugger->slug($name)->lower()->toString())
                ->setDescription(sprintf('%s for carpentry and construction work.', $name))
                ->setPrice($price)
                ->setCostPrice($cost)
                ->setSalePrice($sale)
                ->setUnit($unit)
                ->setStock($stock)
                ->setLowStockThreshold($threshold)
                ->setStatus(29 === $index ? Product::STATUS_ARCHIVED : Product::STATUS_ACTIVE)
                ->setIsFeatured($featured)
                ->setCreatedAt(new \DateTimeImmutable(sprintf('-%d days', 60 - $index)))
                ->setCategory($categories[$category])
                ->setBrand(null === $brand ? null : $brands[$brand]);
            $manager->persist($product);
            $this->addReference('product-'.$index, $product);
        }

        $manager->persist((new DiscountCode())->setCode('SAVE10')->setType('percent')->setValue('10.00')->setMinSpend('1000.00')->setTimesUsed(0)->setIsActive(true));
        $manager->persist((new DiscountCode())->setCode('LESS200')->setType('fixed')->setValue('200.00')->setMinSpend('2000.00')->setUsageLimit(50)->setTimesUsed(0)->setIsActive(true));
        $manager->persist((new DiscountCode())->setCode('EXPIRED')->setType('percent')->setValue('50.00')->setExpiresAt(new \DateTimeImmutable('-1 month'))->setTimesUsed(0)->setIsActive(true));

        $manager->flush();
    }

    private function category(string $name, ?Category $parent, int $order, ObjectManager $manager, AsciiSlugger $slugger): Category
    {
        $category = (new Category())
            ->setName($name)
            ->setSlug($slugger->slug($name)->lower()->toString())
            ->setDescription(sprintf('Browse our %s.', strtolower($name)))
            ->setDisplayOrder($order)
            ->setIsActive(true)
            ->setParent($parent);
        $manager->persist($category);

        return $category;
    }
}
