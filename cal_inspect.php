<?php
// Temporary production diagnostic: explain exactly who the reconcile wants
// links for, and who already has one. Deleted after review.
$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\CalendarEventLink;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Models\User;
use App\Models\UserCloudConnection;

$tenant = 1;
$svc = app(App\Services\Cloud\CloudCalendarService::class);

$connected = UserCloudConnection::withoutGlobalScopes()
    ->where('tenant_id', $tenant)->pluck('user_id')->unique();

echo "tenant {$tenant} users with a connection row: ".$connected->implode(', ')."\n\n";

$users = User::withoutGlobalScopes()->where('tenant_id', $tenant)
    ->whereIn('id', $connected)->get();

foreach ($users as $u) {
    $c = $u->calendarConnections()->withoutGlobalScopes()->first();
    printf("  user %-3s %-28s role=%-10s conn=%s expires=%s\n",
        $u->id, substr($u->name, 0, 28), $u->role ?? '-', $c ? 'yes' : 'NO',
        $c?->expires_at ?: '-');
}

$records = [
    ...Showing::withoutGlobalScopes()->where('tenant_id', $tenant)->get()->all(),
    ...Meeting::withoutGlobalScopes()->where('tenant_id', $tenant)->get()->all(),
    ...Task::withoutGlobalScopes()->where('tenant_id', $tenant)->get()->all(),
];

echo "\n=== per record ===\n";
foreach ($records as $r) {
    $type = class_basename($r);
    $linked = CalendarEventLink::withoutGlobalScopes()
        ->where('eventable_type', $r::class)->where('eventable_id', $r->getKey())->count();

    $involved = [];
    foreach ($svc->involvedUsers($r) as $u) {
        $involved[] = $u->id.':'.substr($u->name, 0, 20);
    }

    printf("%-8s #%-3s status=%-10s links=%-2d involved=[%s]\n",
        $type, $r->getKey(), var_export($r->status, true), $linked, implode(' | ', $involved));
}