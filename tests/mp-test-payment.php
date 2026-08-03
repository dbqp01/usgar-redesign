<?php
require __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(__DIR__ . '/../app');
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\CardToken\CardTokenClient;
use MercadoPago\Client\Common\RequestOptions;

Config::boot();

echo "Iniciando simulacion de pago local para validacion MCP...\n";

// Usamos el token real de entorno
$token = Config::get('MERCADO_PAGO_ACCESS_TOKEN');
if (!$token) {
    die("Error: MERCADO_PAGO_ACCESS_TOKEN no esta configurado.\n");
}
MercadoPagoConfig::setAccessToken($token);

try {
    // 1. Tokenizar la tarjeta de prueba (Visa MPE: cardholder APRO = approved)
    $cardTokenClient = new CardTokenClient();
$cardTokenRequest = [
    "card_number" => "4009175332806176",
    "expiration_month" => 11,
    "expiration_year" => 2030,
    "security_code" => "123",
    "cardholder" => [
        "name" => "APRO",
        "identification" => [
            "type" => "DNI",
            "number" => "123456789"
        ]
    ]
];

$cardToken = $cardTokenClient->create($cardTokenRequest);
echo "Card token creado: " . substr($cardToken->id, 0, 8) . "...\n";

// 2. Crear el pago con la tarjeta tokenizada (Custom Checkout / Checkout API)
$client = new PaymentClient();
$requestOptions = new RequestOptions();

$paymentRequest = [
    "transaction_amount" => 50,
    "description" => "Test Card Payment for Webhook Verification",
    "payment_method_id" => "visa",
    "token" => $cardToken->id,
    "installments" => 1,
    "payer" => [
        "email" => "usgar.tester.2026@example.com",
        "identification" => [
            "type" => "DNI",
            "number" => "123456789"
        ]
    ]
];
    
    $payment = $client->create($paymentRequest, $requestOptions);
    
    echo "Pago creado exitosamente.\n";
    echo "Payment ID: " . $payment->id . "\n";
    echo "Status: " . $payment->status . "\n";
    
    // Guardar el payment ID en un archivo local para que el agente lo lea
    file_put_contents(__DIR__ . '/latest_payment_id.txt', $payment->id);

} catch (\Exception $e) {
    echo "Error al crear pago de prueba: " . $e->getMessage() . "\n";
    if (method_exists($e, 'getApiResponse') && $e->getApiResponse()) {
        print_r($e->getApiResponse()->getContent());
    }
}
