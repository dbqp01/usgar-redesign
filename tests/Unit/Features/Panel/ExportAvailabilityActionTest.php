<?php
declare(strict_types=1);

namespace App\Test\Unit\Features\Panel;

use App\Features\Panel\Actions\ExportAvailabilityAction;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    /**
     * Nombres de huesped sin validar de caracteres (formulario web, import o
     * PMS). Excel/Sheets ejecutan celdas que empiezan por = + - @ TAB CR.
     */
    public function testCsvNeutralizaFormulaInjectionEnHuesped(): void {
        [$action, $rows] = $this->actionAndRows();

        ob_start();
        (new \ReflectionMethod($action, 'sendCsv'))->invoke($action, $rows, '2026-08');
        $csv = (string)ob_get_clean();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $this->assertSame('Huesped', fgetcsv($stream, escape: '')[5], 'Cabecera intacta');
        $this->assertSame('Ana Perez', fgetcsv($stream, escape: '')[5], 'Valor seguro intacto');
        foreach (array_slice($this->data()['bookings'], 1) as $booking) {
            $this->assertSame("'" . $booking['guest'], fgetcsv($stream, escape: '')[5]);
        }
        fclose($stream);
    }

    public function testXlsxNoEscribeFormulaEnHuesped(): void {
        [$action, $rows] = $this->actionAndRows();

        ob_start();
        (new \ReflectionMethod($action, 'sendXlsx'))->invoke($action, $rows, $this->data(), '2026-08');
        $xlsx = (string)ob_get_clean();

        $tmp = tempnam(sys_get_temp_dir(), 'usgar-export-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xlsx);
        try {
            $sheet = IOFactory::load($tmp)->getSheetByName('Reservas');
            $this->assertNotNull($sheet);
            $this->assertSame('Ana Perez', $sheet->getCell('F2')->getValue(), 'Valor seguro intacto');
            foreach (range(3, 10) as $rowNum) {
                $cell = $sheet->getCell('F' . $rowNum);
                $this->assertSame(
                    DataType::TYPE_STRING,
                    $cell->getDataType(),
                    'Formula inyectada en XLSX F' . $rowNum . ': ' . $cell->getValue()
                );
            }
            // Las columnas numericas siguen numericas tras sanear.
            $this->assertSame(2, $sheet->getCell('E2')->getValue());
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{0: ExportAvailabilityAction, 1: list<list<string|int|float>>}
     */
    private function actionAndRows(): array {
        $ref = new \ReflectionClass(ExportAvailabilityAction::class);
        $action = $ref->newInstanceWithoutConstructor();
        return [$action, $ref->getMethod('buildRows')->invoke($action, $this->data())];
    }

    /**
     * @return array{month: string, today: string, rooms: list<array<string,mixed>>, bookings: list<array<string,mixed>>}
     */
    private function data(): array {
        $base = [
            'room_id' => 1, 'room' => '101', 'checkin' => '2026-08-01', 'checkout' => '2026-08-03',
            'channel' => 'web', 'status' => 'confirmed', 'price' => 100.0,
        ];
        $guests = [
            'Ana Perez',
            '=HYPERLINK("http://evil.example","x")',
            '+1+1',
            '-2+3',
            '@SUM(1+1)',
            "\t=1+1",
            "\r=1+1",
            "\n=1+1",
            '  =1+1',
        ];
        $bookings = [];
        foreach ($guests as $guest) {
            $bookings[] = $base + ['guest' => $guest];
        }
        return [
            'month' => '2026-08',
            'today' => '2026-08-11',
            'rooms' => [['room_id' => 1, 'room_num' => '101', 'type' => 'Triple Estandar']],
            'bookings' => $bookings,
        ];
    }
}
