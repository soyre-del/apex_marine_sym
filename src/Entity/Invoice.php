<?php

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'invoice_id')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DispatchRequest::class)]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'request_id', nullable: false, onDelete: 'RESTRICT')]
    private ?DispatchRequest $request = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'client_id', referencedColumnName: 'client_id', nullable: false, onDelete: 'RESTRICT')]
    private ?ClientProfile $client = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $amount = null;

    #[ORM\Column(length: 3, nullable: true, options: ['default' => 'USD'])]
    private ?string $currency = 'USD';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $paymentMethod = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $referenceNumber = null;

    #[ORM\Column(
        type: 'string',
        length: 10,
        nullable: true,
        options: ['default' => 'unpaid'],
        columnDefinition: "ENUM('unpaid','paid','failed') DEFAULT 'unpaid'",
    )]
    private ?string $paymentStatus = 'unpaid';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true, columnDefinition: 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP')]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getClient(): ?ClientProfile
    {
        return $this->client;
    }

    public function setClient(?ClientProfile $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(?string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;

        return $this;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(?string $referenceNumber): static
    {
        $this->referenceNumber = $referenceNumber;

        return $this;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(string $paymentStatus): static
    {
        $this->paymentStatus = $paymentStatus;

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
}
