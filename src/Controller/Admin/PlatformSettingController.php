<?php

namespace App\Controller\Admin;

use App\Entity\PlatformSetting;
use App\Form\PlatformSettingType;
use App\Repository\PlatformSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/platform/setting')]
final class PlatformSettingController extends AbstractController
{
    #[Route(name: 'app_admin_platform_setting_index', methods: ['GET'])]
    public function index(PlatformSettingRepository $platformSettingRepository): Response
    {
        return $this->render('admin/platform_setting/index.html.twig', [
            'platform_settings' => $platformSettingRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_admin_platform_setting_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $platformSetting = new PlatformSetting();
        $form = $this->createForm(PlatformSettingType::class, $platformSetting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($platformSetting);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_platform_setting_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/platform_setting/new.html.twig', [
            'platform_setting' => $platformSetting,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_platform_setting_show', methods: ['GET'])]
    public function show(PlatformSetting $platformSetting): Response
    {
        return $this->render('admin/platform_setting/show.html.twig', [
            'platform_setting' => $platformSetting,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_platform_setting_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, PlatformSetting $platformSetting, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PlatformSettingType::class, $platformSetting);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_platform_setting_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/platform_setting/edit.html.twig', [
            'platform_setting' => $platformSetting,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_platform_setting_delete', methods: ['POST'])]
    public function delete(Request $request, PlatformSetting $platformSetting, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$platformSetting->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($platformSetting);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_admin_platform_setting_index', [], Response::HTTP_SEE_OTHER);
    }
}
