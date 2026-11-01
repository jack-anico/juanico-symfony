<?php

namespace App\Controller\Admin;

use App\Repository\CategoryRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/report')]
final class ReportController extends AbstractController
{
    #[Route('', name: 'app_admin_report', methods: ['GET'])]
    public function index(Request $request, OrderRepository $orders, ProductRepository $products, CategoryRepository $categories): Response
    {
        [$from, $to] = $this->dateRange($request);

        return $this->render('admin/report/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'revenue' => $orders->totalRevenueBetween($from, $to),
            'order_count' => $orders->countBetween($from, $to),
            'by_product' => $products->revenueByProduct($from, $to),
            'by_category' => $categories->revenueByCategory($from, $to),
        ]);
    }

    #[Route('/export.csv', name: 'app_admin_report_export', methods: ['GET'])]
    public function export(Request $request, ProductRepository $products): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $rows = $products->revenueByProduct($from, $to);

        $response = new StreamedResponse(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['SKU', 'Product', 'Units', 'Revenue'], escape: '');
            foreach ($rows as $row) {
                fputcsv($output, [
                    $this->safeCell($row['sku']),
                    $this->safeCell($row['name']),
                    $row['units'],
                    $row['revenue'],
                ], escape: '');
            }
            fclose($output);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="sales-%s-to-%s.csv"',
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
        ));

        return $response;
    }

    /** Stops spreadsheet apps from running product text as a formula. */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function dateRange(Request $request): array
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('from'))
            ?: new \DateTimeImmutable('-29 days midnight');
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->query->getString('to'))
            ?: new \DateTimeImmutable('today');

        return [$from, $to->setTime(23, 59, 59)];
    }
}
