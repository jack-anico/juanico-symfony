<?php

namespace App\Entity;

use App\Repository\PaymentStatusHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentStatusHistoryRepository::class)]
class PaymentStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fromStatus = null;

    #[ORM\Column(length: 20)]
    private ?string $toStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Payment $payment = null;

    #[ORM\ManyToOne]
    private ?User $changedBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function setFromStatus(?string $fromStatus): static
    {
        $this->fromStatus = $fromStatus;

        return $this;
    }

    public function getToStatus(): ?string
    {
        return $this->toStatus;
    }

    public function setToStatus(string $toStatus): static
    {
        $this->toStatus = $toStatus;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getPayment(): ?Payment
    {
        return $this->payment;
    }

    public function setPayment(?Payment $payment): static
    {
        $this->payment = $payment;

        return $this;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function setChangedBy(?User $changedBy): static
    {
        $this->changedBy = $changedBy;

        return $this;
    }
}
