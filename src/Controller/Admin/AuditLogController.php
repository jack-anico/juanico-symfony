<?php

namespace App\Controller\Admin;

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
    public function index(AuditLogRepository $auditLogRepository): Response
    {
        return $this->render('admin/audit_log/index.html.twig', [
            'audit_logs' => $auditLogRepository->findRecent(),
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
