<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Panel;

use App\Features\Panel\Actions\ExportAvailabilityAction;
use PHPUnit\Framework\TestCase;

class ExportAvailabilityActionTest extends TestCase {
    public function testBuildRowsResuelveHabitacionYTipo(): void {
        $data = [
            'month' => '2026-08',
            'today' => '2026-08-11',
            'rooms' => [
                ['room_id' => 1, 'room_num' => '101', 'type' => 'Triple Estandar'],
                ['room_id' => 2, 'room_num' => '102', 'type' => 'Matrimonial'],
            ],
            'bookings' => [
                // Confirmada con cuarto fisico: room_num resuelto al tipo.
                ['room_id' => 1, 'room' => '101', 'checkin' => '2026-08-01', 'checkout' => '2026-08-03', 'guest' => 'A', 'channel' => 'web', 'status' => 'confirmed', 'price' => 100.0],
                // Hold sin cuarto asignado: room = nombre del tipo, no una habitacion.
                ['room_id' => null, 'room' => 'Triple Estandar', 'checkin' => '2026-08-05', 'checkout' => '2026-08-08', 'guest' => 'B', 'channel' => 'web', 'status' => 'hold', 'price' => 300.0],
                // Fuera de servicio: room_id real sin room_num (resuelto por roomById).
                ['room_id' => 2, 'room' => null, 'checkin' => '2026-08-10', 'checkout' => '2026-08-11', 'guest' => 'Mantenimiento', 'channel' => 'maint', 'status' => 'maint', 'price' => null],
            ],
        ];

        $ref = new \ReflectionClass(ExportAvailabilityAction::class);
        $action = $ref->newInstanceWithoutConstructor();
        $buildRows = $ref->getMethod('buildRows');
        $rows = $buildRows->invoke($action, $data);

        $this->assertCount(3, $rows);
        $this->assertSame('101', $rows[0][0]);
        $this->assertSame('Triple Estandar', $rows[0][1]);
        $this->assertSame('Sin asignar', $rows[1][0]);
        $this->assertSame('Triple Estandar', $rows[1][1]);
        $this->assertSame(3, $rows[1][4]); // noches 05->08
        $this->assertSame('102', $rows[2][0]);
        $this->assertSame('Matrimonial', $rows[2][1]);
    }
}
