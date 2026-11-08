<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegistrationFormIncludesRequiredAccountFields(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="registration_form[fullName]"]');
        self::assertSelectorExists('input[name="registration_form[email]"]');
        self::assertSelectorExists('input[name="registration_form[plainPassword]"]');
        self::assertSelectorExists('input[name="registration_form[agreeTerms]"]');
        self::assertSelectorCount(0, 'input[name="registration_form[role]"]');
        self::assertSame(1, $crawler->filter('form.registration-form')->count());
    }

}
