<?php

$secret = '7c2c54c4f51a5a1f31cf6ba53fdb35a8';

if (($_GET['key'] ?? null) !== $secret) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: text/plain; charset=utf-8');

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\AvailabilitySourcesSeeder::class, '--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    echo "\n=== AVAILABILITY SEED COMPLETE ===\n";
    @unlink(__FILE__);
} catch (Throwable $e) {
    echo 'RUN FAILED: '.get_class($e).': '.$e->getMessage()."\n";
    echo 'File kept for retry. Delete it manually after fixing the error.'."\n";
}
