<?php
declare(strict_types=1);

namespace App\Features\Cron\Actions;

use App\Core\Request;
use App\Core\Response;
use App\Core\Logger;
use App\Core\Config;
use App\Core\BookingStatus;
use App\Core\PriceCalculator;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;
use Throwable;

/**
 * Accion ADR: POST /api/cron/manual-review
 * Cron de resolucion de holds en ManualReview/FraudReview (Wave 4, todo 24).
 *
 * - list (default): bookings en manual_review/fraud_review con el contador
 *   de re-despachos (auditoria en payment_alerts, alert_type='redispatch').
 * - redispatch {cart_id}: re-chequea el pago contra la gateway; si el monto
 *   YA coincide -> fraud_review -> paid (guard del todo 9 lo permite) y
 *   re-despacha el evento (outbox -> listeners); si no coincide -> permanece
 *   en revision (NUNCA confirmar el PMS con monto errado). Log de auditoria
 *   por re-despacho (recordAlert + Logger).
 * - expire {cart_id}: libera el hold (status -> 'expired'; FROM-set
 *   manual_review/fraud_review, mismo guard que cleanExpiredCarts) para
 *   resolucion manual tras N re-despachos sin coincidencia (reembolso/manual).
 *
 * Protegido por CRON_SECRET en HTTP (header x-cron-secret o ?secret=), mismo
 * patron que CleanExpiredCartsAction; en CLI (cron real) se omite.
 */
