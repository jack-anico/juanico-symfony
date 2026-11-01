<?php

namespace App\Controller\Admin;

use App\Entity\PlatformSetting;
use App\Entity\User;
use App\Form\PlatformSettingType;
use App\Repository\AuditLogRepository;
use App\Repository\PlatformSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Settings are read by key in code, so they can be edited but not created or deleted.
 */
#[Route('/admin/platform/setting')]
final class PlatformSettingController extends AbstractController
{
    #[Route(name: 'app_admin_platform_setting_index', methods: ['GET'])]
    public function index(PlatformSettingRepository $platformSettingRepository): Response
    {
        return $this->render('admin/platform_setting/index.html.twig', [
            'platform_settings' => $platformSettingRepository->findBy([], ['settingKey' => 'ASC']),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_platform_setting_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PlatformSetting $platformSetting, EntityManagerInterface $entityManager, AuditLogRepository $auditLog): Response
    {
        $previous = $platformSetting->getSettingValue();
        $form = $this->createForm(PlatformSettingType::class, $platformSetting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $admin */
            $admin = $this->getUser();
            // Both rows are saved by this one flush, which runs in a single transaction.
            $auditLog->record($admin, 'setting_changed', 'PlatformSetting', $platformSetting->getId(), [
                'key' => $platformSetting->getSettingKey(),
                'from' => $previous,
                'to' => $platformSetting->getSettingValue(),
            ]);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_platform_setting_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/platform_setting/edit.html.twig', [
            'platform_setting' => $platformSetting,
            'form' => $form,
        ]);
    }
}
