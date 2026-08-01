<?php
$url = 'http://localhost:8000/api/booking';
$payloads = [
    'Empty Body' => [],
    'Missing Required' => ['id_room_type' => 1],
    'Bad Dates' => ['id_room_type' => 1, 'checkIn' => '2026-07-31', 'checkOut' => '2026-07-20', 'guestName' => 'Test', 'guestEmail' => 'test@test.com'],
    'Negative Guests' => ['id_room_type' => 1, 'guests' => -5, 'checkIn' => '2026-08-01', 'checkOut' => '2026-08-05', 'guestName' => 'Test', 'guestEmail' => 'test@test.com'],
    'Malformed Email' => ['id_room_type' => 1, 'checkIn' => '2026-08-01', 'checkOut' => '2026-08-05', 'guestName' => 'Test', 'guestEmail' => 'not-an-email'],
    'Happy Path' => [
        'id_room_type' => 1,
        'guests' => 2,
        'checkIn' => date('Y-m-d', strtotime('+5 days')),
        'checkOut' => date('Y-m-d', strtotime('+10 days')),
        'guestName' => 'John Doe',
        'guestEmail' => 'john.doe@example.com',
        'guestPhone' => '+123456789'
    ]
];

foreach ($payloads as $name => $payload) {
    echo "Testing: $name\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n\n";
    curl_close($ch);
}
