<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\PriceCalculator;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    public function testExtraGuestChargeCeroDentroDeOcupanciaBase(): void
    {
        // Matrimonial: max 3, base 2 — 2 huespedes no pagan extra.
        $this->assertSame(0.0, PriceCalculator::extraGuestCharge(2, 3, 2, 30.0));
        $this->assertSame(0.0, PriceCalculator::extraGuestCharge(1, 3, 5, 30.0));
    }

    public function testExtraGuestChargeSoloParaElHuespedAdicional(): void
    {
        // Matrimonial 3 pax, 2 noches: 1 extra x 30 x 2 = 60.
        $this->assertSame(60.0, PriceCalculator::extraGuestCharge(3, 3, 2, 30.0));
        // Familiar 8 pax, 3 noches: 1 extra x 30 x 3 = 90.
        $this->assertSame(90.0, PriceCalculator::extraGuestCharge(8, 8, 3, 30.0));
    }

    public function testExtraGuestChargeNuncaSuperaUnHuesped(): void
    {
        // La validacion guests <= maxGuests ya limita el extra a 1 persona
        // (base = max - 1, tope = max). La formula es consistente en el borde:
        // un room con max=2 tendria base=1 => 2 huespedes = 1 extra.
        $this->assertSame(60.0, PriceCalculator::extraGuestCharge(2, 2, 2, 30.0));
        $this->assertSame(0.0, PriceCalculator::extraGuestCharge(1, 2, 2, 30.0));
    }

    public function testToGatewayPrice(): void
    {
        $this->assertSame(339.0, PriceCalculator::toGatewayPrice(100.0, 3.39));
        $this->assertSame(380.0, PriceCalculator::toGatewayPrice(100.0, 3.80));
    }

    public function testRoomTotalCentsAcumulaEnEnteros(): void
    {
        // 100.10 USD/noche x 2 noches + 30.00 extra = 20020 + 3000 = 23020 centimos.
        $this->assertSame(23020, PriceCalculator::roomTotalCents(100.10, 2, 30.0));
        // Sin extra y precio entero: 100 x 3 = 30000 centimos.
        $this->assertSame(30000, PriceCalculator::roomTotalCents(100.0, 3, 0.0));
        // Dos habitaciones sumadas en centimos: 23020 + 515 = 23535.
        $this->assertSame(23535, PriceCalculator::roomTotalCents(100.10, 2, 30.0) + PriceCalculator::roomTotalCents(5.15, 1, 0.0));
    }
}
