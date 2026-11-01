<?php

namespace App\Command;

use App\Repository\ProductRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stock:check',
    description: 'Lists low-stock and out-of-stock products, and exits with 1 if there are any',
)]
class StockCheckCommand
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $low = $this->products->findLowStock();
        $out = $this->products->findOutOfStock();

        if ([] === $low && [] === $out) {
            $io->success('All products are above their low-stock thresholds.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($low as $product) {
            $rows[] = [$product->getSku(), $product->getName(), $product->getStock(), $product->getLowStockThreshold(), 'Low'];
        }
        foreach ($out as $product) {
            $rows[] = [$product->getSku(), $product->getName(), $product->getStock(), $product->getLowStockThreshold(), 'Out'];
        }

        $io->table(['SKU', 'Product', 'Stock', 'Threshold', 'State'], $rows);
        $io->warning(sprintf('%d low, %d out of stock.', count($low), count($out)));

        return Command::FAILURE;
    }
}
