<?php

namespace App\Entity;

use App\Repository\DeploymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeploymentRepository::class)]
#[ORM\Table(name: 'deployments')]
class Deployment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'deployment_id')]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(name: 'request_id', referencedColumnName: 'request_id', nullable: false, onDelete: 'RESTRICT')]
    private ?DispatchRequest $request = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'admin_id', referencedColumnName: 'user_id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $admin = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'engineer_id', referencedColumnName: 'engineer_id', nullable: false, onDelete: 'RESTRICT')]
    private ?EngineerProfile $engineer = null;

    #[ORM\Column(
        type: 'string',
        length: 20,
        nullable: true,
        options: ['default' => 'en_route'],
        columnDefinition: "ENUM('en_route','on_site','completed') DEFAULT 'en_route'",
    )]
    private ?string $deploymentStatus = 'en_route';

    #[ORM\Column(type: 'datetime_immutable', nullable: true, columnDefinition: 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP')]
    private ?\DateTimeImmutable $deployedAt = null;

    public function __construct()
    {
        $this->deployedAt = new \DateTimeImmutable();
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

    public function getAdmin(): ?User
    {
        return $this->admin;
    }

    public function setAdmin(?User $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    public function getEngineer(): ?EngineerProfile
    {
        return $this->engineer;
    }

    public function setEngineer(?EngineerProfile $engineer): static
    {
        $this->engineer = $engineer;

        return $this;
    }

    public function getDeploymentStatus(): ?string
    {
        return $this->deploymentStatus;
    }

    public function setDeploymentStatus(string $deploymentStatus): static
    {
        $this->deploymentStatus = $deploymentStatus;

        return $this;
    }

    public function getDeployedAt(): ?\DateTimeImmutable
    {
        return $this->deployedAt;
    }

    public function setDeployedAt(\DateTimeImmutable $deployedAt): static
    {
        $this->deployedAt = $deployedAt;

        return $this;
    }
}
