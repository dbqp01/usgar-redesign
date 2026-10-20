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
            $claimToken = (string)($hold['reconcile_claim_token'] ?? '');

            if ($cartId === '' || $paymentId === '') {
                $skipped++;
                continue;
            }

            $checked++;
            $updateStatus = function (string $status) use ($cartId, $claimToken): bool {
                return $claimToken !== ''
                    ? $this->bookingRepo->updateStatusWithReconcileClaim($cartId, $status, $claimToken)
                    : $this->bookingRepo->updateStatus($cartId, $status);
            };

            try {
                if ($this->bookingRepo->isPaymentProcessed($paymentId)) {
                    // The payment is terminal/idempotent; do not retain the
                    // ten-minute lease and unnecessarily delay the next scan.
                    $this->bookingRepo->releaseReconcileClaim($cartId, $claimToken);
                    $skipped++;
                    Logger::info("ReconcilePaymentsAction: Payment {$paymentId} ya procesado. Skip.");
                    continue;
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $skipped++;
                Logger::error("ReconcilePaymentsAction: no se pudo consultar idempotencia para payment {$paymentId}: " . $e->getMessage());
                continue;
            }

            try {
                $paymentDetails = $this->paymentGateway->getPaymentDetails($paymentId);
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $skipped++;
                Logger::error("ReconcilePaymentsAction: no se pudo consultar payment {$paymentId}: " . $e->getMessage());
                continue;
            }
            if (!$paymentDetails) {
                $skipped++;
                Logger::info("ReconcilePaymentsAction: Payment {$paymentId} no verificable. Sin accion.");
                continue;
            }
            $mpStatus = (string)($paymentDetails['status'] ?? '');

            // Pago final-rechazado/cancelado con webhook nunca llegado ->
            // transicion pending -> failed (mismo guard que el webhook). Sin
            // esta transicion el hold quedaba pending hasta el TTL y el
            // usuario veia "BOOKING EXPIRED" aunque MP habia rechazado.
            if (in_array($mpStatus, ['rejected', 'failed', 'cancelled'], true)) {
                try {
                    $this->pdo->beginTransaction();
                    if (!$updateStatus(BookingStatus::Failed->value)
                        || !$this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'rejected')
                        || ($mpStatus === 'cancelled'
                            && !$this->bookingRepo->recordAlert($cartId, $paymentId, 'cancelled'))) {
                        throw new \RuntimeException('No se pudo persistir la reconciliación del rechazo.');
                    }
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

            // Hold expirado + pago approved se resuelve antes de validar el
            // monto, igual que el webhook: no se revive ni se convierte en
            // fraud_review una reserva que ya pudo ser revendida.
            $holdStatus = BookingStatus::tryFrom((string)($hold['status'] ?? ''));

            // Validacion de monto: misma logica que el webhook (centavos
            // enteros, price_snapshot_pen congelado al cotizar).
            $priceSnapshotPen = $hold['price_snapshot_pen'] ?? null;
            $expectedPenCents = $priceSnapshotPen !== null
                ? (int)round((float)$priceSnapshotPen * 100)
                : (int)round(PriceCalculator::toGatewayPrice((float)($hold['price_snapshot'] ?? 0.0)) * 100);
            $chargedPenCents = (int)round((float)($paymentDetails['transaction_amount'] ?? 0.0) * 100);
            if ($holdStatus !== BookingStatus::Expired && $chargedPenCents < $expectedPenCents) {
                Logger::error("ReconcilePaymentsAction ALERTA: monto insuficiente para cart {$cartId} (cobrado={$chargedPenCents} centavos < esperado={$expectedPenCents} centavos)");
                try {
                    $this->pdo->beginTransaction();
                    if ($this->bookingRepo->isPaymentProcessed($paymentId, 'fraud_review')) {
                        $this->pdo->commit();
                        $skipped++;
                        continue;
                    }
                    if (!$updateStatus(BookingStatus::FraudReview->value)
                        || !$this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'fraud_review')
                        || !$this->bookingRepo->recordAlert($cartId, $paymentId, 'fraud_review')) {
                        throw new \RuntimeException('No se pudo persistir la revisión antifraude.');
                    }
                    $this->pdo->commit();
                    $reconciled++;
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    Logger::error("ReconcilePaymentsAction Error fraud_review para cart {$cartId}: " . $e->getMessage());
                }
                continue;
            }

            // Hold expirado + pago approved con webhook nunca llegado ->
            // misma semántica que el webhook (rama expired_paid): alerta para
            // resolución manual, NO se revive la reserva (la habitación pudo
            // re-venderse) y NO se despacha el evento (no confirmar el PMS).
            if ($holdStatus === BookingStatus::Expired) {
                try {
                    $this->pdo->beginTransaction();
                    if (!$updateStatus(BookingStatus::ExpiredPaid->value)
                        || !$this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'approved')
                        || !$this->bookingRepo->recordAlert($cartId, $paymentId, 'expired_paid')) {
                        throw new \RuntimeException('No se pudo persistir la reconciliación del pago tardío.');
                    }
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
                if (!$updateStatus(BookingStatus::Paid->value)
                    || !$this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'approved')) {
                    throw new \RuntimeException('No se pudo persistir la reconciliación del pago aprobado.');
                }
                // Transactional-outbox: el evento se persiste en la MISMA txn
                // (patrón del webhook). Un dispatch post-commit con catch
                // silencioso perdia el evento (confirmOrder en QloApps) para
                // siempre si el INSERT del outbox fallaba.
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
