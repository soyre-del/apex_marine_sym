<?php

namespace App\Entity;

use App\Repository\DispatchRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DispatchRequestRepository::class)]
#[ORM\Table(name: 'dispatch_requests')]
class DispatchRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $vesselName = null;

    #[ORM\Column(length: 7)]
    private ?string $imoNumber = null;

    #[ORM\Column(length: 255)]
    private ?string $vesselType = null;

    #[ORM\Column(length: 255)]
    private ?string $contactPerson = null;

    #[ORM\Column(length: 50)]
    private ?string $contactPhone = null;

    #[ORM\Column(length: 255)]
    private ?string $serviceType = null;

    #[ORM\Column]
    private ?bool $isUrgent = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $attachmentPath = null;

    #[ORM\Column]
    private ?int $clientId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $company = null;

    #[ORM\Column]
    private ?int $locationId = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column]
    private ?bool $isActive = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $etaDate = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVesselName(): ?string
    {
        return $this->vesselName;
    }

    public function setVesselName(string $vesselName): static
    {
        $this->vesselName = $vesselName;

        return $this;
    }

    public function getImoNumber(): ?string
    {
        return $this->imoNumber;
    }

    public function setImoNumber(string $imoNumber): static
    {
        $this->imoNumber = $imoNumber;

        return $this;
    }

    public function getVesselType(): ?string
    {
        return $this->vesselType;
    }

    public function setVesselType(string $vesselType): static
    {
        $this->vesselType = $vesselType;

        return $this;
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(string $contactPhone): static
    {
        $this->contactPhone = $contactPhone;

        return $this;
    }

    public function getServiceType(): ?string
    {
        return $this->serviceType;
    }

    public function setServiceType(string $serviceType): static
    {
        $this->serviceType = $serviceType;

        return $this;
    }

    public function isUrgent(): ?bool
    {
        return $this->isUrgent;
    }

    public function setIsUrgent(bool $isUrgent): static
    {
        $this->isUrgent = $isUrgent;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getAttachmentPath(): ?string
    {
        return $this->attachmentPath;
    }

    public function setAttachmentPath(?string $attachmentPath): static
    {
        $this->attachmentPath = $attachmentPath;

        return $this;
    }

    public function getClientId(): ?int
    {
        return $this->clientId;
    }

    public function setClientId(int $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(string $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function setLocationId(int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

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

    public function getEtaDate(): ?\DateTimeInterface
    {
        return $this->etaDate;
    }

    public function setEtaDate(\DateTimeInterface $etaDate): static
    {
        $this->etaDate = $etaDate;

        return $this;
    }
}
