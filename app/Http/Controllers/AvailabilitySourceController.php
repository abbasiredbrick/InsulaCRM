<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityImportRun;
use App\Models\AvailabilityReview;
use App\Models\AvailabilitySource;
use App\Services\AvailabilityIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvailabilitySourceController extends Controller
{
    public function __construct(protected AvailabilityIngestService $ingest) {}

    public function index()
    {
        $sources = AvailabilitySource::with(['latestRun'])
            ->withCount('properties')
            ->orderBy('name')
            ->get();

        $pendingReviews = AvailabilityReview::with(['property', 'source'])->pending()->latest()->get();

        return view('availability.index', compact('sources', 'pendingReviews'));
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
            'url' => 'nullable|url|max:500',
            'default_building' => 'nullable|string|max:150',
            'default_category' => 'nullable|string|max:40',
            'default_city' => 'nullable|string|max:100',
            'default_deposit' => 'nullable|numeric|min:0',
            'default_admin_fee' => 'nullable|numeric|min:0',
            'default_tawtheeq_fee' => 'nullable|numeric|min:0',
            'missing_status' => 'nullable|string|in:listed,ready_to_list,reserved,leased,sold,unlisted,draft',
        ]);

        $url = $data['url'] ?? null;

        $source = AvailabilitySource::create([
            'tenant_id' => auth()->user()->tenant_id,
            ...$data,
            'url' => $url,
            'missing_status' => $data['missing_status'] ?? ($url ? 'unlisted' : 'leased'),
        ]);

        // URL-published lists (e.g. RDK's portfolio JSON) have a known shape,
        // so jump straight to a working mapping.
        if ($url) {
            $source->update([
                'column_map' => [
                    'Unit' => 'unit_no',
                    'Tower' => 'building',
                    'Property' => 'community',
                    'City' => 'city',
                    'Type' => 'features',
                    'Remarks' => 'remarks',
                    'Rent' => 'rent',
                ],
            ]);
        }

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
            'url' => 'nullable|url|max:500',
            'default_building' => 'nullable|string|max:150',
            'default_category' => 'nullable|string|max:40',
            'default_city' => 'nullable|string|max:100',
            'default_deposit' => 'nullable|numeric|min:0',
            'default_admin_fee' => 'nullable|numeric|min:0',
            'default_tawtheeq_fee' => 'nullable|numeric|min:0',
            'column_map' => 'nullable|array',
            'column_map.*.source' => 'nullable|string|max:60',
            'column_map.*.target' => 'nullable|string|max:40',
            'column_map.*.inherit' => 'nullable|boolean',
            'delimiter' => 'nullable|string|in:multi_space,tab,comma,semicolon,auto',
            'has_header' => 'nullable|boolean',
            'status_map' => 'nullable|string',
            'missing_status' => 'nullable|string|in:listed,ready_to_list,reserved,leased,sold,unlisted,draft',
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
            if (! empty($row['inherit'])) {
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
            'url' => $data['url'] ?? null,
            'default_building' => $data['default_building'] ?? null,
            'default_category' => $data['default_category'] ?? null,
            'default_city' => $data['default_city'] ?? null,
            'default_deposit' => $data['default_deposit'] ?? null,
            'default_admin_fee' => $data['default_admin_fee'] ?? null,
            'default_tawtheeq_fee' => $data['default_tawtheeq_fee'] ?? null,
            'column_map' => $columnMap,
            'parse_options' => [
                'delimiter' => $data['delimiter'] ?? 'multi_space',
                'has_header' => ! empty($data['has_header']),
                'inherit_columns' => $inherit,
            ],
            'status_map' => $statusMap,
            'missing_status' => $data['missing_status'] ?? ($source->url ? 'unlisted' : ($source->missing_status ?: 'leased')),
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
        if ($format === 'csv' && ! in_array($parseOptions['delimiter'], ['comma', 'semicolon', 'tab', 'auto'])) {
            $parseOptions['delimiter'] = 'auto';
        }
        if (isset($source->parse_options['inherit_columns'])) {
            $parseOptions['inherit_columns'] = $source->parse_options['inherit_columns'];
        }

        try {
            if ($path) {
                $table = $this->ingest->parseFile(Storage::path($path), $format, $parseOptions);
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
        if (! $preview) {
            return redirect()->route('availability-sources.import', $source)
                ->with('error', __('Nothing to review yet — choose a file or paste a list first.'));
        }

        $sample = array_slice($preview['rows'], 0, 15);

        $columnMap = $source->column_map ?: [];
        $counts = ['total' => count($preview['rows']), 'mapped' => 0];
        $mappedHeaders = [];
        foreach ($columnMap as $header => $target) {
            if (in_array($target, ['unit_no', 'building', 'community', 'city', 'features', 'rent', 'deposit', 'admin_fee', 'tawtheeq', 'status', 'parking', 'key_date', 'amenities', 'remarks'])) {
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
        if (! $preview) {
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
                auth()->id(),
                $run->id
            );

            $run->update([
                'status' => 'completed',
                'total_rows' => $result['total'],
                'created_rows' => $result['created'],
                'updated_rows' => $result['updated'],
                'missing_rows' => $result['missing'],
                'conflict_rows' => $result['conflicts'],
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

        $summary = sprintf(
            '%d created, %d updated, %d marked unlisted (%d left in the sheet were skipped).',
            $result['created'],
            $result['updated'],
            $result['missing'],
            $result['skipped']
        );

        if (! empty($result['mapping_rebuilt'])) {
            $summary .= __(' Column mapping was auto-detected from the file\'s column headers — verify it on the Edit screen if anything looks off.');
        }

        if (! empty($result['reconciliation_skipped'])) {
            $summary .= __(' Reconciliation for units missing from the sheet was skipped — the file looked partial, so nothing was bulk-marked leased. Re-check the column mapping and re-import.');
        }

        if (($result['conflicts'] ?? 0) > 0) {
            $summary .= sprintf(
                ' %d listed unit(s) flagged for a decision (%s).',
                $result['conflicts'],
                route('availability-sources.reviews')
            );
        }

        return redirect()->route('availability-sources.index')->with('success', $summary);
    }

    /**
     * Pending (and recently resolved) decisions for listed units the PM sheet
     * now shows as leased.
     */
    public function reviews()
    {
        $pending = AvailabilityReview::with(['property', 'source', 'decider'])
            ->pending()->latest()->get();

        $resolved = AvailabilityReview::with(['property', 'source', 'decider'])
            ->resolved()->latest()->limit(20)->get();

        return view('availability.reviews', compact('pending', 'resolved'));
    }

    /**
     * Resolve a listed-unit decision: keep it listed (leads keep coming in and
     * are diverted) or unlist it.
     */
    public function resolve(Request $request, AvailabilityReview $review)
    {
        $action = $request->validate(['action' => 'required|in:keep_listed,unlist'])['action'];
        $property = $review->property;

        if (! $property) {
            $review->update(['status' => $action, 'decided_by' => auth()->id(), 'decided_at' => now()]);

            return redirect()->route('availability-sources.reviews')
                ->with('success', __('Decision recorded.'));
        }

        $note = $action === 'keep_listed'
            ? 'Kept listed (decision: keep receiving leads and divert them) on '.now()->format('d.m.Y').' — PM sheet shows the unit as leased.'
            : 'Unlisted per manager decision on '.now()->format('d.m.Y').' — PM sheet shows the unit as leased.';

        if ($action === 'keep_listed') {
            $property->update([
                'availability' => 'listed',
                'notes' => trim(($property->notes ?? '').' '.$note),
            ]);
        } else {
            $property->update([
                'availability' => 'unlisted',
                'notes' => trim(($property->notes ?? '').' '.$note),
            ]);
        }

        $review->update([
            'status' => $action,
            'decided_by' => auth()->id(),
            'decided_at' => now(),
            'notes' => trim(($review->notes ?? '').' '.$note),
        ]);

        return redirect()->route('availability-sources.reviews')
            ->with('success', $action === 'keep_listed'
                ? __('Unit kept listed — leads can still be diverted to it or to other units.')
                : __('Unit unlisted.'));
    }

    /**
     * One-shot re-import: upload a refreshed file and run it immediately with
     * the source's saved mapping — existing rows are updated in place, nothing
     * is duplicated, and listed units flagged as leased go to the review queue
     * instead of being unlisted.
     */
    public function importDirect(Request $request, AvailabilitySource $source)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx|max:10240',
        ]);

        $file = $request->file('file');
        $format = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $path = $file->store('availability_imports', 'local');

        $parseOptions = collect($source->parse_options ?: [])
            ->only(['delimiter', 'has_header', 'inherit_columns'])
            ->all();
        if ($format === 'csv' && ! in_array($parseOptions['delimiter'] ?? null, ['comma', 'semicolon', 'tab', 'auto'], true)) {
            $parseOptions['delimiter'] = 'auto';
        }

        try {
            $table = $this->ingest->parseFile(Storage::path($path), $format, $parseOptions);
        } catch (\Throwable $e) {
            Storage::delete($path);

            return back()->withErrors(['file' => $e->getMessage()])->withInput();
        }

        if (empty($table['rows'])) {
            Storage::delete($path);

            return back()->withErrors(['file' => __('No rows could be read from that file. Check the file or the source mapping.')])->withInput();
        }

        $out = $this->runRows($source, $table['rows'], $format, basename($path));
        Storage::delete($path);

        if (! $out['ok']) {
            return back()->withErrors(['file' => $out['error']]);
        }

        return redirect()->route('availability-sources.index')->with('success', $this->importSummary($out['result']));
    }

    /**
     * Pull a source's published availability straight from its public URL
     * (e.g. https://rdk.ae/Listing/data.json) and apply the same import rules.
     * Only units the PM published as available are imported; listed units that
     * drop off the published list keep their listing pending a decision, and
     * the rest take the source's missing status.
     */
    public function syncUrl(Request $request, AvailabilitySource $source)
    {
        if (! $source->url) {
            return back()->withErrors(['url' => __('This source has no availability URL configured yet.')]);
        }

        $run = AvailabilityImportRun::create([
            'tenant_id' => auth()->user()->tenant_id,
            'source_id' => $source->id,
            'user_id' => auth()->id(),
            'filename' => $source->url,
            'format' => 'url',
            'status' => 'processing',
        ]);

        try {
            $table = $this->ingest->fetchUrl($source->url);
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            return back()->withErrors(['url' => $e->getMessage()])->withInput();
        }

        if (empty($table['rows'])) {
            $message = __('The availability URL lists no units to import.');
            $run->update(['status' => 'failed', 'error_message' => $message]);

            return back()->withErrors(['url' => $message])->withInput();
        }

        $out = $this->runRows($source, $table['rows'], 'url', $source->url, $run, false);

        if (! $out['ok']) {
            return back()->withErrors(['url' => $out['error']]);
        }

        return redirect()->route('availability-sources.index')->with('success', $this->importSummary($out['result']));
    }

    /**
     * Record an import run, apply the source's mapping, and persist the outcome.
     *
     * @return array{ok: bool, error?: string, result?: array<string, mixed>}
     */
    protected function runRows(AvailabilitySource $source, array $rows, string $format, string $filename, ?AvailabilityImportRun $run = null, bool $guardReconciliation = true): array
    {
        $run ??= AvailabilityImportRun::create([
            'tenant_id' => auth()->user()->tenant_id,
            'source_id' => $source->id,
            'user_id' => auth()->id(),
            'filename' => $filename,
            'format' => $format,
            'status' => 'processing',
        ]);

        try {
            $result = $this->ingest->ingest($source, $rows, auth()->user()->tenant_id, auth()->id(), $run->id, $guardReconciliation);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $run->update([
            'status' => 'completed',
            'total_rows' => $result['total'],
            'created_rows' => $result['created'],
            'updated_rows' => $result['updated'],
            'missing_rows' => $result['missing'],
            'conflict_rows' => $result['conflicts'],
            'skipped_rows' => $result['skipped'],
            'notes' => implode("\n", array_slice($result['skipped_examples'], 0, 5)),
        ]);

        return ['ok' => true, 'result' => $result];
    }

    protected function importSummary(array $result): string
    {
        $summary = sprintf(
            '%d created, %d updated, %d marked unlisted (%d skipped).',
            $result['created'],
            $result['updated'],
            $result['missing'],
            $result['skipped']
        );

        if (! empty($result['mapping_rebuilt'])) {
            $summary .= __(' Column mapping was auto-detected from the file\'s column headers — verify it on the Edit screen if anything looks off.');
        }

        if (! empty($result['reconciliation_skipped'])) {
            $summary .= __(' Reconciliation for units missing from the sheet was skipped — the file looked partial, so nothing was bulk-marked leased. Re-check the column mapping and re-import.');
        }

        if (($result['conflicts'] ?? 0) > 0) {
            $summary .= sprintf(
                ' %d listed unit(s) flagged for a decision (%s).',
                $result['conflicts'],
                route('availability-sources.reviews')
            );
        }

        return $summary;
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
