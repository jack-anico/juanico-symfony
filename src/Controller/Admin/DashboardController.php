<?php

namespace App\Controller\Admin;

use App\Repository\CartItemRepository;
use App\Repository\CategoryRepository;
use App\Repository\InquiryRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlatformSettingRepository;
use App\Repository\ProductRepository;
use App\Repository\ReviewRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
final class DashboardController extends AbstractController
{
    #[Route('', name: 'app_admin_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        OrderRepository $orders,
        ProductRepository $products,
        CategoryRepository $categories,
        UserRepository $users,
        ReviewRepository $reviews,
        InquiryRepository $inquiries,
        CartItemRepository $cartItems,
        PaymentRepository $payments,
        PlatformSettingRepository $settings,
    ): Response {
        $from = $this->parseDate($request->query->getString('from')) ?? new \DateTimeImmutable('-29 days midnight');
        $to = ($this->parseDate($request->query->getString('to')) ?? new \DateTimeImmutable('today'))->setTime(23, 59, 59);
        $escalationHours = $settings->getInt('pending_order_escalation_hours');

        return $this->render('admin/dashboard/index.html.twig', [
            'from' => $from,
            'to' => $to,
            'revenue' => $orders->totalRevenueBetween($from, $to),
            'total_paid' => $payments->totalPaidBetween($from, $to),
            'order_count' => $orders->countBetween($from, $to),
            'average_order_value' => $orders->averageOrderValue($from, $to),
            'new_customers' => $users->countNewBetween($from, $to),
            'sales_by_day' => $orders->salesByDay($from, $to),
            'revenue_by_category' => $categories->revenueByCategory($from, $to),
            'orders_by_status' => $orders->countByStatus(),
            'escalation_hours' => $escalationHours,
            'stale_pending_orders' => $orders->findPendingOlderThan($escalationHours),
            'pending_bank_transfers' => count($payments->findPendingBankTransfers()),
            'collectable_cod' => count($payments->findCollectableCod()),
            'awaiting_refund' => count($payments->findAwaitingRefund()),
            'cancellation_rate' => $orders->cancellationRate(),
            'average_fulfillment_hours' => $orders->averageFulfillmentHours(),
            'repeat_customer_rate' => $users->repeatCustomerRate(),
            'active_customers' => count($users->findActiveCustomers(30)),
            'top_buyers' => $users->topBuyers(5),
            'best_sellers' => $products->findBestSellers(5),
            'slow_moving' => $products->findSlowMoving(30),
            'low_stock' => $products->findLowStock(),
            'out_of_stock' => $products->findOutOfStock(),
            'inventory_value' => $products->inventoryValue(),
            'abandoned_carts' => $cartItems->countAbandonedCarts(24),
            'pending_reviews' => count($reviews->findPending()),
            'unresolved_inquiries' => $inquiries->countUnresolved(),
        ]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
