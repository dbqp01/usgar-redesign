<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config;
use PHPUnit\Framework\TestCase;

/**
 * P3-4 (2026-08-12): BOOKING_HOLD_TTL invalido pasaba a strtotime -> false ->
 * date() producia 1970-01-01 y el hold expiraba al instante. El helper
 * fallback a +15 min con log. $ttl inyectable para testeo hermetico.
 */
final class ConfigHoldTtlTest extends TestCase {
    public function testValidRelativeTtlReturnsFutureTimestamp(): void {
        $ts = Config::holdExpirationTimestamp('+15 minutes');
        $this->assertGreaterThan(time(), $ts);
        // 15 min = 900s con tolerancia de ejecucion.
        $this->assertLessThanOrEqual(time() + 910, $ts);
    }

    public function testInvalidTtlFallsBackToFifteenMinutes(): void {
        $before = time();
        $ts = Config::holdExpirationTimestamp('no-es-una-fecha');
        $this->assertGreaterThanOrEqual($before + 900, $ts);
        $this->assertLessThanOrEqual($before + 910, $ts);
    }
}
