<?php
declare(strict_types=1);

namespace App\Features\Auth\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Features\Auth\User;
use App\Features\Auth\SessionService;

/**
 * Acción ADR: GET /api/user/bookings
 * Retorna las reservas del usuario autenticado.
 */
class GetUserBookingsAction {
    public function __invoke(Request $request): void {
        $user = SessionService::getUserFromRequest();

        if ($user === null) {
            Response::error("No hay una sesión activa.", 401);
            return;
        }

        $pdo = Database::getInstance()->getConnection();
        if ($pdo === null) {
            Response::error("Error de base de datos.", 500);
            return;
        }

        $userModel = new User($pdo);
        $bookings = $userModel->getBookings((int)$user['sub']);

        Response::json([
            'success'  => true,
            'user_id'  => $user['sub'],
            'bookings' => $bookings,
        ]);
    }
}
