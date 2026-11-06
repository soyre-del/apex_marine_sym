<?php

namespace App\Entity;

use App\Repository\ClientProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ClientProfileRepository::class)]
#[ORM\Table(name: 'client_profiles')]
class ClientProfile
{
    #[ORM\Id]
    #[ORM\Column(name: 'client_id')]
    private ?int $id = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(name: 'contact_number', length: 50, nullable: true)]
    private ?string $contactNumber = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getContactNumber(): ?string
    {
        return $this->contactNumber;
    }

    public function setContactNumber(?string $contactNumber): static
    {
        $this->contactNumber = $contactNumber;

        return $this;
    }
}