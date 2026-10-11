@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Conversation</h5>
                </div>
                <div class="card-body">
                    <p><strong>Customer:</strong> {{ $conversation->display_name ?? $conversation->wa_id }}</p>
                    <p><strong>Phone:</strong> {{ $conversation->wa_id }}</p>
                    @if ($conversation->lead)
                        <p><strong>Lead:</strong> <a href="{{ route('leads.show', $conversation->lead) }}">{{ $conversation->lead->full_name }}</a></p>
                    @endif
                    <p><strong>Assigned:</strong> {{ $conversation->assignedUser->name ?? 'Unassigned' }}</p>
                    <p><strong>Status:</strong> {{ ucfirst($conversation->status) }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="mb-0">Messages</h5>
                    <div>
                        <button class="btn btn-sm btn-secondary" onclick="closeConversation()">Close</button>
                    </div>
                </div>
                <div class="card-body" style="height: 400px; overflow-y: auto;" id="messages">
                    @foreach ($conversation->messages as $message)
                        <div class="mb-2 {{ $message->direction === 'outbound' ? 'text-right' : '' }}">
                            <div class="d-inline-block p-2 rounded {{ $message->direction === 'outbound' ? 'bg-primary text-white' : 'bg-light' }}" style="max-width: 70%;">
                                {{ $message->body }}
                            </div>
                            <div class="small text-muted mt-1">
                                {{ $message->created_at->format('d M H:i') }}
                                @if ($message->direction === 'outbound' && $message->user)
                                    · {{ $message->user->name }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="card-footer">
                    <form id="replyForm" method="POST" action="{{ route('whatsapp.inbox.reply', $conversation) }}">
                        @csrf
                        <div class="input-group">
                            <input type="text" name="message" class="form-control" placeholder="Type your message..." required>
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="submit">Send</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.getElementById('replyForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const form = this;
    const input = form.querySelector('input[name="message"]');
    const button = form.querySelector('button[type="submit"]');
    const message = input.value;
    if (!message) return;
    button.disabled = true;
    fetch(form.action, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ message })
    }).then(function(response) {
        return response.json().then(function(data) {
            if (!response.ok) throw new Error(data.message || 'Message was not sent.');
            location.reload();
        });
    }).catch(function(error) {
        alert(error.message);
        button.disabled = false;
    });
});

function closeConversation() {
    fetch('{{ route('whatsapp.inbox.close', $conversation) }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        }
    }).then(() => {
        location.reload();
    });
}
</script>
@endpush
