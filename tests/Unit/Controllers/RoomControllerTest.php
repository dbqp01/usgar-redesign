<?php
declare(strict_types=1);

namespace App\Test\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use App\Controllers\RoomController;
use App\Services\QloAppService;
use App\Core\Request;
use App\Core\HttpException;

/**
 * Pruebas unitarias para RoomController.
 */
final class RoomControllerTest extends TestCase {
    public function testRoomControllerInstantiation(): void {
        $qloAppMock = $this->createMock(QloAppService::class);
        $controller = new RoomController($qloAppMock);
        $this->assertInstanceOf(RoomController::class, $controller);
    }
}
