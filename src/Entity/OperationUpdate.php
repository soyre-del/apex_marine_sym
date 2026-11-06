<?php

namespace App\Entity;

use App\Repository\OperationUpdateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OperationUpdateRepository::class)]
#[ORM\Table(name: 'operation_updates')]
class OperationUpdate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'update_id')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DispatchRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'request_id', nullable: false, onDelete: 'RESTRICT')]
    private ?DispatchRequest $request = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'updater_id', referencedColumnName: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $updater = null;

    #[ORM\Column(length: 100)]
    private ?string $statusMilestone = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $detailedMessage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $attachmentPath = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP')]
    private ?\DateTimeImmutable $loggedAt = null;

    public function __construct()
    {
        $this->loggedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): ?DispatchRequest
    {
        return $this->request;
    }

    public function setRequest(?DispatchRequest $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getUpdater(): ?User
    {
        return $this->updater;
    }

    public function setUpdater(?User $updater): static
    {
        $this->updater = $updater;

        return $this;
    }

    public function getStatusMilestone(): ?string
    {
        return $this->statusMilestone;
    }

    public function setStatusMilestone(string $statusMilestone): static
    {
        $this->statusMilestone = $statusMilestone;

        return $this;
    }

    public function getDetailedMessage(): ?string
    {
        return $this->detailedMessage;
    }

    public function setDetailedMessage(string $detailedMessage): static
    {
        $this->detailedMessage = $detailedMessage;

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

    public function getLoggedAt(): ?\DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function setLoggedAt(\DateTimeImmutable $loggedAt): static
    {
        $this->loggedAt = $loggedAt;

        return $this;
    }
}
