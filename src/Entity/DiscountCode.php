<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use App\Repository\DiscountCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscountCodeRepository::class)]
#[UniqueEntity(fields: ['code'], message: 'This code already exists.')]
#[Assert\Expression('this.getType() !== "percent" or this.getValue() <= 100', message: 'A percent discount cannot exceed 100.')]
class DiscountCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    #[ORM\Column(length: 40, unique: true)]
    private ?string $code = null;

    #[Assert\Choice(choices: ['percent', 'fixed'])]
    #[ORM\Column(length: 10)]
    private ?string $type = null;

    #[Assert\NotBlank]
    #[Assert\Positive]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $value = null;

    #[Assert\PositiveOrZero]
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $minSpend = null;

    #[Assert\Positive]
    #[ORM\Column(nullable: true)]
    private ?int $usageLimit = null;

    #[ORM\Column]
    private ?int $timesUsed = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getMinSpend(): ?string
    {
        return $this->minSpend;
    }

    public function setMinSpend(?string $minSpend): static
    {
        $this->minSpend = $minSpend;

        return $this;
    }

    public function getUsageLimit(): ?int
    {
        return $this->usageLimit;
    }

    public function setUsageLimit(?int $usageLimit): static
    {
        $this->usageLimit = $usageLimit;

        return $this;
    }

    public function getTimesUsed(): ?int
    {
        return $this->timesUsed;
    }

    public function setTimesUsed(int $timesUsed): static
    {
        $this->timesUsed = $timesUsed;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
