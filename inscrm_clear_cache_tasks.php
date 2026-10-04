<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
header('Content-Type: text/plain');

$appPath = dirname($_SERVER['SCRIPT_FILENAME']) . '/../insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require_once $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    foreach (['view:clear', 'view:cache'] as $cmd) {
        $exit = \Illuminate\Support\Facades\Artisan::call($cmd, ['--no-interaction' => true]);
        echo "{$cmd} => exit {$exit}\n";
    }
    if (function_exists('opcache_reset')) {
        echo 'opcache_reset: '.(opcache_reset() ? 'OK' : 'FAIL')."\n";
    } else {
        echo "opcache_reset: not available\n";
    }
    echo "--- DONE ---\n";
} catch (\Throwable $e) {
    echo 'ERROR: '.$e->getMessage()."\n";
    echo $e->getTraceAsString()."\n";
}