<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;

/**
 * Accion ADR: DELETE /api/panel/block?id=NN
 * Libera un bloqueo (disable_date) del dueño / mantenimiento. NO es una
 * cancelación de reserva: el bloqueo nunca fue una reserva vendida, así que
 * liberarlo no genera cargo ni penalización en ningún canal.
 * Requiere cookie del panel.
 */
class PanelUnblockAction {
    private AvailabilityRepository $repo;

    public function __construct(AvailabilityRepository $repo) {
        $this->repo = $repo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $id = (int)$request->getQuery('id', '0');
        if ($id < 1) {
            Response::badRequest('id de bloqueo requerido.');
        }

        $ok = $this->repo->deleteDisableDate($id);
        if (!$ok) {
            Response::error('Bloqueo no encontrado o ya liberado.', 404, 'BLOCK_NOT_FOUND');
        }

        Response::json([
            'success' => true,
            'id'      => $id,
            'message' => 'Bloqueo liberado; la habitación vuelve a venderse.',
        ]);
    }
}
