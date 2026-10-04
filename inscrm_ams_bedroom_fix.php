<?php

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
header('Content-Type: text/plain');

$appPath = dirname(__DIR__) . '/insulacrm';
require $appPath . '/vendor/autoload.php';
$app = require $appPath . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AvailabilitySource;
use App\Models\Property;
use App\Services\AvailabilityIngestService;

try {
    $target = ($argv[1] ?? $_GET['source'] ?? '1');
    $csvPath = __DIR__ . '/' . ($argv[2] ?? $_GET['csv'] ?? 'ams_fix.csv');
    $guard = (($argv[3] ?? $_GET['guard'] ?? 'yes') === 'yes');

    $source = AvailabilitySource::withoutGlobalScopes()->find($target);
    if (! $source) {
        echo "ERROR: source {$target} not found\n";
        exit(1);
    }

    echo "source: #{$source->id} {$source->name} tenant={$source->tenant_id}\n";
    echo "parse_options: ".json_encode($source->parse_options)."\n";
    echo "column_map keys: ".implode(' | ', array_keys($source->column_map ?: []))."\n";
    echo "csv exists: ".(is_file($csvPath) ? 'yes ('.filesize($csvPath).' bytes)' : 'NO')."\n";

    $parseOptions = collect($source->parse_options ?: [])
        ->only(['delimiter', 'has_header', 'inherit_columns'])
        ->all();

    $service = new AvailabilityIngestService;
    $table = $service->parseFile($csvPath, 'csv', $parseOptions);
    echo "parsed rows: ".count($table['rows'])."\n---\n";

    $before = Property::withoutGlobalScopes()
        ->where('tenant_id', $source->tenant_id)
        ->where('availability_source_id', $source->id)
        ->count();
    echo "before units: {$before}\n";

    $result = $service->ingest($source, $table['rows'], $source->tenant_id, null, null, $guard);

    echo "result: ".json_encode($result)."\n---\nunits now:\n";
    foreach (Property::withoutGlobalScopes()
        ->where('tenant_id', $source->tenant_id)
        ->where('availability_source_id', $source->id)
        ->orderBy('sub_community')
        ->orderBy('source_unit_ref')
        ->get(['id', 'sub_community', 'source_unit_ref', 'bedrooms', 'marketing_title']) as $p) {
        printf("#%d %-24s %-10s beds=%-4s | %s\n", $p->id, $p->sub_community, $p->source_unit_ref, var_export($p->bedrooms, true), $p->marketing_title);
    }
    echo "--- DONE ---\n";
} catch (\Throwable $e) {
    echo "ERROR: ".$e->getMessage()."\n";
    echo $e->getTraceAsString();
}