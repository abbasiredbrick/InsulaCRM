<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain');

$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

try {
    $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
    echo "=== migrate exit: {$exit} ===\n";
    echo \Illuminate\Support\Facades\Artisan::output();

    echo "\n=== schema check ===\n";
    foreach (['tasks' => ['created_by', 'due_time'], 'task_activities' => ['tenant_id', 'task_id', 'agent_id', 'body']] as $table => $cols) {
        echo "{$table}: ".Schema::hasTable($table) ? 'exists' : 'MISSING';
        echo ' cols=[';
        foreach ($cols as $col) {
            echo $col.'='.(Schema::hasColumn($table, $col) ? 'yes' : 'NO').';';
        }
        echo "]\n";
    }

    echo "\n=== latest rows in migrations ===\n";
    foreach (DB::table('migrations')->orderByDesc('id')->limit(6)->get() as $m) {
        echo "#{$m->id} {$m->migration}\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: ".$e->getMessage()."\n";
    echo $e->getTraceAsString();
}