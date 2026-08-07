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
            if (!$paymentDetails || ($paymentDetails['status'] ?? '') !== 'approved') {
                $skipped++;
                Logger::info("ReconcilePaymentsAction: Payment {$paymentId} estado '"
                    . ($paymentDetails['status'] ?? 'unknown') . "'. Sin accion.");
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

            try {
                $this->pdo->beginTransaction();
                $this->bookingRepo->updateStatus($cartId, BookingStatus::Paid->value);
                $this->bookingRepo->markPaymentProcessed($paymentId, $cartId, 'approved');
                $this->pdo->commit();
                $reconciled++;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                Logger::error("ReconcilePaymentsAction Error para cart {$cartId}: " . $e->getMessage());
                continue;
            }

            $event = BookingPaidEvent::fromHold($cartId, $paymentId, $hold);

            try {
                $this->eventDispatcher->dispatch($event);
            } catch (Throwable $e) {
                Logger::error("ReconcilePaymentsAction: Fallo al despachar evento para cart {$cartId}: " . $e->getMessage());
            }
        }

        Logger::info("ReconcilePaymentsAction: checked={$checked}, reconciled={$reconciled}, skipped={$skipped}");
        return ['checked' => $checked, 'reconciled' => $reconciled, 'skipped' => $skipped];
    }
}
