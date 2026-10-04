@extends('layouts.app')

@section('title', __('Chat'))
@section('page-title', __('Team Chat'))

@section('breadcrumbs')
<li class="breadcrumb-item active" aria-current="page">{{ __('Chat') }}</li>
@endsection

@push('styles')
<style>
    .chat-bubble .chat-mentioned {
        outline: 2px solid var(--tblr-primary, #206bc4);
        outline-offset: 0;
    }
    #mention-menu .dropdown-item { cursor: pointer; padding: .5rem .75rem; }
    #mention-menu .dropdown-item.active, #mention-menu .dropdown-item:active { background: var(--tblr-primary); color: #fff; }
    #chat-msg-list::-webkit-scrollbar { width: 6px; }
    #chat-msg-list::-webkit-scrollbar-thumb { background: rgba(0,0,0,.15); border-radius: 3px; }
</style>
@endpush

@section('content')
<div class="row g-3" id="chat-app" data-user-id="{{ auth()->id() }}" data-sidebar-url="{{ route('chat.sidebar') }}">
    <div class="col-12 col-lg-4 col-xl-3">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1 text-cyan" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-3 -3h-7a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1h9a1 1 0 0 1 1 1v10"/><path d="M14 15v-2a2 2 0 0 0 -2 -2h-7l-3 3v11l2.5 -2.5"/></svg>
                    {{ __('Chat') }}
                </h3>
                <div class="card-actions">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newChatModal">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        {{ __('New chat') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0" style="overflow-y:auto; max-height: calc(100vh - 300px); min-height: 320px;">
                <div id="chat-conv-list">
                    @include('chat._conv_list', ['activeId' => $conversation?->id])
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8 col-xl-9">
        @if($conversation)
            @include('chat._thread')
        @else
            <div class="card" style="height: calc(100vh - 230px); display:flex; align-items:center; justify-content:center; border:none; background: transparent;">
                <div class="text-center text-muted p-5">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-3" width="56" height="56" viewBox="0 0 24 24" stroke-width="1.25" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-3 -3h-7a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1h9a1 1 0 0 1 1 1v10"/><path d="M14 15v-2a2 2 0 0 0 -2 -2h-7l-3 3v11l2.5 -2.5"/></svg>
                    <h3 class="text-secondary">{{ __('Select a conversation') }}</h3>
                    <p class="small">{{ __('Pick a thread from the list, or start a new chat with a teammate.') }}</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newChatModal">{{ __('Start a new chat') }}</button>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- New chat modal --}}
<div class="modal modal-blur fade" id="newChatModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('Start a new chat') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs nav-fill mb-3" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#newDirectTab" type="button" role="tab">{{ __('Direct message') }}</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#newGroupTab" type="button" role="tab">{{ __('Group chat') }}</button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="newDirectTab" role="tabpanel">
                        <form method="POST" action="{{ route('chat.direct') }}">
                            @csrf
                            <input type="hidden" name="user_id" id="chat-direct-user-id" value="">
                            <div class="input-icon mb-2">
                                <span class="input-icon-addon">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
                                </span>
                                <input type="search" class="form-control" id="chat-direct-search" placeholder="{{ __('Search teammates...') }}" autocomplete="off">
                            </div>
                            <div id="chat-direct-results" style="max-height:260px; overflow-y:auto;"></div>
                            <div class="mt-2 text-end">
                                <button type="submit" class="btn btn-primary" id="chat-direct-submit" disabled>{{ __('Open chat') }}</button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="newGroupTab" role="tabpanel">
                        <form method="POST" action="{{ route('chat.group') }}">
                            @csrf
                            <input type="text" name="title" class="form-control mb-2" placeholder="{{ __('Group name (optional)') }}" maxlength="120">
                            <div class="input-icon mb-2">
                                <span class="input-icon-addon">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
                                </span>
                                <input type="search" class="form-control" id="chat-group-search" placeholder="{{ __('Filter teammates...') }}" autocomplete="off">
                            </div>
                            <div id="chat-group-members" style="max-height:220px; overflow-y:auto;"></div>
                            <div class="mt-2 text-end">
                                <button type="submit" class="btn btn-primary">{{ __('Create group') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/chat.js') . '?v=' . \App\Support\AppVersion::current() }}"></script>
@endpush