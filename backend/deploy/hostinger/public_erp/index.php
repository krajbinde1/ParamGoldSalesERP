<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$backendPath = '/home/u630302848/paramgold-erp/backend';

if (file_exists($maintenance = $backendPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $backendPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backendPath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