class RetryManualReviewAction {
    private PDO $pdo;
    private PaymentGatewayPortInterface $paymentGateway;
    private ProvisionalBookingRepository $bookingRepo;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        PDO $pdo,
        PaymentGatewayPortInterface $paymentGateway,
        ProvisionalBookingRepository $bookingRepo,
        EventDispatcher $eventDispatcher
    ) {
        $this->pdo = $pdo;
        $this->paymentGateway = $paymentGateway;
        $this->bookingRepo = $bookingRepo;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function __invoke(Request $request): void {
        // En entorno HTTP, exigir validacion de token de cron (excepto CLI).
        if (PHP_SAPI !== 'cli') {
            $cronSecret = Config::get('CRON_SECRET');
            $providedSecret = $request->getHeader('x-cron-secret') ?? $request->getQuery('secret', '');

            if (empty($cronSecret)) {
                Logger::error('RetryManualReviewAction: CRON_SECRET no configurado en servidor.');
                Response::error('Cron secret non-configured.', 500);
                return;
            }
            if (!hash_equals($cronSecret, (string)$providedSecret)) {
                Logger::error('RetryManualReviewAction: Peticion no autorizada al endpoint de cron.');
                Response::unauthorized('Invalid cron secret token.');
                return;
            }
        }

        $body = $request->getBody() ?? [];
        $action = (string)($body['action'] ?? $request->getQuery('action', 'list'));
        $cartId = (string)($body['cart_id'] ?? $request->getQuery('cart_id', ''));

        match ($action) {
            'redispatch' => $this->redispatch($cartId),
            'expire'     => $this->expire($cartId),
            default      => $this->listReview(),
        };
    }

    private function listReview(): void {
        $stmt = $this->pdo->prepare(
            "SELECT pb.cart_id, pb.status, pb.payment_id, pb.price_snapshot,
                    pb.price_snapshot_pen, pb.exchange_rate_snapshot,
                    pb.expires_at, pb.created_at,
                    (SELECT COUNT(*) FROM payment_alerts pa
                     WHERE pa.cart_id = pb.cart_id AND pa.alert_type = 'redispatch') AS redispatch_count
             FROM provisional_bookings pb
             WHERE pb.status IN ('" . BookingStatus::ManualReview->value . "','" . BookingStatus::FraudReview->value . "')
             ORDER BY pb.cart_id ASC"
        );
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        Logger::info("RetryManualReviewAction: listado de revision: " . count($items) . " holds.");
        Response::json(['success' => true, 'items' => $items]);
    }

    /**
     * Re-despacha el evento de un hold en revision: re-chequea el pago contra
     * la gateway; si el monto ya coincide -> paid + dispatch (los listeners
     * corren en el cron del outbox).
     */
    private function redispatch(string $cartId): void {
        if ($cartId === '') {
            Response::error('cart_id requerido.', 400);
            return;
        }

        $hold = $this->bookingRepo->getByCartId($cartId);
        if ($hold === null) {
            Response::error('Hold no encontrado.', 404);
            return;
        }

        $paymentId = (string)($hold['payment_id'] ?? '');
        if ($paymentId === '') {
            Response::error('El hold no tiene payment_id para re-despachar.', 400);
            return;
        }

        // Auditoria de cada re-despacho (todo 24: log de auditoria).
        Logger::info("RetryManualReviewAction: re-despacho para cart {$cartId} (payment {$paymentId}, status {$hold['status']})");

        $paymentDetails = null;
        try {
            $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentId);
        } catch (Throwable $e) {
            Logger::error("RetryManualReviewAction: gateway no disponible para {$paymentId}: " . $e->getMessage());
            Response::error('Gateway no disponible.', 502);
            return;
        }

        $status = (string)($paymentDetails['status'] ?? 'unknown');
        if ($paymentDetails === null || $status !== 'approved') {
            $this->bookingRepo->recordAlert($cartId, $paymentId, 'redispatch');
            Logger::info("RetryManualReviewAction: payment {$paymentId} estado '{$status}' — sin accion.");
            Response::json(['success' => true, 'status' => 'still_pending', 'reason' => 'payment_not_approved']);
            return;
        }

        // Comparacion de monto: misma logica que el webhook (centavos enteros,
        // price_snapshot_pen congelado al cotizar — todo 32).
        $priceSnapshotPen = $hold['price_snapshot_pen'] ?? null;
        $expectedPenCents = $priceSnapshotPen !== null
            ? (int)round((float)$priceSnapshotPen * 100)
            : (int)round(PriceCalculator::toGatewayPrice((float)($hold['price_snapshot'] ?? 0.0)) * 100);
        $chargedPenCents = (int)round((float)($paymentDetails['transaction_amount'] ?? 0.0) * 100);
        $matches = $chargedPenCents >= $expectedPenCents;

        $this->bookingRepo->recordAlert($cartId, $paymentId, 'redispatch');

        if (!$matches) {
            Logger::info("RetryManualReviewAction: cart {$cartId} — monto ({$chargedPenCents} centavos) < esperado ({$expectedPenCents} centavos): permanece en revision.");
            Response::json(['success' => true, 'status' => 'still_fraud_review', 'reason' => 'amount_mismatch']);
            return;
        }

        // NOTA r2: monto ya coincide -> fraud_review -> paid (guard todo 9).
        // FIX 2026-08-11: dispatch DENTRO de la txn (transactional-outbox,
        // patrón del webhook) — antes el evento se insertaba post-commit con
        // catch silencioso: si el outbox fallaba, paid sin sincronizar PMS y
        // sin reintento posible.
        try {
            $this->pdo->beginTransaction();
            $this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value);
            $this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'approved');
            $this->eventDispatcher->dispatch(BookingPaidEvent::fromHold($cartId, $paymentId, $hold));
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            Logger::error("RetryManualReviewAction: error al marcar paid para {$cartId}: " . $e->getMessage());
            Response::error('Error interno al completar el hold.', 500);
            return;
        }

        Logger::info("RetryManualReviewAction: cart {$cartId} completado a paid tras re-despacho (monto coincide).");
        Response::json(['success' => true, 'status' => 'paid', 'message' => 'Fraud review hold completed after redispatch.']);
    }

    /**
     * Libera un hold en revision (status -> expired) para resolucion manual.
     * Mismo FROM-set que cleanExpiredCarts (nunca afecta paid/expired_paid).
     */
    private function expire(string $cartId): void {
        if ($cartId === '') {
            Response::error('cart_id requerido.', 400);
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE provisional_bookings SET status = '" . BookingStatus::Expired->value . "'
             WHERE cart_id = :cart
               AND status IN ('" . BookingStatus::ManualReview->value . "','" . BookingStatus::FraudReview->value . "')"
        );
        $stmt->execute([':cart' => $cartId]);

        if ($stmt->rowCount() === 0) {
            Response::error('No hay hold en manual/fraud review para liberar.', 404);
            return;
        }

        $hold = $this->bookingRepo->getByCartId($cartId);
        $paymentId = (string)($hold['payment_id'] ?? '');
        $this->bookingRepo->recordAlert($cartId, $paymentId, 'expired_manual');
        Logger::info("RetryManualReviewAction: hold {$cartId} expirado para resolucion manual (reembolso/manual).");

        Response::json(['success' => true, 'status' => 'expired', 'message' => 'Hold released for manual resolution.']);
    }
}
