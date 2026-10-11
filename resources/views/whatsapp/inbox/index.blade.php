@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4>WhatsApp Inbox</h4>
                <small class="text-muted">Conversations from company WhatsApp number</small>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Lead</th>
                                <th>Assigned</th>
                                <th>Status</th>
                                <th>Last Message</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($conversations as $conversation)
                                <tr>
                                    <td>
                                        <strong>{{ $conversation->display_name ?? $conversation->wa_id }}</strong><br>
                                        <small class="text-muted">{{ $conversation->wa_id }}</small>
                                    </td>
                                    <td>
                                        @if ($conversation->lead)
                                            <a href="{{ route('leads.show', $conversation->lead) }}">{{ $conversation->lead->full_name }}</a>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $conversation->assignedUser->name ?? 'Unassigned' }}</td>
                                    <td>
                                        <span class="badge badge-{{ $conversation->status === 'open' ? 'success' : ($conversation->status === 'closed' ? 'secondary' : 'warning') }}">
                                            {{ ucfirst($conversation->status) }}
                                        </span>
                                    </td>
                                    <td>{{ $conversation->last_message_at ? $conversation->last_message_at->diffForHumans() : '' }}</td>
                                    <td>
                                        <a href="{{ route('whatsapp.inbox.show', $conversation) }}" class="btn btn-sm btn-primary">Open</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No conversations yet</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($conversations->hasPages())
                    <div class="card-footer">
                        {{ $conversations->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
