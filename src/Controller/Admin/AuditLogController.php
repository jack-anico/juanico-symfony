<?php

namespace App\Controller\Admin;

use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only: audit rows are never edited or deleted.
 */
#[Route('/admin/audit/log')]
final class AuditLogController extends AbstractController
{
    #[Route(name: 'app_admin_audit_log_index', methods: ['GET'])]
    public function index(Request $request, AuditLogRepository $auditLogRepository, PaginatorInterface $paginator): Response
    {
        return $this->render('admin/audit_log/index.html.twig', [
            'audit_logs' => $paginator->paginate($auditLogRepository->listQuery(), max(1, $request->query->getInt('page', 1)), 20),
        ]);
    }

    #[Route('/{id}', name: 'app_admin_audit_log_show', methods: ['GET'])]
    public function show(AuditLog $auditLog): Response
    {
        return $this->render('admin/audit_log/show.html.twig', [
            'audit_log' => $auditLog,
        ]);
    }
}
