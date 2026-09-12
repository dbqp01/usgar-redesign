<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Auth;

use App\Features\Auth\Actions\AuthLoginEmailAction;
use PHPUnit\Framework\TestCase;

final class AuthLoginEmailActionTest extends TestCase {
    public function testOAuthOnlyFailureUsesGeneralErrorEnvelopeAndLegacyMetadata(): void {
        $payload = TestableAuthLoginEmailAction::oauthPayload('Google', 'Use Google to sign in.');

        self::assertFalse($payload['success']);
        self::assertSame([
            'code' => 'OAUTH_ACCOUNT',
            'message' => 'Use Google to sign in.',
        ], $payload['error']);
        self::assertTrue($payload['isOAuth']);
        self::assertSame('Google', $payload['provider']);
        self::assertSame('Use Google to sign in.', $payload['message']);
    }
}

final class TestableAuthLoginEmailAction extends AuthLoginEmailAction {
    /** @return array<string, mixed> */
    public static function oauthPayload(string $provider, string $message): array {
        return parent::oauthAccountPayload($provider, $message);
    }
}
