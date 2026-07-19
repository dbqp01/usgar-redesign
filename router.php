<?php
// router.php - Router for the local PHP development server
// Emulates Apache .htaccess rewrite rules for the built-in PHP server

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Expose the public folder as root
$file = __DIR__ . '/public' . $uri;

// 1. If it is a physical file in the public directory, serve it directly
if (is_file($file)) {
    return false;
}

// 2. /api/rooms -> include db.php + rooms.php, output JSON
if ($uri === '/api/rooms') {
    include __DIR__ . '/public/api/db.php';
    include __DIR__ . '/public/api/rooms.php';
    sendJson(array_values($rooms));
    exit;
}

// 3. Rewrite for /api/channex/booking/[id] -> /api/channex/booking-detail.php?id=[id]
if (preg_match('#^/api/channex/booking/([^/]+)$#', $uri, $matches)) {
    $_GET['id'] = $matches[1];
    include __DIR__ . '/public/api/channex/booking-detail.php';
    exit;
}

// 4. Rewrite for /api/channex/booking -> /api/channex/booking.php
if ($uri === '/api/channex/booking') {
    include __DIR__ . '/public/api/channex/booking.php';
    exit;
}

// 5. Rewrite for /api/channex/availability -> /api/channex/availability.php
if ($uri === '/api/channex/availability') {
    include __DIR__ . '/public/api/channex/availability.php';
    exit;
}

// 6. Rewrite for other /api/* -> /api/*.php
if (preg_match('#^/api/([^/]+)$#', $uri, $matches)) {
    $script = __DIR__ . '/public/api/' . $matches[1] . '.php';
    if (is_file($script)) {
        include $script;
        exit;
    }
}

// Fallback to 404
http_response_code(404);
echo "404 Not Found";
