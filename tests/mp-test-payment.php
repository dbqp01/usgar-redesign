<?php
require __DIR__ . '/../app/Core/Autoloader.php';
\App\Core\Autoloader::register(__DIR__ . '/../app');
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Common\RequestOptions;

Config::init();

echo "Iniciando simulacion de pago local para validacion MCP...\n";

// Usamos el token real de entorno
$token = Config::get('MERCADO_PAGO_ACCESS_TOKEN');
if (!$token) {
    die("Error: MERCADO_PAGO_ACCESS_TOKEN no esta configurado.\n");
}
MercadoPagoConfig::setAccessToken($token);

// 1. Crear un pago de prueba directamente via PaymentClient 
// (Esto solo funciona si el token tiene permisos de Checkout API para tarjetas,
// de lo contrario, simularemos un pago con Ticket / Rapipago / Efecty / Boleto, o crearemos una preferencia)

try {
    $client = new PaymentClient();
    $requestOptions = new RequestOptions();
    
    // Tratamos de crear un pago offline de prueba
    $paymentRequest = [
        "transaction_amount" => 50,
        "description" => "Test Payment for Quality Evaluation",
        "payment_method_id" => "pagoefectivo",
        "payer" => [
            "email" => "test_user_1116846715521496960@testuser.com",
            "first_name" => "APRO",
            "last_name" => "Test"
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
