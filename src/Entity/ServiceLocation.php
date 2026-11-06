<?php

namespace App\Entity;

use App\Repository\ServiceLocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceLocationRepository::class)]
#[ORM\Table(name: 'service_locations')]
class ServiceLocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'location_id')]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $portName = null;

    #[ORM\ManyToOne(targetEntity: OperationalHub::class)]
    #[ORM\JoinColumn(name: 'hub_id', referencedColumnName: 'hub_id', nullable: false, onDelete: 'RESTRICT')]
    private ?OperationalHub $hub = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortName(): ?string
    {
        return $this->portName;
    }

    public function setPortName(string $portName): static
    {
        $this->portName = $portName;

        return $this;
    }

    public function getHub(): ?OperationalHub
    {
        return $this->hub;
    }

    public function setHub(?OperationalHub $hub): static
    {
        $this->hub = $hub;

        return $this;
    }
}