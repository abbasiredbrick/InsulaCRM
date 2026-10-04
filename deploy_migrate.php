<?php
$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('migrate', ['--force' => true]);
echo 'done';
