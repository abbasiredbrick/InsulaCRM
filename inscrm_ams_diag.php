<?php
$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $src = DB::table('availability_sources')->where('name', 'AMS Properties')->first();
    echo "=== source #{$src->id} AMS Properties ===\n";
    echo "default_building=".json_encode($src->default_building)." default_city=".json_encode($src->default_city)."\n";
    echo "column_map=".json_encode($src->column_map)."\n";
    echo "parse_options=".json_encode($src->parse_options)."\n";
    echo "status_map=".json_encode($src->status_map)."\n";
    echo "last_imported_at={$src->last_imported_at}\n";
    echo "\n=== import runs for source #{$src->id} ===\n";
    foreach (DB::table('availability_import_runs')->where('source_id', $src->id)->orderByDesc('id')->limit(8)->get() as $r) {
        echo "#{$r->id} {$r->status} format={$r->format} created={$r->created_rows} updated={$r->updated_rows} missing={$r->missing_rows} skp={$r->skipped_rows} err=".json_encode($r->error_message)." file=".json_encode($r->filename)."\n";
        if ($r->notes) echo "    notes=".json_encode($r->notes)."\n";
    }
    echo "\n=== properties linked to source #{$src->id} ===\n";
    $rows = DB::table('properties')->where('availability_source_id', $src->id)->get(['id','source_unit_ref','sub_community','community','availability']);
    echo "total: ".count($rows)."\n";
    foreach ($rows->groupBy('sub_community')->sortKeys() as $sc => $grp) {
        echo "  ".json_encode($sc)." => ".count($grp)."  [".$grp->map(fn($x)=>$x->source_unit_ref)->join(',')."]\n";
    }
    echo "\n=== units with sub_community = 'AMS Properties' or 'Other' (any source) ===\n";
    foreach (DB::table('properties')->whereIn('sub_community', ['AMS Properties','Other'])->get(['id','availability_source_id','source_unit_ref','sub_community']) as $p) {
        echo "#{$p->id} src={$p->availability_source_id} [{$p->source_unit_ref}] {$p->sub_community}\n";
    }
} catch (Throwable $e) {
    echo "ERROR: ".get_class($e).": ".$e->getMessage()."\n".$e->getFile().":".$e->getLine()."\n";
}