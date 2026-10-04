<?php
$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('view:clear', [], new \Symfony\Component\Console\Output\BufferedOutput());
$kernel->call('route:clear', [], new \Symfony\Component\Console\Output\BufferedOutput());
$kernel->call('config:clear', [], new \Symfony\Component\Console\Output\BufferedOutput());
$kernel->call('view:cache', [], new \Symfony\Component\Console\Output\BufferedOutput());
$kernel->call('route:cache', [], new \Symfony\Component\Console\Output\BufferedOutput());
$kernel->call('config:cache', [], new \Symfony\Component\Console\Output\BufferedOutput());
echo 'ok';
