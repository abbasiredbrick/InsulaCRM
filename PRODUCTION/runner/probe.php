<?php

require dirname(__DIR__) . '/insulacrm/vendor/autoload.php';
$app = require dirname(__DIR__) . '/insulacrm/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Deal;
use App\Models\Tenant;

header('Content-Type: text/plain; charset=utf-8');

$tenants = Tenant::all(['id', 'name', 'business_mode']);
foreach ($tenants as $t) {
    echo "TENANT {$t->id} {$t->name} mode={$t->business_mode}\n";
}

echo "\nDEAL_COUNT=" . Deal::count() . "\n";

echo "\nBY_STAGE:\n";
foreach (Deal::selectRaw('stage, count(*) c')->groupBy('stage')->orderByDesc('c')->get() as $r) {
    echo "  {$r->stage} = {$r->c}\n";
}

echo "\nBY_TYPE:\n";
foreach (Deal::selectRaw('deal_type, count(*) c')->groupBy('deal_type')->get() as $r) {
    echo "  " . var_export($r->deal_type, true) . " = {$r->c}\n";
}

echo "\nBY_TENANT:\n";
foreach (Deal::selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->get() as $r) {
    echo "  tenant {$r->tenant_id} = {$r->c}\n";
}

echo "\nCLOSED_WON_CHECKS:\n";
echo 'closed stage count=' . Deal::where('stage', 'closed_won')->orWhere('stage', 'closed')->count() . "\n";
echo 'contract_price sum=' . round((float) Deal::sum('contract_price'), 2) . "\n";

echo "\nDONE\n";