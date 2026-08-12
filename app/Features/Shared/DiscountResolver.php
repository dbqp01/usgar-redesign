<?php
declare(strict_types=1);

namespace App\Features\Shared;

/**
 * Resolucion de la tarifa No Reembolsable desde QloApps (Feature Price Plan).
 *
 * Centralizacion pedida por el negocio: el descuento se configura en el admin
 * de QloApps (Hotel Reservation -> Settings -> Feature Price) y el backend lo
 * RESUELVE al cotizar — el frontend solo muestra rate_plans y envia rateType.
 *
 * VERIFICADO contra la BD real (2026-08-10) y el codigo fuente de QloApps
 * (modules/hotelreservationsystem/classes/HotelRoomTypeFeaturePricing.php y
 * AdminHotelFeaturePricesSettings.php):
 *  - Los planes maestros viven en `qlo_htl_room_type_feature_pricing` con
 *    id_cart = 0 AND id_guest = 0 AND id_room = 0 (las filas con cart > 0 son
 *    materializaciones por reserva que QloApps crea al aplicar el plan).
 *  - impact_way: 1=DECREASE, 2=INCREASE, 3=FIX_PRICE.
 *  - impact_type: 1=PERCENTAGE, 2=FIXED_PRICE.
 *  - Las restricciones de fechas viven en
 *    `qlo_htl_room_type_feature_pricing_restriction` (date_from/date_to,
 *    date_selection_type, special_days). Plan sin restricciones = permanente.
 *  - NO existe qlo_catalog_price_rule en esta instalacion (era PrestaShop
 *    clasico): ese mecanismo se descarto con evidencia.
 *  - QloApps NO tiene tarifas "no reembolsables" nativas: la politica es
 *    contractual; el refund se procesa manualmente en back-office.
 *
 * Limite documentado (ponytail): se resuelven planes con restriccion de RANGO
 * (date_selection_type=1) sin special_days, y si hay varios planes aplicables
 * se usa el de menor id (la prioridad global HTL_FEATURE_PRICING_PRIORITY de
 * QloApps no se replica). El caso real del negocio es UN plan permanente.
 */
final class DiscountResolver {

    public const IMPACT_WAY_DECREASE = 1;
    public const IMPACT_WAY_INCREASE = 2;
    public const IMPACT_WAY_FIX_PRICE = 3;
    public const IMPACT_TYPE_PERCENTAGE = 1;
    public const IMPACT_TYPE_FIXED_PRICE = 2;
    public const DATE_SELECTION_TYPE_RANGE = 1;

    /**
     * Elige el plan aplicable de un lote de planes maestros (ya filtrados por
     * producto/activa). Un plan aplica si NO tiene restricciones o si su
     * restriccion de rango intersecta la estadia. Varios aplicables => menor id.
     *
     * @param list<array<string, mixed>> $plans planes maestros del producto (id_feature_price => fila)
     * @param array<int, list<array<string, mixed>>> $restrictionsByPlan id_feature_price => restricciones
     * @return array<string, mixed>|null
     */
    public static function pickPlan(array $plans, array $restrictionsByPlan, string $checkIn, string $checkOut): ?array {
        $applicable = array_values(array_filter($plans, static function (array $p) use ($restrictionsByPlan, $checkIn, $checkOut): bool {
            $restrictions = $restrictionsByPlan[(int)($p['id_feature_price'] ?? 0)] ?? [];
            foreach ($restrictions as $r) {
                $type = (int)($r['date_selection_type'] ?? self::DATE_SELECTION_TYPE_RANGE);
                $specialDays = trim((string)($r['special_days'] ?? ''));
                // QloApps guarda "sin días especiales" como JSON vacio "[]",
                // no como string vacio — ambos significan "sin restriccion de dias".
                $hasSpecialDays = $specialDays !== '' && $specialDays !== '[]';
                if ($type !== self::DATE_SELECTION_TYPE_RANGE || $hasSpecialDays) {
                    return false; // Rango con dias especiales o seleccion por dias: no resoluble aqui.
                }
                $from = (string)($r['date_from'] ?? '');
                $to   = (string)($r['date_to'] ?? '');
                // La restriccion limita el plan: solo aplica si la estadia intersecta [from, to].
                $intersects = ($from === '' || $from <= $checkOut) && ($to === '' || $to >= $checkIn);
                if (!$intersects) return false;
            }
            return true;
        }));

        if (empty($applicable)) return null;

        usort($applicable, static fn (array $a, array $b): int =>
            (int)($a['id_feature_price'] ?? 0) <=> (int)($b['id_feature_price'] ?? 0));

        return $applicable[0];
    }

    /**
     * Aplica el plan al precio base (misma formula que QloApps, verificada en
     * el codigo del modulo hotelreservationsystem).
     *
     * @param array<string, mixed> $plan
     */
    public static function applyPlan(float $basePrice, array $plan): float {
        $way = (int)($plan['impact_way'] ?? self::IMPACT_WAY_DECREASE);
        $type = (int)($plan['impact_type'] ?? self::IMPACT_TYPE_PERCENTAGE);
        $value = (float)($plan['impact_value'] ?? 0);

        $price = match ($way) {
            self::IMPACT_WAY_FIX_PRICE => $value,
            self::IMPACT_WAY_INCREASE  => $type === self::IMPACT_TYPE_PERCENTAGE
                ? $basePrice * (1 + $value / 100)
                : $basePrice + $value,
            default => $type === self::IMPACT_TYPE_PERCENTAGE
                ? $basePrice * (1 - $value / 100)
                : $basePrice - $value,
        };

        return round(max(0, $price), 2);
    }

    /**
     * Precio no reembolsable por noche: precio base tras aplicar el plan.
     * Sin plan aplicable => mismo precio (honesto; el descuento se activa
     * cuando el admin configure el Feature Price en QloApps).
     *
     * @param array<string, mixed>|null $plan
     */
    public static function nonRefundablePrice(float $basePrice, ?array $plan): float {
        if ($plan === null) return round($basePrice, 2);
        return self::applyPlan($basePrice, $plan);
    }
}
