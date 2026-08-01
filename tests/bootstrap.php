<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/dg/bypass-finals/src/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Core/Autoloader.php';

\App\Core\Autoloader::register(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app');
