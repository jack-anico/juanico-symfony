<?php

namespace App\Controller\Admin;

use App\Entity\Inquiry;
use App\Repository\InquiryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inquiries come from the public contact form. Admins read them and mark them read or resolved.
 */
#[Route('/admin/inquiry')]
final class InquiryController extends AbstractController
{
    private const STATUSES = [Inquiry::STATUS_NEW, Inquiry::STATUS_READ, Inquiry::STATUS_RESOLVED];

    #[Route(name: 'app_admin_inquiry_index', methods: ['GET'])]
    public function index(Request $request, InquiryRepository $inquiryRepository): Response
    {
        $status = $request->query->getString('status');
        $filter = in_array($status, self::STATUSES, true) ? $status : null;

        return $this->render('admin/inquiry/index.html.twig', [
            'inquiries' => null === $filter
                ? $inquiryRepository->findBy([], ['createdAt' => 'DESC'])
                : $inquiryRepository->findByStatus($filter),
            'statuses' => self::STATUSES,
            'current_status' => $filter,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_inquiry_show', methods: ['GET'])]
    public function show(Inquiry $inquiry): Response
    {
        return $this->render('admin/inquiry/show.html.twig', [
            'inquiry' => $inquiry,
        ]);
    }

    #[Route('/{id}/status', name: 'app_admin_inquiry_status', methods: ['POST'])]
    public function updateStatus(Request $request, Inquiry $inquiry, EntityManagerInterface $entityManager): Response
    {
        $status = $request->getPayload()->getString('status');

        if ($this->isCsrfTokenValid('inquiry'.$inquiry->getId(), $request->getPayload()->getString('_token'))
            && in_array($status, self::STATUSES, true)) {
            $inquiry->setStatus($status);
            $entityManager->flush();
            $this->addFlash('success', 'Inquiry updated.');
        } else {
            $this->addFlash('error', 'The inquiry could not be updated.');
        }

        return $this->redirectToRoute('app_admin_inquiry_show', ['id' => $inquiry->getId()], Response::HTTP_SEE_OTHER);
    }
}
