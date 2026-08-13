<?php
declare(strict_types=1);

namespace App\Features\Cron\Actions;

use App\Core\BookingStatus;
use App\Core\Logger;
use App\Core\PriceCalculator;
use App\Core\Events\EventDispatcher;
use App\Features\Booking\Domain\Events\BookingPaidEvent;
use App\Features\Booking\Domain\ProvisionalBookingRepository;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use PDO;
use Throwable;

/**
 * Accion de reconciliacion de pagos (Cron).
 *
 * Recupera reservas provisionales pendientes cuyo webhook de MercadoPago
 * nunca llego (outbox/comunicacion rota) consultando el estado real del
 * pago en la API de MercadoPago y completando el flujo si esta aprobado.
 *
 * Uso: cron/reconcile_payments.php
 */
class ReconcilePaymentsAction {
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

    /**
     * @return array{checked: int, reconciled: int, skipped: int}
     */
    public function __invoke(): array {
        $checked = 0;
        $reconciled = 0;
        $skipped = 0;

        $holds = $this->bookingRepo->getPendingHoldsWithPaymentId();

        foreach ($holds as $hold) {
            $cartId = (string)($hold['cart_id'] ?? '');
            $paymentId = (string)($hold['payment_id'] ?? '');

            if ($cartId === '' || $paymentId === '') {
                $skipped++;
                continue;
            }

            $checked++;

            if ($this->bookingRepo->isPaymentProcessed($paymentId)) {
                $skipped++;
                Logger::info("ReconcilePaymentsAction: Payment {$paymentId} ya procesado. Skip.");
                continue;
            }

            $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentId);
            if (!$paymentDetails) {
                $skipped++;
                Logger::info("ReconcilePaymentsAction: Payment {$paymentId} no verificable. Sin accion.");
                continue;
            }
            $mpStatus = (string)($paymentDetails['status'] ?? '');

            // FIX 2026-08-13 (clase rechazo): pago final-rechazado/cancelado
            // con webhook nunca llegado -> transicion pending -> failed (mismo
            // guard que el webhook). Antes: skip sin transicion -> el hold
            // quedaba pending hasta el TTL -> el usuario veia "BOOKING
            // EXPIRED" aunque MP habia rechazado.
            if (in_array($mpStatus, ['rejected', 'failed', 'cancelled'], true)) {
                try {
                    $this->pdo->beginTransaction();
                    $this->bookingRepo->updateStatus($cartId, BookingStatus::Failed->value);
                    $this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'rejected');
                    $this->pdo->commit();
                    $reconciled++;
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Logger::error("ReconcilePaymentsAction Error rejected para cart {$cartId}: " . $e->getMessage());
                    continue;
                }
                Logger::info("ReconcilePaymentsAction: Payment {$paymentId} rechazado ({$mpStatus}); hold {$cartId} -> failed.");
                continue;
            }

            if ($mpStatus !== 'approved') {
                $skipped++;
                Logger::info("ReconcilePaymentsAction: Payment {$paymentId} estado '{$mpStatus}'. Sin accion.");
                continue;
            }

            // Validacion de monto: misma logica que el webhook (centavos
            // enteros, price_snapshot_pen congelado al cotizar — todo 32).
            $priceSnapshotPen = $hold['price_snapshot_pen'] ?? null;
            $expectedPenCents = $priceSnapshotPen !== null
                ? (int)round((float)$priceSnapshotPen * 100)
                : (int)round(PriceCalculator::toGatewayPrice((float)($hold['price_snapshot'] ?? 0.0)) * 100);
            $chargedPenCents = (int)round((float)($paymentDetails['transaction_amount'] ?? 0.0) * 100);
            if ($chargedPenCents < $expectedPenCents) {
                Logger::error("ReconcilePaymentsAction ALERTA: monto insuficiente para cart {$cartId} (cobrado={$chargedPenCents} centavos < esperado={$expectedPenCents} centavos)");
                $skipped++;
                continue;
            }

            // FIX 2026-08-11 (auditoría webhooks): hold expirado + pago
            // approved con webhook nunca llegado -> misma semántica que el
            // webhook (rama expired_paid): alerta para resolución manual, NO
            // se revive la reserva (la habitación pudo re-venderse) y NO se
            // despacha el evento (no confirmar el PMS en ese caso).
            $holdStatus = BookingStatus::tryFrom((string)($hold['status'] ?? ''));
            if ($holdStatus === BookingStatus::Expired) {
                try {
                    $this->pdo->beginTransaction();
                    $this->bookingRepo->updateStatus($cartId, BookingStatus::ExpiredPaid->value);
                    $this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'approved');
                    $this->bookingRepo->recordAlert($cartId, $paymentId, 'expired_paid');
                    $this->pdo->commit();
                    $reconciled++;
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Logger::error("ReconcilePaymentsAction Error expired_paid para cart {$cartId}: " . $e->getMessage());
                    continue;
                }
                Logger::error("ReconcilePaymentsAction ALERTA: pago approved {$paymentId} sobre hold expirado {$cartId} (posible reventa). Marcado expired_paid.");
                continue;
            }

            try {
                $this->pdo->beginTransaction();
                $this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value);
                $this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'approved');
                // FIX 2026-08-11: transactional-outbox — el evento se persiste
                // en la MISMA txn (patrón del webhook). Antes: dispatch
                // post-commit con catch silencioso = si el INSERT del outbox
                // fallaba, el pago quedaba paid/processed y el evento
                // (confirmOrder en QloApps) se perdía para siempre.
                $event = BookingPaidEvent::fromHold($cartId, $paymentId, $hold);
                $this->eventDispatcher->dispatch($event);
                $this->pdo->commit();
                $reconciled++;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Logger::error("ReconcilePaymentsAction Error para cart {$cartId}: " . $e->getMessage());
                continue;
            }
        }

        Logger::info("ReconcilePaymentsAction: checked={$checked}, reconciled={$reconciled}, skipped={$skipped}");
        return ['checked' => $checked, 'reconciled' => $reconciled, 'skipped' => $skipped];
    }
}
