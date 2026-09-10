<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityImportRun;
use App\Models\AvailabilitySource;
use App\Services\AvailabilityIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvailabilitySourceController extends Controller
{
    public function __construct(protected AvailabilityIngestService $ingest)
    {
    }

    public function index()
    {
        $sources = AvailabilitySource::with(['latestRun'])
            ->withCount('properties')
            ->orderBy('name')
            ->get();

        return view('availability.index', compact('sources'));
    }

    public function create()
    {
        return view('availability.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'contact_info' => 'nullable|string|max:255',
            'default_building' => 'nullable|string|max:150',
            'default_category' => 'nullable|string|max:40',
            'default_city' => 'nullable|string|max:100',
        ]);

        $source = AvailabilitySource::create([
            'tenant_id' => auth()->user()->tenant_id,
            ...$data,
        ]);

        return redirect()->route('availability-sources.edit', $source)
            ->with('success', __('Source added. Now define its column mapping so lists import correctly.'));
    }

    public function edit(AvailabilitySource $source)
    {
        return view('availability.edit', compact('source'));
    }

    public function update(Request $request, AvailabilitySource $source)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'contact_info' => 'nullable|string|max:255',
            'default_building' => 'nullable|string|max:150',
            'default_category' => 'nullable|string|max:40',
            'default_city' => 'nullable|string|max:100',
            'column_map' => 'nullable|array',
            'column_map.*.source' => 'nullable|string|max:60',
            'column_map.*.target' => 'nullable|string|max:40',
            'column_map.*.inherit' => 'nullable|boolean',
            'delimiter' => 'nullable|string|in:multi_space,tab,comma,semicolon,auto',
            'has_header' => 'nullable|boolean',
            'status_map' => 'nullable|string',
        ]);

        $columnMap = [];
        $inherit = [];
        foreach (($data['column_map'] ?? []) as $row) {
            $sourceHeader = trim((string) ($row['source'] ?? ''));
            $target = trim((string) ($row['target'] ?? ''));
            if ($sourceHeader === '' || $target === '') {
                continue;
            }
            $columnMap[$sourceHeader] = $target;
            if (!empty($row['inherit'])) {
                $inherit[] = $sourceHeader;
            }
        }

        $statusMap = [];
        foreach (preg_split('/\R/', (string) ($data['status_map'] ?? '')) as $line) {
            if (preg_match('/^(.+?)\s*(?:=>|->|=)\s*(.+)$/', trim($line), $m)) {
                $statusMap[trim($m[1])] = trim($m[2]);
            }
        }

        $source->update([
            'name' => $data['name'],
            'contact_info' => $data['contact_info'] ?? null,
            'default_building' => $data['default_building'] ?? null,
            'default_category' => $data['default_category'] ?? null,
            'default_city' => $data['default_city'] ?? null,
            'column_map' => $columnMap,
            'parse_options' => [
                'delimiter' => $data['delimiter'] ?? 'multi_space',
                'has_header' => !empty($data['has_header']),
                'inherit_columns' => $inherit,
            ],
            'status_map' => $statusMap,
            'last_imported_at' => $source->last_imported_at,
        ]);

        return back()->with('success', __('Source and mapping saved.'));
    }

    public function importShow(AvailabilitySource $source)
    {
        return view('availability.import', compact('source'));
    }

    public function importParse(Request $request, AvailabilitySource $source)
    {
        $request->validate([
            'file' => 'nullable|file|mimes:csv,txt,xlsx|max:10240',
            'pasted' => 'nullable|string|max:200000',
            'delimiter' => 'nullable|string|in:multi_space,tab,comma,semicolon,auto',
            'has_header' => 'nullable|boolean',
        ]);

        $format = 'text';
        $path = null;
        $text = '';

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $file = $request->file('file');
            $format = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
            $path = $file->store('availability_imports', 'local');
        }

        $parseOptions = [
            'delimiter' => $request->input('delimiter', $source->parse_options['delimiter'] ?? 'multi_space'),
            'has_header' => $request->boolean('has_header', $source->parse_options['has_header'] ?? false),
        ];
        if ($format === 'csv' && !in_array($parseOptions['delimiter'], ['comma', 'semicolon', 'tab', 'auto'])) {
            $parseOptions['delimiter'] = 'auto';
        }
        if (isset($source->parse_options['inherit_columns'])) {
            $parseOptions['inherit_columns'] = $source->parse_options['inherit_columns'];
        }

        try {
            if ($path) {
                $table = $this->ingest->parseFile(storage_path('app/' . $path), $format, $parseOptions);
            } else {
                $text = $request->input('pasted', '');
                $table = $this->ingest->parseText($text, $parseOptions);
            }
        } catch (\Throwable $e) {
            if ($path) {
                Storage::delete($path);
            }

            return back()->withErrors(['pasted' => $e->getMessage()])->withInput();
        }

        if (empty($table['rows'])) {
            if ($path) {
                Storage::delete($path);
            }

            return back()->withErrors(['pasted' => __('No rows could be read from that input. Check the file or separator choice.')])->withInput();
        }

        session()->put("availability_preview.{$source->id}", [
            'path' => $path,
            'format' => $format,
            'text' => $text,
            'parse_options' => $parseOptions,
            'header' => $table['header'],
            'rows' => $table['rows'],
        ]);

        return redirect()->route('availability-sources.review', $source);
    }

    public function importReview(AvailabilitySource $source)
    {
        $preview = session()->get("availability_preview.{$source->id}");
        if (!$preview) {
            return redirect()->route('availability-sources.import', $source)
                ->with('error', __('Nothing to review yet — choose a file or paste a list first.'));
        }

        $sample = array_slice($preview['rows'], 0, 15);

        $columnMap = $source->column_map ?: [];
        $counts = ['total' => count($preview['rows']), 'mapped' => 0];
        $mappedHeaders = [];
        foreach ($columnMap as $header => $target) {
            if (in_array($target, ['unit_no', 'building', 'features', 'rent', 'deposit', 'admin_fee', 'status', 'parking', 'key_date', 'amenities', 'remarks', 'community'])) {
                $mappedHeaders[] = $header;
            }
        }
        foreach ($preview['rows'] as $row) {
            $hasUnit = false;
            foreach ($mappedHeaders as $header) {
                if (($row[$header] ?? '') !== '') {
                    $hasUnit = true;
                    break;
                }
            }
            if ($hasUnit) {
                $counts['mapped']++;
            }
        }

        return view('availability.review', [
            'source' => $source,
            'preview' => $preview,
            'sample' => $sample,
            'counts' => $counts,
            'columnMap' => $columnMap,
        ]);
    }

    public function importRun(Request $request, AvailabilitySource $source)
    {
        $preview = session()->get("availability_preview.{$source->id}");
        if (!$preview) {
            return redirect()->route('availability-sources.import', $source)
                ->with('error', __('Preview expired — please re-upload the list.'));
        }

        $run = AvailabilityImportRun::create([
            'tenant_id' => auth()->user()->tenant_id,
            'source_id' => $source->id,
            'user_id' => auth()->id(),
            'filename' => $preview['path'] ? basename($preview['path']) : 'pasted text',
            'format' => $preview['format'],
            'status' => 'processing',
        ]);

        try {
            $result = $this->ingest->ingest(
                $source,
                $preview['rows'],
                auth()->user()->tenant_id,
                auth()->id()
            );

            $run->update([
                'status' => 'completed',
                'total_rows' => $result['total'],
                'created_rows' => $result['created'],
                'updated_rows' => $result['updated'],
                'missing_rows' => $result['missing'],
                'skipped_rows' => $result['skipped'],
                'notes' => implode("\n", array_slice($result['skipped_examples'], 0, 5)),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return back()->withErrors(['import' => $e->getMessage()]);
        } finally {
            if ($preview['path']) {
                Storage::delete($preview['path']);
            }
            session()->forget("availability_preview.{$source->id}");
        }

        if ($result['created'] === 0 && $result['updated'] === 0 && $result['skipped'] > 0) {
            return redirect()->route('availability-sources.edit', $source)
                ->with('error', sprintf(
                    'Nothing imported — %d rows skipped. The column mapping may not match your sheet; check "%s".',
                    $result['skipped'],
                    $result['skipped_examples'][0] ?? ''
                ));
        }

        return redirect()->route('availability-sources.index')
            ->with('success', sprintf(
                '%d created, %d updated, %d marked unlisted (%d left in the sheet were skipped).',
                $result['created'],
                $result['updated'],
                $result['missing'],
                $result['skipped']
            ));
    }

    public function importCancel(AvailabilitySource $source)
    {
        $preview = session()->get("availability_preview.{$source->id}");
        if ($preview && $preview['path']) {
            Storage::delete($preview['path']);
        }
        session()->forget("availability_preview.{$source->id}");

        return redirect()->route('availability-sources.import', $source)
            ->with('info', __('Import cancelled.'));
    }
}