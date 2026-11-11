<?php

declare(strict_types=1);

namespace App\Security;

final class CredentialSecurityPolicy
{
    /**
     * Common disposable or throwaway email domains blocked by strict security policy.
     */
    private const BLOCKED_EMAIL_DOMAINS = [
        '10minutemail.com',
        'dispostable.com',
        'fakeinbox.com',
        'getnada.com',
        'guerrillamail.com',
        'maildrop.cc',
        'mailinator.com',
        'sharklasers.com',
        'temp-mail.org',
        'tempmail.com',
        'throwawaymail.com',
        'trashmail.com',
        'yopmail.com',
    ];

    private const WEAK_PASSWORDS = [
        'password123!',
        'password1234!',
        'admin123!',
        'admin1234!',
        'welcome123!',
        'qwerty123!',
        '12345678a!',
        'apexmarine1!',
    ];

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Validates a username under strict rules:
     * - 3 to 50 characters
     * - No whitespace
     * - Letters, numbers, underscores, dots, and hyphens only
     * - Must start and end with a letter or number
     */
    public static function validateUsername(string $username): ?string
    {
        $trimmed = trim($username);
        if ($trimmed === '') {
            return 'Please enter a username.';
        }

        if (preg_match('/\s/', $trimmed) === 1) {
            return 'Username cannot contain spaces.';
        }

        if (strlen($trimmed) < 3 || strlen($trimmed) > 50) {
            return 'Username must be between 3 and 50 characters long.';
        }

        if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9_.-]*[a-zA-Z0-9])?$/', $trimmed) !== 1) {
            return 'Username may only contain letters, numbers, underscores, dots, and hyphens, and must start and end with a letter or number.';
        }

        return null;
    }

    /**
     * Validates an email address under strict formatting and domain rules.
     * Returns null if valid, or a descriptive error message if invalid.
     */
    public static function validateEmail(string $email): ?string
    {
        $raw = trim($email);
        if ($raw === '') {
            return 'Please enter an email address.';
        }

        if (preg_match('/\s/', $raw) === 1) {
            return 'Email address cannot contain spaces.';
        }

        $normalized = self::normalizeEmail($raw);

        if (strlen($normalized) < 6 || strlen($normalized) > 100) {
            return 'Email address must be between 6 and 100 characters long.';
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false || !str_contains($normalized, '@')) {
            return 'Please enter a valid email address (for example, name@company.com).';
        }

        [$localPart, $domainPart] = explode('@', $normalized, 2);

        if ($localPart === '' || strlen($localPart) > 64) {
            return 'The username portion of the email address is invalid.';
        }

        if (
            str_starts_with($localPart, '.')
            || str_ends_with($localPart, '.')
            || str_contains($localPart, '..')
            || preg_match('/^[a-z0-9](?:[a-z0-9._%+-]*[a-z0-9])?$/', $localPart) !== 1
        ) {
            return 'Email username must start and end with a letter or number and cannot contain consecutive dots.';
        }

        if (
            $domainPart === ''
            || str_contains($domainPart, '..')
            || !str_contains($domainPart, '.')
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domainPart) !== 1
        ) {
            return 'Email domain must be a valid domain name with a complete top-level domain (such as .com or .ph).';
        }

        if (in_array($domainPart, self::BLOCKED_EMAIL_DOMAINS, true)) {
            return 'Disposable or temporary email addresses are not permitted.';
        }

        return null;
    }

    /**
     * Validates a password against strict complexity requirements:
     * - 8 to 128 characters
     * - No whitespace
     * - At least 1 uppercase letter (A-Z)
     * - At least 1 lowercase letter (a-z)
     * - At least 1 digit (0-9)
     * - At least 1 special character
     * - Must not contain the email local-part or match common weak passwords
     *
     * Returns null if valid, or a descriptive error message if invalid.
     */
    public static function validatePassword(string $password, ?string $email = null): ?string
    {
        if ($password === '') {
            return 'Please enter a password.';
        }

        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }

        if (strlen($password) > 128) {
            return 'Password cannot exceed 128 characters.';
        }

        if (preg_match('/\s/', $password) === 1) {
            return 'Password cannot contain spaces.';
        }

        if (preg_match('/[A-Z]/', $password) !== 1) {
            return 'Password must contain at least one uppercase letter (A-Z).';
        }

        if (preg_match('/[a-z]/', $password) !== 1) {
            return 'Password must contain at least one lowercase letter (a-z).';
        }

        if (preg_match('/[0-9]/', $password) !== 1) {
            return 'Password must contain at least one number (0-9).';
        }

        if (preg_match('/[^a-zA-Z0-9\s]/', $password) !== 1) {
            return 'Password must contain at least one special character (for example: ! @ # $ % ^ & *).';
        }

        if (in_array(strtolower($password), self::WEAK_PASSWORDS, true)) {
            return 'This password is too common. Please choose a stronger, unique password.';
        }

        if ($email !== null && trim($email) !== '') {
            $localPart = strtolower(explode('@', trim($email))[0] ?? '');
            if (strlen($localPart) >= 3 && str_contains(strtolower($password), $localPart)) {
                return 'Password cannot contain your email username.';
            }
        }

        return null;
    }
}
