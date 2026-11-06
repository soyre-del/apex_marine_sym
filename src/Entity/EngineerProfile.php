<?php

namespace App\Entity;

use App\Repository\EngineerProfileRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EngineerProfileRepository::class)]
#[ORM\Table(name: 'engineer_profiles')]
class EngineerProfile
{
    #[ORM\Id]
    #[ORM\Column(name: 'engineer_id')]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $specialty = null;

    #[ORM\Column(
        name: 'current_status',
        type: 'string',
        length: 20,
        nullable: true,
        options: ['default' => 'available'],
        columnDefinition: "ENUM('available','deployed','on_leave') DEFAULT 'available'",
    )]
    private ?string $currentStatus = 'available';

    public function getId(): ?int
    {
        return $this->id;
    }

    // <-- Added this method so you can manually set the ID
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getSpecialty(): ?string
    {
        return $this->specialty;
    }

    public function setSpecialty(string $specialty): static
    {
        $this->specialty = $specialty;

        return $this;
    }

    public function getCurrentStatus(): ?string
    {
        return $this->currentStatus;
    }

    public function setCurrentStatus(?string $currentStatus): static
    {
        $this->currentStatus = $currentStatus;

        return $this;
    }
}