<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

/**
 * P1-2 (2026-08-12): el parseo de .env truncaba valores con " #"
 * (ej. DB_PASS="ab #cd" -> "ab"). parseLine es la unidad testeable.
 */
final class ConfigParseTest extends TestCase {
    public function testInlineCommentStrippedWhenUnquoted(): void {
        $this->assertSame(
            ['key' => 'ALLOWED_ORIGINS', 'value' => '*'],
            Config::parseLine('ALLOWED_ORIGINS=*       # En prod: https://usgarhoteles.com')
        );
    }

    public function testQuotedValueWithHashKeepsFullContent(): void {
        $this->assertSame(
            ['key' => 'DB_PASS', 'value' => 'ab #cd'],
            Config::parseLine('DB_PASS="ab #cd"')
        );
    }

    public function testQuotedSingleValueWithHashKeepsFullContent(): void {
        $this->assertSame(
            ['key' => 'DB_PASS', 'value' => 'abc #123'],
            Config::parseLine("DB_PASS='abc #123'")
        );
    }

    public function testPlainValueWithoutComment(): void {
        $this->assertSame(
            ['key' => 'DB_HOST', 'value' => '127.0.0.1'],
            Config::parseLine('DB_HOST=127.0.0.1')
        );
    }

    public function testCommentLineReturnsNull(): void {
        $this->assertNull(Config::parseLine('# solo comentario'));
        $this->assertNull(Config::parseLine(''));
    }

    public function testLineWithoutEqualsReturnsNull(): void {
        $this->assertNull(Config::parseLine('SIN_IGUAL'));
    }
}
