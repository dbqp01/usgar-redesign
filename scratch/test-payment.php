<?php
$data = [
    'cart_id' => 'USGAR-37011518eabc',
    'token' => 'dummy_token',
    'issuer_id' => 'visa',
    'payment_method_id' => 'visa',
    'transaction_amount' => 100.0,
    'installments' => 1,
    'payer' => [
        'email' => 'test@usgar.com',
        'identification' => [
            'type' => 'DNI',
            'number' => '12345678'
        ]
    ]
];

$ch = curl_init('http://localhost:8000/api/process-payment');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
echo "Response:\n$res\n";
curl_close($ch);
