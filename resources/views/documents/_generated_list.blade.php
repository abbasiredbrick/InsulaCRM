{{-- Generated documents card, used by the Documents & Agreements hub. --}}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ __('Generated Documents') }}</h3>
        <div class="card-actions">
            <span class="text-secondary small">{{ __('Built from a template on the transaction page') }}</span>
        </div>
    </div>
    <div class="card-body">
        @if($documents->isEmpty())
            <p class="text-secondary mb-0">{{ __('No generated documents yet. Use Document Templates from a transaction to merge real deal data.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-vcenter">
                <thead>
                    <tr>
                        <th>{{ __('Name') }}</th>
                        <th>{{ __('Template') }}</th>
                        <th>{{ __('Deal') }}</th>
                        <th>{{ __('Created By') }}</th>
                        <th>{{ __('Created') }}</th>
                        <th class="text-end">{{ __('') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $document)
                    <tr>
                        <td>
                            <a href="{{ route('documents.show', $document) }}" class="fw-semibold text-reset">{{ $document->name }}</a>
                        </td>
                        <td>
                            <span class="badge bg-blue-lt">{{ $document->template?->name ?: __('—') }}</span>
                        </td>
                        <td>
                            @if($document->deal)
                                <a href="{{ route('deals.show', $document->deal) }}" class="fw-semibold text-reset">{{ $document->deal->title }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $document->user?->name ?: '—' }}</td>
                        <td class="text-secondary small">{{ $document->created_at->format('M d, Y') }}</td>
                        <td class="text-end">
                            <div class="btn-list justify-content-end">
                                <a href="{{ route('documents.print', $document) }}" target="_blank" class="btn btn-sm btn-outline">{{ __('Print') }}</a>
                                <form method="POST" action="{{ route('documents.destroy', $document) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete this document?') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>