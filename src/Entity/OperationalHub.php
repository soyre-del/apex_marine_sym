<?php

namespace App\Entity;

use App\Repository\OperationalHubRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OperationalHubRepository::class)]
#[ORM\Table(name: 'operational_hubs')]
class OperationalHub
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'hub_id')]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $hubName = null;

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'Standard'])]
    private ?string $hubType = 'Standard';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHubName(): ?string
    {
        return $this->hubName;
    }

    public function setHubName(string $hubName): static
    {
        $this->hubName = $hubName;

        return $this;
    }

    public function getHubType(): ?string
    {
        return $this->hubType;
    }

    public function setHubType(?string $hubType): static
    {
        $this->hubType = $hubType;

        return $this;
    }
}