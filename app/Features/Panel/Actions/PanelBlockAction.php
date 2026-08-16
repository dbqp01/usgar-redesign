<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;

/**
 * Accion ADR: POST /api/panel/block
 * Bloquea una habitación FÍSICA (dueño / mantenimiento) en
 * qlo_htl_room_disable_dates — la tabla NATIVA de QloApps para "no vendible".
 * Descuenta disponibilidad web (adapter suma disable_dates) y se ve en el CMS.
 * Requiere cookie del panel.
 *
 * Body: { room_id, date_from, date_to, reason? }
 */
class PanelBlockAction {
    private AvailabilityRepository $repo;

    public function __construct(AvailabilityRepository $repo) {
        $this->repo = $repo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $body = $request->getBody() ?? [];
        $roomId = (int)($body['room_id'] ?? 0);
        $dateFrom = (string)($body['date_from'] ?? '');
        $dateTo = (string)($body['date_to'] ?? '');
        $reason = (string)($body['reason'] ?? '');

        if ($roomId < 1) {
            Response::badRequest('Habitación requerida.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            Response::badRequest('Formato de fecha inválido (YYYY-MM-DD).');
        }
        if (strtotime($dateTo) < strtotime($dateFrom)) {
            Response::badRequest('date_to debe ser >= date_from.');
        }

        $id = $this->repo->insertDisableDate([
            'room_id'   => $roomId,
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'reason'    => $reason,
        ]);

        if ($id === null) {
            Response::error('No se pudo bloquear: habitación no encontrada.', 400, 'ROOM_NOT_FOUND');
        }

        Response::json([
            'success' => true,
            'id'      => $id,
            'message' => 'Habitación bloqueada (no vendible en la web ni el CMS).',
        ]);
    }
}
