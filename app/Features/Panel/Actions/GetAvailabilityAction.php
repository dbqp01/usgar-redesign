<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;

/**
 * Accion ADR: GET /api/panel/availability?month=YYYY-MM
 * Grid del mes para el calendario del dueno (habitaciones + reservas + holds
 * + bloqueos manuales + mantenimiento). Requiere cookie del panel.
 */
class GetAvailabilityAction {
    private AvailabilityRepository $repo;

    public function __construct(AvailabilityRepository $repo) {
        $this->repo = $repo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $month = (string)$request->getQuery('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            Response::badRequest('Formato de mes invalido (esperado YYYY-MM).');
        }

        $hotelId = (int)($request->getQuery('hotel', Config::get('DEFAULT_HOTEL_ID', '1')));
        Response::json($this->repo->getMonth($month, $hotelId));
    }
}
