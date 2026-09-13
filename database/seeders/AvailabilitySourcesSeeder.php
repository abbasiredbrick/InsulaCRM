<?php

namespace Database\Seeders;

use App\Models\AvailabilityImportRun;
use App\Models\AvailabilitySource;
use App\Services\AvailabilityIngestService;
use Illuminate\Database\Seeder;

class AvailabilitySourcesSeeder extends Seeder
{
    public function run(): void
    {
        $dataDir = database_path('seeders/data/availability');

        $configs = [
            'bloom' => [
                'name' => 'Bloom',
                'paste' => 'bloom_paste.txt',
                'default_city' => 'Abu Dhabi',
                'missing_status' => 'leased',
                'status_map' => ['Booked' => 'reserved', 'Under Offer' => 'reserved'],
                'column_map' => [
                    'col0' => 'building',
                    'col1' => 'community',
                    'col2' => 'features',
                    'col3' => 'unit_no',
                    'col4' => 'bedrooms',
                    'col5' => 'square_footage',
                    'col6' => 'rent',
                    'col7' => 'status',
                    'col8' => 'remarks',
                ],
            ],

            'ay' => [
                'name' => 'AY',
                'paste' => 'ay_paste.txt',
                'default_city' => 'Abu Dhabi',
                'missing_status' => 'leased',
                'column_map' => [
                    'col0' => 'building',
                    'col1' => 'floor_no',
                    'col2' => 'unit_no',
                    'col3' => 'features',
                    'col4' => 'square_footage',
                    'col6' => 'features',
                    'col7' => 'status',
                    'col8' => 'rent',
                ],
            ],

            'etihad' => [
                'name' => 'Etihad Towers by AMS',
                'paste' => 'etihad_paste.txt',
                'default_building' => 'Etihad Towers',
                'default_category' => 'apartment',
                'default_city' => 'Abu Dhabi',
                'missing_status' => 'leased',
                'column_map' => [
                    'col1' => 'community',
                    'col2' => 'unit_no',
                    'col3' => 'bedrooms',
                    'col4' => 'features',
                    'col5' => 'square_footage',
                    'col6' => 'parking',
                    'col7' => 'rent',
                    'col8' => 'features',
                    'col9' => 'amenities',
                    'col10' => 'status',
                    'col11' => 'status',
                ],
            ],

            'ict_resi' => [
                'name' => 'ICT Residential',
                'paste' => 'ict_r_paste.txt',
                'default_city' => 'Abu Dhabi',
                'missing_status' => 'leased',
                'column_map' => [
                    'col1' => 'building',
                    'col2' => 'unit_no',
                    'col3' => 'rent',
                    'col4' => 'features',
                    'col5' => 'square_footage',
                    'col6' => 'parking',
                    'col7' => 'amenities',
                    'col8' => 'status',
                ],
            ],

            'ict_com' => [
                'name' => 'ICT Commercial',
                'paste' => 'ict_c_paste.txt',
                'default_city' => 'Abu Dhabi',
                'rent_is_per_sqm' => true,
                'missing_status' => 'leased',
                'column_map' => [
                    'col0' => 'building',
                    'col1' => 'unit_no',
                    'col2' => 'features',
                    'col3' => 'rent',
                    'col4' => 'square_footage',
                ],
            ],

            'relaam_apt' => [
                'name' => 'Relaam Apartments & Villas',
                'paste' => 'relaam_apt_paste.txt',
                'default_city' => 'Abu Dhabi',
                'missing_status' => 'leased',
                'column_map' => [
                    'col0' => 'building',
                    'col1' => 'unit_no',
                    'col2' => 'features',
                    'col3' => 'square_footage',
                    'col4' => 'city',
                    'col5' => 'plot_no',
                    'col7' => 'community',
                    'col10' => 'remarks',
                    'col11' => 'rent',
                ],
            ],

            'relaam_com' => [
                'name' => 'Relaam Commercial',
                'paste' => 'relaam_com_paste.txt',
                'default_city' => 'Abu Dhabi',
                'rent_is_per_sqm' => true,
                'missing_status' => 'leased',
                'column_map' => [
                    'col0' => 'building',
                    'col1' => 'unit_no',
                    'col2' => 'features',
                    'col3' => 'square_footage',
                    'col4' => 'city',
                    'col5' => 'plot_no',
                    'col7' => 'community',
                    'col10' => 'remarks',
                    'col11' => 'rent',
                ],
            ],
        ];

        $ingest = new AvailabilityIngestService;

        foreach ($configs as $config) {
            $file = $dataDir.'/'.$config['paste'];
            if (! is_file($file)) {
                $this->command?->warn("Missing paste file: {$config['paste']}");
                continue;
            }

            $source = AvailabilitySource::withoutGlobalScopes()->firstOrNew([
                'tenant_id' => 1,
                'name' => $config['name'],
            ]);

            $parseOptions = [
                'delimiter' => 'tab',
                'has_header' => false,
                'inherit_columns' => [],
            ];
            if (! empty($config['rent_is_per_sqm'])) {
                $parseOptions['rent_is_per_sqm'] = true;
            }

            $source->fill([
                'default_building' => $config['default_building'] ?? null,
                'default_category' => $config['default_category'] ?? null,
                'default_city' => $config['default_city'] ?? null,
                'column_map' => $config['column_map'],
                'parse_options' => $parseOptions,
                'status_map' => $config['status_map'] ?? [],
                'missing_status' => $config['missing_status'] ?? 'unlisted',
            ]);
            $source->save();

            $table = $ingest->parseText((string) file_get_contents($file), $parseOptions);

            $run = AvailabilityImportRun::withoutGlobalScopes()->create([
                'tenant_id' => 1,
                'source_id' => $source->id,
                'user_id' => 1,
                'filename' => $config['paste'],
                'format' => 'text',
                'status' => 'processing',
            ]);

            $result = $ingest->ingest($source, $table['rows'], 1, 1, $run->id);

            $run->update([
                'status' => 'completed',
                'total_rows' => $result['total'] ?? 0,
                'created_rows' => $result['created'] ?? 0,
                'updated_rows' => $result['updated'] ?? 0,
                'missing_rows' => $result['missing'] ?? 0,
                'conflict_rows' => $result['conflicts'] ?? 0,
                'skipped_rows' => $result['skipped'] ?? 0,
                'notes' => implode("\n", array_slice($result['skipped_examples'] ?? [], 0, 5)),
            ]);

            $this->command?->info(
                "{$source->name} (source #{$source->id}): parsed ".count($table['rows']).
                " rows, created={$result['created']} updated={$result['updated']} skipped={$result['skipped']}"
            );
        }
    }
}