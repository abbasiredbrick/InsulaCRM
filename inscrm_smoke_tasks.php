<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain');

$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

try {
    echo "APP_VERSION=" . config('app.version') . "\n\n";

    echo "=== routes matching 'tasks' ===\n";
    \Illuminate\Support\Facades\Artisan::call('route:list', ['--path' => 'tasks']);
    echo \Illuminate\Support\Facades\Artisan::output();

    echo "\n=== models ===\n";
    $task = new App\Models\Task();
    echo 'Task fillable: ' . implode(',', $task->getFillable()) . "\n";
    $ta = new App\Models\TaskActivity();
    echo 'TaskActivity fillable: ' . implode(',', $ta->getFillable()) . "\n";
    echo 'Schema columns: tasks.created_by=' . (Schema::hasColumn('tasks', 'created_by') ? 'yes' : 'no')
        . ' tasks.due_time=' . (Schema::hasColumn('tasks', 'due_time') ? 'yes' : 'no') . "\n";

    echo "\n=== recent tasks (count) ===\n";
    echo App\Models\Task::count() . " tasks total\n";
    foreach (App\Models\Task::with(['agent', 'owner'])->orderByDesc('id')->take(3)->get() as $t) {
        echo "#{$t->id} '{$t->title}' due={$t->due_date} time=" . ($t->due_time ?? '-') . " assigned=" . ($t->agent?->name ?? '?') . " owner=" . ($t->owner?->name ?? '?') . "\n";
    }

    echo "\n=== notifications config ===\n";
    $latest = App\Models\TaskActivity::orderByDesc('id')->first();
    echo 'latest task_activity: ' . ($latest ? $latest->id : 'none') . "\n";

    echo "\nSMOKE OK\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}