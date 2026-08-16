<?php
declare(strict_types=1);

namespace App\Features\Panel\Actions;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Features\Panel\Domain\AvailabilityRepository;
use App\Features\Panel\Domain\PanelAuth;

/**
 * Accion ADR: GET /api/panel/rooms-availability?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Disponibilidad por HABITACIÓN FÍSICA en un rango, para el wizard del panel:
 * por tipo de habitación, cada habitación con sus bloques ocupados. El
 * frontend calcula la continuidad (habitación libre TODAS las noches del
 * rango) — detecta los "huecos" que obligarían a cambiar de cuarto a mitad de
 * la estadía. Requiere cookie del panel.
 */
class PanelRoomsAvailabilityAction {
    private AvailabilityRepository $repo;

    public function __construct(AvailabilityRepository $repo) {
        $this->repo = $repo;
    }

    public function __invoke(Request $request): void {
        PanelAuth::requireAuth();

        $from = (string)$request->getQuery('from', '');
        $to = (string)$request->getQuery('to', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
            || strtotime($to) < strtotime($from)) {
            Response::badRequest('Rango inválido (from/to YYYY-MM-DD, to >= from).');
        }

        $hotelId = (int)($request->getQuery('hotel', Config::get('DEFAULT_HOTEL_ID', '1')));
        Response::json($this->repo->getRoomAvailability($from, $to, $hotelId));
    }
}
