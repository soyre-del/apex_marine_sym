<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\AccountStatusChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class AccountStatusCheckerTest extends TestCase
{
    public function testActiveUserCanPassPreAuthenticationCheck(): void
    {
        $user = (new User())
            ->setEmail('active@example.test')
            ->setFullName('Active Client')
            ->setIsActive(true);

        (new AccountStatusChecker())->checkPreAuth($user);

        self::assertTrue($user->isActive());
    }

    public function testSuspendedUserCannotPassPreAuthenticationCheck(): void
    {
        $user = (new User())
            ->setEmail('suspended@example.test')
            ->setFullName('Suspended Client')
            ->setIsActive(false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('This account has been suspended.');

        (new AccountStatusChecker())->checkPreAuth($user);
    }
}
