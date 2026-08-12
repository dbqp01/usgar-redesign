<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Accion ADR: GET /api/panel/export?format=csv|xlsx&month=YYYY-MM
 * Descarga las reservas del mes en CSV (BOM UTF-8, abre directo en Excel) o
 * XLSX real (PhpSpreadsheet, hoja Reservas + hoja Resumen). Requiere cookie
 * del panel.
 */
class ExportAvailabilityAction {
    private const HEADERS = ['Habitacion', 'Tipo', 'Entrada', 'Salida', 'Noches', 'Huesped', 'Canal', 'Estado', 'Precio USD'];

    private AvailabilityRepository $repo;

    public function __construct(AvailabilityRepository $repo) {
        $this->repo = $repo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $format = (string)$request->getQuery('format', 'csv');
        $month = (string)$request->getQuery('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            Response::badRequest('Formato de mes invalido (esperado YYYY-MM).');
        }
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            Response::badRequest('Formato invalido (csv|xlsx).');
        }

        $hotelId = (int)($request->getQuery('hotel', Config::get('DEFAULT_HOTEL_ID', '1')));
        $data = $this->repo->getMonth($month, $hotelId);
        $rows = $this->buildRows($data);

        if ($format === 'csv') {
            $this->sendCsv($rows, $month);
        }
        $this->sendXlsx($rows, $data, $month);
    }

    /**
     * @param array{month: string, today: string, rooms: list<array<string,mixed>>, bookings: list<array<string,mixed>>} $data
     * @return list<list<string|int|float>>
     */
    private function buildRows(array $data): array {
        $roomInfo = []; // room_num => type
        $roomById = []; // room_id => room (maint viene sin room_num)
        foreach ($data['rooms'] as $r) {
            $roomInfo[(string)$r['room_num']] = (string)$r['type'];
            if (isset($r['room_id'])) {
                $roomById[(int)$r['room_id']] = $r;
            }
        }

        $rows = [];
        foreach ($data['bookings'] as $b) {
            $roomNum = (string)($b['room'] ?? '');
            $type = $roomNum !== '' && isset($roomInfo[$roomNum]) ? $roomInfo[$roomNum] : (string)($b['room'] ?? '');
            // Fuera de servicio: el cuarto fisico llega solo por room_id.
            if ($roomNum === '' && isset($b['room_id']) && isset($roomById[(int)$b['room_id']])) {
                $room = $roomById[(int)$b['room_id']];
                $roomNum = (string)$room['room_num'];
                $type = (string)$room['type'];
            } elseif ($roomNum !== '' && !isset($roomInfo[$roomNum])) {
                // Hold / reserva a nivel de tipo (sin cuarto fisico): el nombre
                // es el tipo, no una habitacion.
                $type = $roomNum;
                $roomNum = 'Sin asignar';
            }
            $checkin = substr((string)$b['checkin'], 0, 10);
            $checkout = substr((string)$b['checkout'], 0, 10);
            $nights = max(0, (int)round((strtotime($checkout) - strtotime($checkin)) / 86400));
            $rows[] = [
                $roomNum !== '' ? $roomNum : 'Sin asignar',
                $type,
                $checkin,
                $checkout,
                $nights,
                (string)$b['guest'],
                $this->channelLabel((string)$b['channel']),
                $b['status'] === 'hold' ? 'Hold' : ($b['status'] === 'maint' ? 'Fuera de servicio' : 'Confirmada'),
                $b['price'] !== null ? (float)$b['price'] : '',
            ];
        }
        return $rows;
    }

    private function channelLabel(string $channel): string {
        return match ($channel) {
            'web'    => 'Web',
            'walkin' => 'Walk-in',
            'ota'    => 'OTA',
            'phone'  => 'Telefono',
            'maint'  => 'Mantenimiento',
            default  => 'PMS',
        };
    }

    /**
     * @param list<list<string|int|float>> $rows
     */
    private function sendCsv(array $rows, string $month): void {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="usgar-reservas-' . $month . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: tildes correctas en Excel
        fputcsv($out, self::HEADERS);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        if (!defined('PHP_TESTING') && Config::get('APP_ENV') !== 'testing') {
            exit(0);
        }
    }

    /**
     * @param list<list<string|int|float>> $rows
     * @param array{month: string, today: string, rooms: list<array<string,mixed>>, bookings: list<array<string,mixed>>} $data
     */
    private function sendXlsx(array $rows, array $data, string $month): void {
        if (ob_get_length()) {
            ob_clean();
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reservas');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $lastRow = count($rows) + 1;
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        $sheet->setAutoFilter('A1:I' . max(1, $lastRow));
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $summary = $spreadsheet->createSheet();
        $summary->setTitle('Resumen');
        $summary->setCellValue('A1', 'Resumen del mes ' . $month);
        $summary->getStyle('A1')->getFont()->setBold(true);
        $nights = 0;
        $revenue = 0.0;
        $byChannel = [];
        foreach ($data['bookings'] as $b) {
            $n = max(0, (int)round((strtotime((string)$b['checkout']) - strtotime((string)$b['checkin'])) / 86400));
            $nights += $n;
            if ($b['price'] !== null) {
                $revenue += (float)$b['price'];
            }
            $key = $this->channelLabel((string)$b['channel']);
            $byChannel[$key] = ($byChannel[$key] ?? 0) + 1;
        }
        $totalRooms = count($data['rooms']);
        $totalRoomNights = $totalRooms * (int)date('t', strtotime($month . '-01'));
        $summary->setCellValue('A3', 'Noches vendidas')->setCellValue('B3', $nights);
        $summary->setCellValue('A4', 'Reservas')->setCellValue('B4', count($data['bookings']));
        $summary->setCellValue('A5', 'Ocupacion %')->setCellValue('B5', $totalRoomNights > 0 ? round(($nights / $totalRoomNights) * 100, 1) : 0);
        $summary->setCellValue('A6', 'Ingreso estimado USD')->setCellValue('B6', round($revenue, 2));
        $rowIdx = 8;
        foreach ($byChannel as $channel => $count) {
            $summary->setCellValue('A' . $rowIdx, $channel)->setCellValue('B' . $rowIdx, $count);
            $rowIdx++;
        }

        $writer = new Xlsx($spreadsheet);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="usgar-reservas-' . $month . '.xlsx"');
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
        if (!defined('PHP_TESTING') && Config::get('APP_ENV') !== 'testing') {
            exit(0);
        }
    }
}
