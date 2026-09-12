<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Auth;

use App\Core\Config;
use App\Features\Auth\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthServiceProfileTest extends TestCase
{
    public function testNormalizeProfileUsesProviderEmailVerification(): void
    {
        $profile = (object)[
            'email' => 'user@example.com',
            'emailVerified' => 'user@example.com',
            'firstName' => 'User',
            'lastName' => 'Example',
            'photoURL' => null,
            'phone' => null,
            'identifier' => 'google-1',
        ];

        self::assertTrue(AuthService::normalizeProfile($profile, 'Google')['email_verified']);

        $profile->emailVerified = '';
        self::assertFalse(AuthService::normalizeProfile($profile, 'Google')['email_verified']);
    }

    public function testMicrosoftTrustFlagIsRequiredToEnableProvider(): void
    {
        Config::set('MICROSOFT_CLIENT_ID', 'id');
        Config::set('MICROSOFT_CLIENT_SECRET', 'secret');
        Config::set('MICROSOFT_TRUST_EMAIL', 'false');
        self::assertArrayNotHasKey('MicrosoftGraph', AuthService::getConfig()['providers']);

        Config::set('MICROSOFT_TRUST_EMAIL', 'true');
        self::assertArrayHasKey('MicrosoftGraph', AuthService::getConfig()['providers']);
    }
}
