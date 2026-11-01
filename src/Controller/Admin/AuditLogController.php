<?php

namespace App\Controller\Admin;

use App\Entity\AuditLog;
use App\Form\AuditLogType;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/audit/log')]
final class AuditLogController extends AbstractController
{
    #[Route(name: 'app_admin_audit_log_index', methods: ['GET'])]
    public function index(AuditLogRepository $auditLogRepository): Response
    {
        return $this->render('admin/audit_log/index.html.twig', [
            'audit_logs' => $auditLogRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_audit_log_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $auditLog = new AuditLog();
        $form = $this->createForm(AuditLogType::class, $auditLog);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($auditLog);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_audit_log_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/audit_log/new.html.twig', [
            'audit_log' => $auditLog,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_audit_log_show', methods: ['GET'])]
    public function show(AuditLog $auditLog): Response
    {
        return $this->render('admin/audit_log/show.html.twig', [
            'audit_log' => $auditLog,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_audit_log_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, AuditLog $auditLog, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(AuditLogType::class, $auditLog);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_audit_log_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/audit_log/edit.html.twig', [
            'audit_log' => $auditLog,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_audit_log_delete', methods: ['POST'])]
    public function delete(Request $request, AuditLog $auditLog, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$auditLog->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($auditLog);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_audit_log_index', [], Response::HTTP_SEE_OTHER);
    }
}
