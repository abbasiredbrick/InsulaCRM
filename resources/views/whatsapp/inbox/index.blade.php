@extends('layouts.app')

@section('content')
<style>
    .wa-inbox { display: flex; height: calc(100vh - 190px); min-height: 480px; border: 1px solid var(--tblr-border-color, #e6e8eb); border-radius: .75rem; overflow: hidden; background: #fff; }
    .wa-sidebar { width: 340px; flex: 0 0 340px; border-right: 1px solid var(--tblr-border-color, #e6e8eb); display: flex; flex-direction: column; background: #f8f9fa; }
    .wa-sidebar-head { padding: .75rem; border-bottom: 1px solid var(--tblr-border-color, #e6e8eb); background: #fff; }
    .wa-conv-list { flex: 1; overflow-y: auto; }
    .wa-conv { display: flex; gap: .65rem; padding: .65rem .75rem; cursor: pointer; border-bottom: 1px solid #f1f3f5; text-decoration: none; color: inherit; }
    .wa-conv:hover { background: #f1f3f5; }
    .wa-conv.active { background: #e9f3ff; }
    .wa-avatar { width: 44px; height: 44px; border-radius: 50%; background: #dfe5ec; color: #546680; display: flex; align-items: center; justify-content: center; font-weight: 600; flex: 0 0 44px; text-transform: uppercase; }
    .wa-conv-body { flex: 1; min-width: 0; }
    .wa-conv-top { display: flex; justify-content: space-between; gap: .5rem; align-items: baseline; }
    .wa-conv-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wa-conv-time { font-size: .72rem; color: #8696a0; white-space: nowrap; }
    .wa-conv-preview { font-size: .82rem; color: #667781; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .wa-unread { background: #25d366; color: #fff; border-radius: 1rem; font-size: .7rem; font-weight: 700; padding: .1rem .45rem; }
    .wa-main { flex: 1; display: flex; flex-direction: column; min-width: 0; background: #efeae2; }
    .wa-chat-head { display: flex; align-items: center; gap: .65rem; padding: .6rem .9rem; background: #f0f2f5; border-bottom: 1px solid var(--tblr-border-color, #e6e8eb); }
    .wa-chat-head .wa-avatar { width: 40px; height: 40px; flex-basis: 40px; }
    .wa-chat-head-actions { margin-left: auto; display: flex; gap: .4rem; align-items: center; }
    .wa-thread { flex: 1; overflow-y: auto; padding: 1rem; background-image: linear-gradient(rgba(255,255,255,.06),rgba(255,255,255,.06)); }
    .wa-day { text-align: center; margin: .85rem 0; }
    .wa-day span { background: #fff; color: #54656f; font-size: .72rem; padding: .2rem .7rem; border-radius: .5rem; box-shadow: 0 1px 1px rgba(0,0,0,.06); }
    .wa-row { display: flex; margin-bottom: .5rem; }
    .wa-row.out { justify-content: flex-end; }
    .wa-bubble { max-width: 72%; background: #fff; border-radius: .6rem; padding: .45rem .6rem; box-shadow: 0 1px 1px rgba(0,0,0,.08); word-wrap: break-word; }
    .wa-row.out .wa-bubble { background: #d9fdd3; }
    .wa-meta { font-size: .68rem; color: #8696a0; text-align: right; margin-top: .15rem; }
    .wa-sender { font-size: .72rem; color: #667781; margin-bottom: .15rem; }
    .wa-media img, .wa-media video { max-width: 100%; border-radius: .5rem; display: block; }
    .wa-doc { display: flex; align-items: center; gap: .5rem; }
    .wa-doc i { font-size: 1.6rem; color: #54656f; }
    .wa-composer { border-top: 1px solid var(--tblr-border-color, #e6e8eb); background: #f0f2f5; padding: .55rem .7rem; }
    .wa-composer form { display: flex; align-items: flex-end; gap: .4rem; }
    .wa-composer textarea { resize: none; max-height: 120px; }
    .wa-recording { color: #d63939; font-weight: 600; font-size: .8rem; }
    .wa-empty { flex: 1; display: flex; align-items: center; justify-content: center; color: #667781; flex-direction: column; gap: .5rem; }
    @media (max-width: 767.98px) {
        .wa-sidebar { width: 100%; flex-basis: 100%; }
        .wa-main { display: none; }
        .wa-inbox.show-chat .wa-sidebar { display: none; }
        .wa-inbox.show-chat .wa-main { display: flex; }
    }
</style>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="row g-2 align-items-center">
            <div class="col">
                <h2 class="page-title">{{ __('WhatsApp Inbox') }}</h2>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        <div class="wa-inbox" id="waInbox">
            <div class="wa-sidebar">
                <div class="wa-sidebar-head">
                    <div class="input-group input-group-flat">
                        <span class="input-group-text"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg></span>
                        <input type="text" class="form-control" id="waSearch" placeholder="{{ __('Search conversations…') }}" autocomplete="off">
                    </div>
                </div>
                <div class="wa-conv-list" id="waConvList"></div>
            </div>

            <div class="wa-main" id="waMain">
                <div class="wa-empty" id="waEmpty">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="#25d366" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9"/><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1"/></svg>
                    <div>{{ __('Select a conversation to start chatting') }}</div>
                </div>

                <div class="wa-chat-head d-none" id="waChatHead"></div>
                <div class="wa-thread d-none" id="waThread"></div>
                <div class="wa-composer d-none" id="waComposer">
                    <form id="waReplyForm" enctype="multipart/form-data">
                        <input type="file" id="waFileInput" class="d-none"
                               accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt">
                        <button type="button" class="btn btn-icon btn-light" id="waAttachBtn" title="{{ __('Attach') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 1 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 6 6l6.5 -6.5"/></svg>
                        </button>
                        <button type="button" class="btn btn-icon btn-light" id="waRecordBtn" title="{{ __('Record voice note') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 2h6v9a3 3 0 0 1 -6 0z"/><path d="M5 10v2a7 7 0 0 0 14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/></svg>
                        </button>
                        <textarea class="form-control" id="waMessage" rows="1" placeholder="{{ __('Type a message…') }}"></textarea>
                        <button type="submit" class="btn btn-success btn-icon" id="waSendBtn" title="{{ __('Send') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 14l11 -11"/><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5"/></svg>
                        </button>
                    </form>
                    <div class="wa-recording d-none" id="waRecording">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="6" fill="currentColor"/></svg> {{ __('Recording…') }}
                        <span id="waRecordTimer">0:00</span>
                        <button type="button" class="btn btn-sm btn-danger" id="waRecordStop">{{ __('Stop & send') }}</button>
                        <button type="button" class="btn btn-sm btn-light" id="waRecordCancel">{{ __('Cancel') }}</button>
                    </div>
                    <div class="form-text" id="waAttachHint"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.__waInbox = @json($initial);
window.__waRoutes = {
    conversations: "{{ route('whatsapp.inbox.conversations') }}",
    messages: "{{ route('whatsapp.inbox.messages', ['conversation' => '__ID__']) }}",
    reply: "{{ route('whatsapp.inbox.reply', ['conversation' => '__ID__']) }}",
    assign: "{{ route('whatsapp.inbox.assign', ['conversation' => '__ID__']) }}",
    close: "{{ route('whatsapp.inbox.close', ['conversation' => '__ID__']) }}",
    index: "{{ route('whatsapp.inbox.index') }}",
};
window.__waSelectedId = @json($selectedId);
</script>
@endsection

@push('scripts')
<script src="{{ asset('js/whatsapp-inbox.js') . '?v=' . \App\Support\AppVersion::current() }}"></script>
@endpush
