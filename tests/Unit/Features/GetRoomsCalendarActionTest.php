<?php
declare(strict_types=1);

namespace App\Test\Unit\Features;

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/../TestCase.php';
}

use App\Core\HttpException;
use App\Core\Request;
use App\Features\Rooms\Actions\GetRoomsCalendarAction;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Features\Shared\PmsUnavailableException;
use PHPUnit\Framework\TestCase;

final class GetRoomsCalendarActionTest extends TestCase {
    /** @dataProvider invalidHotelIds */
    public function testInvalidHotelIdIsRejectedBeforePmsCall(mixed $hotelId): void {
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $pmsMock->expects($this->never())->method('getAvailabilityCalendar');
        $action = new GetRoomsCalendarAction($pmsMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $action(new Request('GET', '/api/rooms/calendar?id_hotel=' . rawurlencode((string)$hotelId)));
    }

    /** @return array<string, array{mixed}> */
    public static function invalidHotelIds(): array {
        return [
            'zero' => [0],
            'negative' => [-1],
            'non integer text' => ['abc'],
            'decimal' => ['1.5'],
        ];
    }

    public function testPmsFailureReturnsServiceUnavailable(): void {
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $pmsMock->expects($this->once())->method('getAvailabilityCalendar')
            ->willThrowException(new PmsUnavailableException('offline'));
        $action = new GetRoomsCalendarAction($pmsMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(503);
        try {
            $action(new Request('GET', '/api/rooms/calendar?from=2099-03-01&to=2099-03-02'));
        } catch (HttpException $e) {
            $this->assertSame('SERVICE_UNAVAILABLE', $e->getErrorCode());
            throw $e;
        }
    }

    public function testHealthyEmptyCalendarReturnsSuccess(): void {
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $pmsMock->expects($this->once())->method('getAvailabilityCalendar')->willReturn([]);
        $action = new GetRoomsCalendarAction($pmsMock);

        ob_start();
        try {
            $action(new Request('GET', '/api/rooms/calendar?from=2099-04-01&to=2099-04-02'));
            $payload = json_decode((string)ob_get_clean(), true);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->assertSame(true, $payload['success']);
        $this->assertSame([], $payload['days']);
    }
}
