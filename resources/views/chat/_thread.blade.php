@php
    if (! function_exists('chatInitials')) {
        function chatInitials(?string $name): string
        {
            $name = trim((string) $name);
            if ($name === '') {
                return '?';
            }

            $parts = preg_split('/\s+/', $name);
            $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));
            if (count($parts) > 1) {
                $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
            }

            return $initials;
        }
    }

    $user = auth()->user();
    $messages = $conversation->messages()
        ->with(['author:id,name,role_id', 'mentions:id,name'])
        ->latest('id')
        ->limit(200)
        ->get()
        ->reverse();
    $isLeadLinked = $conversation->isLeadLinked();
    $showAuthors = $conversation->participants->count() > 2 || $conversation->isGroup();
    $avatarColors = ['bg-blue', 'bg-purple', 'bg-orange', 'bg-green', 'bg-cyan', 'bg-pink', 'bg-teal', 'bg-indigo'];
@endphp

<div class="card" id="chat-thread" data-conversation-id="{{ $conversation->id }}"
     data-messages-url="{{ route('chat.messages', $conversation) }}"
     style="height: calc(100vh - 230px); display:flex; flex-direction:column; border:none;">
    <div class="card-header d-flex align-items-center gap-2">
        <div class="flex-fill" style="min-width:0;">
            <div class="d-flex align-items-center gap-2">
                <h3 class="card-title mb-0 text-truncate">{{ $conversation->displayNameFor($user) }}</h3>
                @if($isLeadLinked)
                <a href="{{ route('leads.show', $conversation->lead_id) }}" class="badge bg-cyan-lt text-decoration-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                    {{ __('Lead') }} #{{ $conversation->lead_id }}
                </a>
                @endif
            </div>
            @if($conversation->isGroup())
            <div class="text-muted" style="font-size:12px;">{{ $conversation->participants->pluck('name')->join(', ') }}</div>
            @else
            <div class="text-muted" style="font-size:12px;">{{ __('Direct message') }}</div>
            @endif
        </div>
        @if($isLeadLinked)
        <a href="{{ route('leads.show', $conversation->lead_id) }}" class="btn btn-outline-secondary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm me-1" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
            {{ __('Open lead') }}
        </a>
        @endif
    </div>

    <div id="chat-msg-list" class="px-3 py-3 flex-fill overflow-auto" style="scroll-behavior:smooth; background: var(--tblr-bg-surface-tertiary, #f6f8fb);">
        @forelse($messages as $msg)
            @php
                $mine = $msg->user_id === $user->id;
                $mentionedMe = in_array($user->id, $msg->mentions->pluck('id')->all(), true);
            @endphp
            <div class="d-flex {{ $mine ? 'justify-content-end' : 'justify-content-start' }} mb-3"
                 data-msg-id="{{ $msg->id }}"
                 data-mentions="{{ json_encode($msg->mentions->pluck('id')) }}"
                 {{ $mentionedMe ? 'data-mentioned=1' : '' }}>
                @if(! $mine)
                <span class="avatar avatar-sm {{ $avatarColors[$msg->user_id % count($avatarColors)] }} me-2 flex-shrink-0">{{ chatInitials($msg->author?->name ?? '?') }}</span>
                @endif
                <div class="d-flex flex-column {{ $mine ? 'align-items-end' : 'align-items-start' }}" style="max-width: 78%;">
                    @if($showAuthors || $msg->author?->id !== $user->id)
                    <small class="text-muted mb-1 px-1" style="font-size:11px;">
                        {{ $msg->author?->name }}
                        <span class="mx-1">·</span>
                        {{ $msg->created_at?->diffForHumans() }}
                    </small>
                    @endif
                    <div class="rounded-2 px-3 py-2 shadow-sm {{ $mine ? 'bg-primary text-white' : 'bg-white' }} {{ $mentionedMe ? 'chat-mentioned' : '' }}" style="border:1px solid {{ $mine ? 'transparent' : 'var(--tblr-border-color)' }};">
                        <div class="chat-body" style="font-size:0.875rem; line-height:1.5;">{!! $msg->renderBody() !!}</div>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center text-muted py-5" id="chat-msg-empty">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-2" width="40" height="40" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-3 -3h-7a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1h9a1 1 0 0 1 1 1v10"/><path d="M14 15v-2a2 2 0 0 0 -2 -2h-7l-3 3v11l2.5 -2.5"/></svg>
                <div>{{ __('No messages yet.') }}</div>
                <div class="small">{{ __('Use @name to mention a teammate.') }}</div>
            </div>
        @endforelse
    </div>

    <div class="border-top px-3 py-2" style="background: var(--tblr-card-bg);">
        <form id="chat-composer" data-url="{{ route('chat.store', $conversation) }}" autocomplete="off">
            @csrf
            <input type="hidden" name="mentioned_user_ids" id="mention-ids" value="">
            <div class="position-relative">
                <textarea name="body" id="chat-input" rows="2" class="form-control" placeholder="{{ __('Type a message... use @ to mention someone') }}" maxlength="5000" required></textarea>
                <div id="mention-menu" class="dropdown-menu w-100 p-0" style="display:none; position:absolute; bottom:100%; left:0; margin-bottom:.25rem; max-height:240px; overflow-y:auto;"></div>
            </div>
            <div class="d-flex align-items-center justify-content-between mt-2">
                <small class="text-muted">
                    <kbd>Enter</kbd> {{ __('to send') }} · <kbd>Shift+Enter</kbd> {{ __('new line') }} · <kbd>@</kbd> {{ __('mention') }}
                </small>
                <button type="submit" class="btn btn-primary btn-sm" id="chat-send">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    {{ __('Send') }}
                </button>
            </div>
        </form>
    </div>
</div>