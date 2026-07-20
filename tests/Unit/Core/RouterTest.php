<?php
declare(strict_types=1);

namespace App\Test\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\Router;
use App\Core\Request;
use App\Core\Response;
use App\Core\HttpException;
use App\Core\BookingStatus;
use App\Core\Validator;

/**
 * Pruebas unitarias de las clases Core del backend PHP.
 */
final class RouterTest extends TestCase {
    public function testBookingStatusEnumValues(): void {
        $pending = BookingStatus::Pending;
        $this->assertTrue($pending->isExtendable());
        $this->assertFalse($pending->isTerminal());

        $paid = BookingStatus::Paid;
        $this->assertFalse($paid->isExtendable());
        $this->assertTrue($paid->isTerminal());
    }

    public function testValidatorRequireFieldsSuccess(): void {
        $this->expectNotToPerformAssertions();
        Validator::requireFields(['checkIn' => '2026-08-01', 'checkOut' => '2026-08-05'], ['checkIn', 'checkOut']);
    }

    public function testValidatorRequireFieldsThrowsOnMissing(): void {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        Validator::requireFields(['checkIn' => '2026-08-01'], ['checkIn', 'checkOut']);
    }

    public function testValidatorDateRangeThrowsOnInvertedDates(): void {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        Validator::dateRange('2026-08-05', '2026-08-01');
    }
}
