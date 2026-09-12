<?php

$secret = 'eed8cb9caa426e62d6249ac6f9eb096c';

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
    Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    echo Illuminate\Support\Facades\Artisan::output();
    echo "\n=== MIGRATION COMPLETE ===\n";
    @unlink(__FILE__);
} catch (Throwable $e) {
    echo 'RUN FAILED: '.get_class($e).': '.$e->getMessage()."\n";
    echo 'File kept for retry. Delete it manually after fixing the error.'."\n";
}
