<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_USERS_EMAIL', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USERS_USERNAME', columns: ['username'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
#[UniqueEntity(fields: ['username'], message: 'This username is already taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'user_id')]
    private ?int $userId = null;

    #[ORM\Column(length: 60, name: 'username', nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 100, name: 'first_name')]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, name: 'middle_name', nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(length: 100, name: 'surname')]
    private ?string $surname = null;

    #[ORM\Column(length: 100)]
    private ?string $email = null;

    #[ORM\Column(length: 255, name: 'password_hash')]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 20, columnDefinition: "ENUM('client','engineer','admin') NOT NULL DEFAULT 'client'")]
    private string $role = 'client';

    #[ORM\Column(name: 'is_active', options: ['default' => 1])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, name: 'created_at', columnDefinition: 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->userId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $trimmed = $username !== null ? trim($username) : '';
        $this->username = $trimmed !== '' ? $trimmed : null;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getWelcomeGreeting(): string
    {
        $display = $this->username ?: ($this->firstName ?: ($this->getFullName() ?: ''));

        return $display !== '' ? 'Welcome, '.$display : 'Welcome';
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName !== null ? self::capitalizeName($firstName) : null;

        return $this;
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function setMiddleName(?string $middleName): static
    {
        $formatted = $middleName !== null ? self::capitalizeName($middleName) : '';
        $this->middleName = $formatted !== '' ? $formatted : null;

        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): static
    {
        $this->surname = $surname !== null ? self::capitalizeName($surname) : null;

        return $this;
    }

    /**
     * Trims, collapses repeated spaces, and uppercases the first letter of every
     * word (including parts joined by hyphens or apostrophes), e.g.
     * "juan  dela cruz" -> "Juan Dela Cruz", "o'neil-smith" -> "O'Neil-Smith".
     * The remaining letters are kept exactly as the user typed them.
     */
    public static function capitalizeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/(^|[\s\'-])(\p{Ll})/u',
            static fn (array $m): string => $m[1].mb_strtoupper($m[2], 'UTF-8'),
            $name,
        );
    }

    public function getFullName(): ?string
    {
        $parts = array_filter(
            [$this->firstName, $this->middleName, $this->surname],
            static fn (?string $part): bool => $part !== null && trim($part) !== ''
        );

        $fullName = trim(implode(' ', $parts));

        return $fullName !== '' ? $fullName : null;
    }

    public function setFullName(string $fullName): static
    {
        $parts = preg_split('/\s+/', self::capitalizeName($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            $this->firstName = '';
            $this->middleName = null;
            $this->surname = '';
        } elseif (count($parts) === 1) {
            $this->firstName = $parts[0];
            $this->middleName = null;
            $this->surname = '';
        } elseif (count($parts) === 2) {
            $this->firstName = $parts[0];
            $this->middleName = null;
            $this->surname = $parts[1];
        } else {
            $this->firstName = (string) array_shift($parts);
            $this->surname = (string) array_pop($parts);
            $middle = trim(implode(' ', $parts));
            $this->middleName = $middle !== '' ? $middle : null;
        }

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_'.strtoupper($this->role), 'ROLE_USER'];
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function setPassword(string $password): static
    {
        $this->passwordHash = $password;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
