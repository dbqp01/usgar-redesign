<?php
declare(strict_types=1);

namespace Tests\Unit\Features\Auth;

use App\Features\Auth\User;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase {
    public function testPublicRegistrationCannotTakeOverOAuthAccount(): void {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())
            ->method('exec')
            ->willReturn(0);

        $lookup = $this->createMock(PDOStatement::class);
        $lookup->expects(self::once())->method('execute')->with([':email' => 'oauth@example.com']);
        $lookup->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'id' => 42,
            'email' => 'oauth@example.com',
            'first_name' => 'OAuth',
            'last_name' => 'User',
            'password_hash' => null,
            'phone' => null,
            'photo_url' => null,
            'provider' => 'Google',
            'provider_id' => 'google-42',
        ]);

        $pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE email = :email LIMIT 1')
            ->willReturn($lookup);

        $user = new User($pdo);

        self::assertNull($user->createFromEmail(
            'oauth@example.com',
            'attacker-password',
            'Attacker',
        ));
    }

    public function testOAuthRejectsUnverifiedEmail(): void {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('exec')->willReturn(0);
        $user = new User($pdo);

        self::assertNull($user->createFromOAuth([
            'email' => 'unverified@example.com',
            'email_verified' => false,
            'first_name' => 'Unverified',
            'last_name' => 'User',
            'photo_url' => null,
            'phone' => null,
            'provider' => 'Google',
            'provider_id' => 'google-1',
        ]));
    }

    public function testOAuthDoesNotImplicitlyLinkDifferentProviderIdentity(): void {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('exec')->willReturn(0);

        $lookup = $this->createMock(PDOStatement::class);
        $lookup->expects(self::once())->method('execute')->with([':email' => 'same@example.com']);
        $lookup->expects(self::once())->method('fetch')->with(PDO::FETCH_ASSOC)->willReturn([
            'id' => 7,
            'email' => 'same@example.com',
            'first_name' => 'Existing',
            'last_name' => 'User',
            'password_hash' => null,
            'phone' => null,
            'photo_url' => null,
            'provider' => 'Google',
            'provider_id' => 'google-7',
        ]);

        $pdo->expects(self::once())
            ->method('prepare')
            ->with('SELECT * FROM users WHERE email = :email LIMIT 1')
            ->willReturn($lookup);

        $user = new User($pdo);
        self::assertNull($user->createFromOAuth([
            'email' => 'same@example.com',
            'email_verified' => true,
            'first_name' => 'Attacker',
            'last_name' => 'User',
            'photo_url' => null,
            'phone' => null,
            'provider' => 'Facebook',
            'provider_id' => 'facebook-7',
        ]));
    }
}
