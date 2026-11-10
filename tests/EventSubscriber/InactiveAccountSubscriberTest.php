<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\InactiveAccountSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class InactiveAccountSubscriberTest extends TestCase
{
    public function testSuspendedAuthenticatedUserIsLoggedOutAndRedirected(): void
    {
        $tokenStorage = new TokenStorage();
        $user = (new User())
            ->setEmail('suspended@example.test')
            ->setFullName('Suspended Client')
            ->setIsActive(false);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_login')
            ->willReturn('/login');

        $request = Request::create('/client/dashboard');
        $session = new Session(new MockArraySessionStorage());
        $session->set('user_id', 10);
        $session->set('role', 'client');
        $request->setSession($session);
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new InactiveAccountSubscriber($tokenStorage, $urlGenerator))->checkAccountStatus($event);

        self::assertSame('/login', $event->getResponse()?->headers->get('Location'));
        self::assertNull($tokenStorage->getToken());
        self::assertFalse($session->has('user_id'));
        self::assertSame(['This account has been suspended.'], $session->getFlashBag()->get('danger'));
    }

    public function testActiveAuthenticatedUserIsNotInterrupted(): void
    {
        $tokenStorage = new TokenStorage();
        $user = (new User())
            ->setEmail('active@example.test')
            ->setFullName('Active Client')
            ->setIsActive(true);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->setToken($token);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::never())->method('generate');
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/client/dashboard'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new InactiveAccountSubscriber($tokenStorage, $urlGenerator))->checkAccountStatus($event);

        self::assertNull($event->getResponse());
        self::assertSame($token, $tokenStorage->getToken());
    }
}
