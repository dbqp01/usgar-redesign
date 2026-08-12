<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Accion ADR: POST /api/panel/import
 * Importa reservas/bloqueos desde CSV o XLSX (formato: room, checkin,
 * check-out, guest, channel, status, price — con o sin cabecera) y los
 * registra en manual_blocks para bloquear la habitacion en el calendario.
 * Requiere cookie del panel.
 *
 * Body: { "filename": "reservas.xlsx", "content_base64": "..." } o
 *       { "csv": "room,checkin,checkout,..." }
 */
class ImportAvailabilityAction {
    private AvailabilityRepository $repo;

    public function __construct(AvailabilityRepository $repo) {
        $this->repo = $repo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $body = $request->getBody() ?? [];
        $filename = (string)($body['filename'] ?? '');
        $contentB64 = (string)($body['content_base64'] ?? '');
        $csvRaw = (string)($body['csv'] ?? '');

        if ($csvRaw === '' && $contentB64 === '') {
            Response::badRequest('Archivo o CSV requerido.');
        }

        if ($contentB64 !== '') {
            $raw = base64_decode($contentB64, true);
            if ($raw === false) {
                Response::badRequest('Contenido base64 invalido.');
            }
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, ['xlsx', 'xls'], true)) {
                $rows = $this->parseXlsx($raw);
            } else {
                $rows = $this->parseCsv($raw);
            }
        } else {
            $rows = $this->parseCsv($csvRaw);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $i => $row) {
            try {
                $ok = $this->repo->insertManualBlock($row);
            } catch (\Throwable $e) {
                $ok = null;
            }
            if ($ok !== null) {
                $imported++;
            } else {
                $skipped++;
                $errors[] = 'Fila ' . ($i + 2) . ': habitacion no encontrada (' . ($row['room_num'] ?? '?') . ')';
            }
        }

        Response::json([
            'success'  => true,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => array_slice($errors, 0, 20),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parseCsv(string $raw): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $out = [];
        $start = 0;
        if (isset($lines[0])) {
            $first = str_getcsv($lines[0]);
            $c0 = strtolower(trim((string)($first[0] ?? '')));
            if (in_array($c0, ['room', 'habitacion', 'habitación', 'cuarto'], true)) {
                $start = 1;
            }
        }
        for ($i = $start; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }
            $c = str_getcsv($lines[$i]);
            if (count($c) < 3) {
                continue;
            }
            $row = $this->normalizeRow($c);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function parseXlsx(string $raw): array {
        $tmp = tempnam(sys_get_temp_dir(), 'usgimp');
        if ($tmp === false) {
            Response::error('No se pudo crear archivo temporal.', 500);
        }
        file_put_contents($tmp, $raw);

        try {
            $reader = new Xlsx();
            $sheet = $reader->load($tmp)->getActiveSheet();
            $all = $sheet->toArray(null, true, false, false) ?: [];
        } finally {
            @unlink($tmp);
        }

        $out = [];
        foreach ($all as $i => $c) {
            if ($i === 0) {
                $c0 = strtolower(trim((string)($c[0] ?? '')));
                if (in_array($c0, ['room', 'habitacion', 'habitación', 'cuarto'], true)) {
                    continue;
                }
            }
            if (count($c) < 3 || trim((string)($c[0] ?? '')) === '') {
                continue;
            }
            $row = $this->normalizeRow($c);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param list<mixed> $c
     * @return array<string,mixed>|null
     */
    private function normalizeRow(array $c): ?array {
        $roomNum = trim((string)($c[0] ?? ''));
        $checkin = $this->parseDate($c[1] ?? '');
        $checkout = $this->parseDate($c[2] ?? '');
        if ($roomNum === '' || $checkin === null || $checkout === null || strtotime($checkout) <= strtotime($checkin)) {
            return null;
        }

        $guest = trim((string)($c[3] ?? ''));
        if ($guest === '') {
            $guest = 'Huesped';
        }
        $channel = strtolower(trim((string)($c[4] ?? 'walkin')));
        if (!in_array($channel, ['web', 'walkin', 'ota', 'phone'], true)) {
            $channel = 'walkin';
        }
        $status = strtolower(trim((string)($c[5] ?? 'confirmed')));
        if ($status !== 'hold') {
            $status = 'confirmed';
        }
        $price = trim((string)($c[6] ?? ''));
        $notes = trim((string)($c[7] ?? ''));

        return [
            'room_num'  => $roomNum,
            'checkin'   => $checkin,
            'checkout'  => $checkout,
            'guest_name' => $guest,
            'channel'   => $channel,
            'status'    => $status,
            'price'     => $price !== '' && is_numeric($price) ? (float)$price : null,
            'notes'     => $notes !== '' ? $notes : null,
        ];
    }

    private function parseDate(mixed $value): ?string {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $ts = strtotime((string)$value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }
}
