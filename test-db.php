<?php
require 'vendor/autoload.php';
require 'app/Core/Autoloader.php';
\App\Core\Autoloader::register(__DIR__ . '/app');

\App\Core\Config::boot();
var_dump(\App\Core\Config::get('DB_NAME'));
var_dump(\App\Core\Config::get('DB_USER'));

$db = \App\Core\Database::getInstance();
var_dump($db->isConnected());
if (!$db->isConnected()) {
    var_dump(\App\Core\Logger::getLogs());
}
