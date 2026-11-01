<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;
use App\Repository\AddressRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AddressRepository::class)]
class Address
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[ORM\Column(length: 50)]
    private ?string $label = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120)]
    private ?string $recipient = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    #[ORM\Column(length: 30)]
    private ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private ?string $line1 = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private ?string $city = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private ?string $province = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20)]
    private ?string $postalCode = null;

    #[ORM\Column]
    private ?bool $isDefault = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function setRecipient(string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getLine1(): ?string
    {
        return $this->line1;
    }

    public function setLine1(string $line1): static
    {
        $this->line1 = $line1;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getProvince(): ?string
    {
        return $this->province;
    }

    public function setProvince(string $province): static
    {
        $this->province = $province;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(string $postalCode): static
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function isDefault(): ?bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }
}
