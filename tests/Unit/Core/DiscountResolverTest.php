<?php
declare(strict_types=1);

namespace App\Test\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Features\Shared\DiscountResolver;

/**
 * Tests de la resolucion de la tarifa No Reembolsable (DiscountResolver).
 * El descuento vive en QloApps (Feature Price Plan, admin del CMS) y el
 * backend lo resuelve — el frontend jamas calcula precios/descuentos.
 * Enums verificados contra el codigo fuente de QloApps
 * (HotelRoomTypeFeaturePricing: impact_way 1=Decrease 2=Increase 3=Fixed,
 * impact_type 1=Percentage 2=Fixed).
 */
final class DiscountResolverTest extends TestCase {
    private function plan(array $overrides = []): array {
        return array_merge([
            'id_feature_price' => 1,
            'id_product'       => 1,
            'impact_way'       => DiscountResolver::IMPACT_WAY_DECREASE,
            'impact_type'      => DiscountResolver::IMPACT_TYPE_PERCENTAGE,
            'impact_value'     => 10,
        ], $overrides);
    }

    private function restriction(array $overrides = []): array {
        return array_merge([
            'id_feature_price'   => 1,
            'date_selection_type' => DiscountResolver::DATE_SELECTION_TYPE_RANGE,
            'special_days'       => '',
            'date_from'          => '2026-01-01',
            'date_to'            => '2026-12-31',
        ], $overrides);
    }

    public function testPicksPlanWithoutRestrictions(): void {
        $plans = [$this->plan()];
        $picked = DiscountResolver::pickPlan($plans, [], '2026-08-10', '2026-08-12');
        $this->assertSame(1, $picked['id_feature_price']);
    }

    public function testRestrictionRangeMustIntersectStay(): void {
        $plans = [$this->plan()];
        $restrictions = [1 => [$this->restriction(['date_from' => '2026-11-01', 'date_to' => '2026-11-30'])]];

        $this->assertNull(DiscountResolver::pickPlan($plans, $restrictions, '2026-08-10', '2026-08-12'));
        $this->assertNotNull(DiscountResolver::pickPlan($plans, $restrictions, '2026-11-15', '2026-11-20'));
        // La estadia intersecta el inicio del rango.
        $this->assertNotNull(DiscountResolver::pickPlan($plans, $restrictions, '2026-11-28', '2026-12-02'));
    }

    public function testSpecialDaysPlansAreSkipped(): void {
        $plans = [$this->plan()];
        $restrictions = [1 => [$this->restriction(['special_days' => '["1","2"]'])]];
        $this->assertNull(DiscountResolver::pickPlan($plans, $restrictions, '2026-08-10', '2026-08-12'));
    }

    public function testMultiplePlansPickLowestId(): void {
        $plans = [
            $this->plan(['id_feature_price' => 7, 'impact_value' => 5]),
            $this->plan(['id_feature_price' => 3, 'impact_value' => 15]),
        ];
        $picked = DiscountResolver::pickPlan($plans, [], '2026-08-10', '2026-08-12');
        $this->assertSame(3, $picked['id_feature_price']);
    }

    public function testApplyPlanFormulas(): void {
        $this->assertSame(90.0, DiscountResolver::applyPlan(100.0, $this->plan())); // -10%
        $this->assertSame(110.0, DiscountResolver::applyPlan(100.0, $this->plan(['impact_way' => DiscountResolver::IMPACT_WAY_INCREASE])));
        $this->assertSame(80.0, DiscountResolver::applyPlan(100.0, $this->plan(['impact_type' => DiscountResolver::IMPACT_TYPE_FIXED_PRICE, 'impact_value' => 20]))); // -$20
        $this->assertSame(45.0, DiscountResolver::applyPlan(100.0, $this->plan(['impact_way' => DiscountResolver::IMPACT_WAY_FIX_PRICE, 'impact_value' => 45]))); // fijo
        $this->assertSame(0.0, DiscountResolver::applyPlan(10.0, $this->plan(['impact_type' => DiscountResolver::IMPACT_TYPE_FIXED_PRICE, 'impact_value' => 50]))); // floor 0
    }

    public function testNonRefundablePriceEqualsBaseWithoutPlan(): void {
        // Honesto: sin plan configurado en QloApps, ambas tarifas al mismo precio.
        $this->assertSame(45.0, DiscountResolver::nonRefundablePrice(45.0, null));
        $this->assertSame(40.5, DiscountResolver::nonRefundablePrice(45.0, $this->plan()));
    }
}
