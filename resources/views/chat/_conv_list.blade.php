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
    $avatarColors = ['bg-blue', 'bg-purple', 'bg-orange', 'bg-green', 'bg-cyan', 'bg-pink', 'bg-teal', 'bg-indigo'];
@endphp
@if(($unreadTotal ?? 0) > 0)
<div class="px-3 py-2 border-bottom bg-azure-lt">
    <span class="text-secondary small fw-semibold">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm text-azure" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-3 -3h-7a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1h9a1 1 0 0 1 1 1v10"/><path d="M14 15v-2a2 2 0 0 0 -2 -2h-7l-3 3v11l2.5 -2.5"/></svg>
        {{ __(':count unread', ['count' => $unreadTotal]) }}
    </span>
</div>
@endif
@forelse($conversations ?? [] as $conv)
    @php
        $name = $conv->displayNameFor($user);
        $last = $conv->latestMessage;
        $unread = $conv->unreadCountFor($user);
        $other = $conv->participants->first(fn ($p) => $p->id !== $user->id);
        $avatarColor = $avatarColors[(($other?->id ?? $conv->id) % count($avatarColors))] ?? 'bg-azure';
        $isActive = isset($activeId) && (int) $activeId === (int) $conv->id;
    @endphp
    <a href="{{ route('chat.show', $conv) }}" class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none text-reset border-bottom {{ $isActive ? 'bg-azure-lt' : '' }}" data-conv-id="{{ $conv->id }}">
        <span class="avatar avatar-sm {{ $avatarColor }} flex-shrink-0">
            {{ chatInitials($conv->isGroup() ? ($conv->title ?? 'T') : ($other?->name ?? '#')) }}
        </span>
        <div class="flex-fill" style="min-width:0;">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <strong class="small text-truncate">{{ $name }}</strong>
                <small class="text-muted flex-shrink-0" style="font-size:11px;">{{ $last?->created_at?->diffForHumans() }}</small>
            </div>
            <div class="text-muted small text-truncate">
                {{ $last ? $last->preview() : __('Start the conversation') }}
            </div>
        </div>
        @if($unread)
        <span class="badge bg-red badge-pill flex-shrink-0" data-msg-unread="{{ $unread }}">{{ min($unread, 99) }}</span>
        @endif
    </a>
@empty
    <div class="text-center text-muted py-5 px-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-2 text-secondary" width="36" height="36" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-3 -3h-7a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1h9a1 1 0 0 1 1 1v10"/><path d="M14 15v-2a2 2 0 0 0 -2 -2h-7l-3 3v11l2.5 -2.5"/></svg>
        <div class="small">{{ __('No conversations yet.') }}</div>
        <div class="small">{{ __('Start one with a teammate or from a lead.') }}</div>
    </div>
@endforelse