<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class PublicSecurityHeadersTest extends TestCase {
    private function htaccess(): string {
        $path = dirname(__DIR__, 3) . '/public/.htaccess';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        return $contents;
    }

    public function testStaticResponsesRemoveRuntimeFingerprintAndSetNosniff(): void {
        $htaccess = $this->htaccess();
        self::assertStringContainsString('Header always unset X-Powered-By', $htaccess);
        self::assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $htaccess);
    }

    public function testStaticHtmlReceivesDefensiveHeaders(): void {
        $htaccess = $this->htaccess();
        self::assertStringContainsString('<FilesMatch "(?i)\\.html$">', $htaccess);
        self::assertStringContainsString('Header always set X-Frame-Options "DENY"', $htaccess);
        self::assertStringContainsString('Header always set Referrer-Policy "strict-origin-when-cross-origin"', $htaccess);
        self::assertStringContainsString('Header always set Permissions-Policy', $htaccess);
        self::assertStringContainsString('Header always set Strict-Transport-Security', $htaccess);
    }

    public function testPanelShellIsNeverStored(): void {
        $htaccess = $this->htaccess();
        self::assertStringContainsString('SetEnvIf Request_URI "^/panel(?:/|$)" USGAR_PANEL=1', $htaccess);
        self::assertStringContainsString('Header always set Cache-Control "private, no-store, max-age=0, must-revalidate" env=USGAR_PANEL', $htaccess);
    }
}
