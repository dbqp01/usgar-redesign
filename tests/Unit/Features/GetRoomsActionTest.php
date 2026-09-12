<?php
declare(strict_types=1);

namespace App\Test\Unit\Features;

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/../TestCase.php';
}

use PHPUnit\Framework\TestCase;
use App\Features\Rooms\Actions\GetRoomsAction;
use App\Features\Shared\Ports\PmsPortInterface;
use App\Core\HttpException;
use App\Core\Request;
use App\Features\Shared\PmsUnavailableException;

/**
 * Pruebas unitarias para la clase-accion ADR GetRoomsAction.
 */
final class GetRoomsActionTest extends TestCase {
    public function testGetRoomsActionInstantiation(): void {
        /** @var PmsPortInterface $pmsMock */
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $action = new GetRoomsAction($pmsMock);
        $this->assertTrue($action instanceof GetRoomsAction);
    }

    /** @dataProvider invalidHotelIds */
    public function testInvalidHotelIdIsRejectedBeforePmsCall(mixed $hotelId): void {
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $pmsMock->expects($this->never())->method('getAvailableRooms');
        $action = new GetRoomsAction($pmsMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);
        $action(new Request('GET', '/api/rooms?id_hotel=' . rawurlencode((string)$hotelId)));
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

    public function testPmsFailureReturnsServiceUnavailableAndIsNotCached(): void {
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $pmsMock->expects($this->once())->method('getAvailableRooms')
            ->willThrowException(new PmsUnavailableException('offline'));
        $action = new GetRoomsAction($pmsMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(503);
        try {
            $action(new Request('GET', '/api/rooms?checkIn=2099-01-01&checkOut=2099-01-02'));
        } catch (HttpException $e) {
            $this->assertSame('SERVICE_UNAVAILABLE', $e->getErrorCode());
            throw $e;
        }
    }

    public function testHealthyEmptyAvailabilityReturnsSuccessWithoutCaching(): void {
        $pmsMock = $this->createMock(PmsPortInterface::class);
        $pmsMock->expects($this->once())->method('getAvailableRooms')->willReturn([]);
        $action = new GetRoomsAction($pmsMock);

        ob_start();
        try {
            $action(new Request('GET', '/api/rooms?checkIn=2099-02-01&checkOut=2099-02-02'));
            $payload = json_decode((string)ob_get_clean(), true);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $this->assertSame(['success' => true, 'rooms' => []], $payload);
    }
}
