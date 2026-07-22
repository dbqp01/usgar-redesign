<?php
declare(strict_types=1);

namespace App\Test\Unit\Features;

if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
    require_once __DIR__ . '/../TestCase.php';
}

use PHPUnit\Framework\TestCase;
use App\Features\Webhooks\Actions\HandleMercadoPagoWebhookAction;
use App\Features\Shared\Ports\PaymentGatewayPortInterface;
use App\Features\Shared\Ports\ChannelManagerPortInterface;

/**
 * Pruebas unitarias para HandleMercadoPagoWebhookAction y contratos de interfaz.
 */
final class HandleMercadoPagoWebhookActionTest extends TestCase {
    public function testPaymentGatewayPortInterfaceMethods(): void {
        /** @var PaymentGatewayPortInterface $paymentGatewayMock */
        $paymentGatewayMock = $this->createMock(PaymentGatewayPortInterface::class);

        $this->assertTrue(method_exists($paymentGatewayMock, 'verifySignature'));
        $this->assertTrue(method_exists($paymentGatewayMock, 'getPaymentDetails'));
    }

    public function testChannelManagerPortInterfaceCreateBooking(): void {
        /** @var ChannelManagerPortInterface $channelManagerMock */
        $channelManagerMock = $this->createMock(ChannelManagerPortInterface::class);

        $this->assertTrue(method_exists($channelManagerMock, 'createBooking'));
    }
}
