<?php
// Temporary production runner: dry-run / run the calendar repair for tenant 1.
// Deleted once the reconcile has been reviewed and executed.
$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;

$run = $argv[1] ?? 'dry';

if ($run === 'repair') {
    echo "=== running repair ===\n";
    Artisan::call('calendar:reconcile-events', ['--tenant' => '1']);
    echo Artisan::output();
} else {
    echo "=== dry run ===\n";
    Artisan::call('calendar:reconcile-events', ['--tenant' => '1', '--dry-run' => true]);
    echo Artisan::output();
}