<?php
// Router dev (patron usgar-repo-ops): sirve estaticos de dist/ y delega el
// resto a public/index.php. Uso: php -S 127.0.0.1:8090 tests/router-dev.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/../dist' . $path;
if ($path !== '/' && is_file($file)) {
    $mime = [
        'html' => 'text/html', 'js' => 'application/javascript', 'css' => 'text/css',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'mp4' => 'video/mp4',
        'json' => 'application/json', 'woff2' => 'font/woff2', 'xml' => 'application/xml',
        'txt' => 'text/plain', 'ico' => 'image/x-icon', 'webmanifest' => 'application/manifest+json',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}
if ($path !== '/' && is_dir($file)) {
    $index = $file . '/index.html';
    if (is_file($index)) {
        header('Content-Type: text/html');
        readfile($index);
        return true;
    }
    $candidates = glob($file . '/index.*');
    if ($candidates) {
        header('Content-Type: text/html');
        readfile($candidates[0]);
        return true;
    }
}
require __DIR__ . '/../public/index.php';
