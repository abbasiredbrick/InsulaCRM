{{-- Native-Feel Mobile App Shell (Header, Bottom Tab Bar, FAB, and Bottom Sheets) --}}
@php
    $currentMode = $businessMode ?? 'wholesale';
    $isRealEstate = $currentMode === 'realestate';
    $user = auth()->user();
    $isSubPage = !request()->is('dashboard') && !request()->is('leads') && !request()->is('pipeline') && !request()->is('calendar') && !request()->is('inventory') && !request()->is('showings');
@endphp

<!-- Mobile App Top Bar -->
<header id="mobile-app-header" class="mobile-only-header">
    <div class="mobile-header-inner">
        <div class="mobile-header-left">
            @if($isSubPage)
                <button type="button" class="mobile-btn-icon" onclick="history.length > 1 ? history.back() : window.location.href='{{ route('dashboard') }}'" aria-label="{{ __('Back') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M15 6l-6 6l6 6" />
                    </svg>
                </button>
            @else
                <button type="button" class="mobile-btn-icon mobile-avatar-btn" onclick="MobileApp.openDrawer()" aria-label="{{ __('Menu') }}">
                    <span class="mobile-avatar-circle">
                        {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
                    </span>
                </button>
            @endif
        </div>

        <div class="mobile-header-center">
            <h1 class="mobile-page-title">
                @yield('page-title', config('app.name'))
            </h1>
            <span class="mobile-tenant-badge">
                {{ $user->tenant->name ?? config('app.name') }}
            </span>
        </div>

        <div class="mobile-header-right">
            <!-- Quick Add -->
            <button type="button" class="mobile-btn-icon mobile-btn-new" onclick="MobileApp.openActions()" aria-label="{{ __('New') }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
            </button>
            <!-- Search Trigger -->
            <button type="button" class="mobile-btn-icon" onclick="MobileApp.openSearch()" aria-label="{{ __('Search') }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <circle cx="10" cy="10" r="7" />
                    <line x1="21" y1="21" x2="15" y2="15" />
                </svg>
            </button>

            <!-- Notifications Trigger -->
            <a href="{{ route('notifications.index') }}" class="mobile-btn-icon position-relative" aria-label="{{ __('Notifications') }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M10 5a2 2 0 0 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" />
                    <path d="M9 17v1a3 3 0 0 0 6 0v-1" />
                </svg>
                <span class="mobile-notif-dot" id="mobile-notif-dot" style="display:none;"></span>
            </a>
        </div>
    </div>
</header>

<!-- Mobile Bottom Navigation Bar -->
<nav id="mobile-bottom-nav" class="mobile-only-bottom-nav" aria-label="{{ __('Mobile navigation') }}">
    <div class="mobile-nav-items">
        <!-- Tab 1: Home -->
        <a href="{{ route('dashboard') }}" class="mobile-nav-tab {{ request()->is('dashboard') ? 'active' : '' }}" data-tab="home">
            <span class="mobile-tab-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <polyline points="5 12 3 12 12 3 21 12 19 12" />
                    <path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" />
                    <path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" />
                </svg>
            </span>
            <span class="mobile-tab-label">{{ __('Home') }}</span>
        </a>

        <!-- Tab 2: Leads -->
        @if($user->canManageLeads())
        <a href="{{ route('leads.index') }}" class="mobile-nav-tab {{ request()->is('leads*') ? 'active' : '' }}" data-tab="leads">
            <span class="mobile-tab-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <circle cx="9" cy="7" r="4" />
                    <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
                </svg>
            </span>
            <span class="mobile-tab-label">{{ __('Leads') }}</span>
        </a>
        @endif

        <!-- Tab 3: Center FAB (Quick Action) -->
        <div class="mobile-fab-container">
            <button type="button" class="mobile-fab-btn" id="mobile-fab-trigger" onclick="MobileApp.openActions()" aria-label="{{ __('Quick Actions') }}">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon fab-icon-plus" width="26" height="26" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                </svg>
            </button>
        </div>

        <!-- Tab 4: Pipeline / Inventory -->
        @if($isRealEstate)
            <a href="{{ route('inventory.index') }}" class="mobile-nav-tab {{ request()->is('inventory*') || request()->is('listings*') ? 'active' : '' }}" data-tab="inventory">
                <span class="mobile-tab-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M3 21l18 0" />
                        <path d="M5 21v-14l8 -4v18" />
                        <path d="M19 21v-10l-6 -4" />
                        <path d="M9 9l0 .01" />
                        <path d="M9 12l0 .01" />
                        <path d="M9 15l0 .01" />
                    </svg>
                </span>
                <span class="mobile-tab-label">{{ __('Inventory') }}</span>
            </a>
        @else
            <a href="{{ route('pipeline') }}" class="mobile-nav-tab {{ request()->is('pipeline*') ? 'active' : '' }}" data-tab="pipeline">
                <span class="mobile-tab-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <rect x="4" y="4" width="6" height="16" rx="1" />
                        <rect x="14" y="4" width="6" height="10" rx="1" />
                    </svg>
                </span>
                <span class="mobile-tab-label">{{ __('Pipeline') }}</span>
            </a>
        @endif

        <!-- Tab 5: More Drawer -->
        <button type="button" class="mobile-nav-tab" onclick="MobileApp.openDrawer()" data-tab="more" aria-label="{{ __('More Options') }}">
            <span class="mobile-tab-icon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <line x1="4" y1="6" x2="20" y2="6" />
                    <line x1="4" y1="12" x2="20" y2="12" />
                    <line x1="4" y1="18" x2="20" y2="18" />
                </svg>
            </span>
            <span class="mobile-tab-label">{{ __('More') }}</span>
        </button>
    </div>
</nav>

<!-- iOS-style Bottom Action Bar (mobile only) — primary form actions
     promoted here, pinned above the bottom nav. Populated by mobile-app.js. -->
<div id="mobile-action-bar" class="mobile-action-bar" hidden></div>

<!-- Backdrop Overlay for Mobile Sheets -->
<div id="mobile-sheet-backdrop" class="mobile-backdrop" onclick="MobileApp.closeAllSheets()"></div>

<!-- Quick Action Bottom Sheet -->
<div id="mobile-action-sheet" class="mobile-bottom-sheet" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="action-sheet-title">
    <div class="sheet-handle-bar" onclick="MobileApp.closeActions()"></div>
    <div class="sheet-header">
        <h2 id="action-sheet-title" class="sheet-title">{{ __('Quick Actions') }}</h2>
        <button type="button" class="sheet-close-btn" onclick="MobileApp.closeActions()" aria-label="{{ __('Close') }}">&times;</button>
    </div>
    <div class="sheet-body">
        <div class="quick-action-grid">
            @if($user->canManageLeads())
            <a href="{{ route('leads.create') }}" class="quick-action-card">
                <span class="action-icon bg-primary-lt text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z"/><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/><path d="M16 11h6M19 8v6"/></svg>
                </span>
                <span class="action-label">{{ __('Add Lead') }}</span>
                <span class="action-sub">{{ __('Seller or buyer lead') }}</span>
            </a>
            @endif

            @if($isRealEstate)
            <a href="{{ route('showings.create') }}" class="quick-action-card">
                <span class="action-icon bg-teal-lt text-teal">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg>
                </span>
                <span class="action-label">{{ __('Schedule Viewing') }}</span>
                <span class="action-sub">{{ __('Book client viewing') }}</span>
            </a>
            <a href="{{ route('inventory.create') }}" class="quick-action-card">
                <span class="action-icon bg-blue-lt text-blue">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z"/><path d="M3 21l18 0"/><path d="M5 21v-14l8 -4v18"/><path d="M19 21v-10l-6 -4"/></svg>
                </span>
                <span class="action-label">{{ __('New Unit') }}</span>
                <span class="action-sub">{{ __('Add to inventory') }}</span>
            </a>
            @else
            <a href="{{ route('properties.index') }}" class="quick-action-card">
                <span class="action-icon bg-indigo-lt text-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z"/><polyline points="5 12 3 12 12 3 21 12 19 12"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/></svg>
                </span>
                <span class="action-label">{{ __('Property') }}</span>
                <span class="action-sub">{{ __('Browse or add') }}</span>
            </a>
            @endif

            <a href="{{ route('calendar.index') }}" class="quick-action-card">
                <span class="action-icon bg-purple-lt text-purple">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z"/><rect x="4" y="5" width="16" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="4" y1="11" x2="20" y2="11"/><rect x="8" y="15" width="2" height="2"/></svg>
                </span>
                <span class="action-label">{{ __('Calendar') }}</span>
                <span class="action-sub">{{ __('Tasks & appointments') }}</span>
            </a>
        </div>
    </div>
</div>

<!-- Native "More" App Drawer (Side/Bottom Sheet) -->
<aside id="mobile-more-drawer" class="mobile-drawer" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="drawer-user-name">
    <div class="drawer-header">
        <div class="d-flex align-items-center gap-3">
            <span class="mobile-avatar-circle large">
                {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
            </span>
            <div class="drawer-user-info">
                <h2 id="drawer-user-name" class="drawer-user-name m-0">{{ $user->name }}</h2>
                <div class="drawer-user-role small text-muted">
                    {{ __(ucwords(str_replace('_', ' ', $user->role->name ?? 'agent'))) }}
                    &bull; <span class="badge {{ $isRealEstate ? 'bg-teal-lt text-teal' : 'bg-blue-lt text-blue' }}">{{ $isRealEstate ? __('Real Estate') : __('Wholesale') }}</span>
                </div>
            </div>
        </div>
        <button type="button" class="drawer-close-btn" onclick="MobileApp.closeDrawer()" aria-label="{{ __('Close') }}">&times;</button>
    </div>

    <div class="drawer-body">
        <div class="drawer-nav-group">
            <div class="drawer-group-title">{{ __('Daily Operations') }}</div>
            <a href="{{ route('dashboard') }}" class="drawer-nav-item {{ request()->is('dashboard') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><polyline points="5 12 3 12 12 3 21 12 19 12"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/></svg></span>
                <span class="drawer-item-text">{{ __('Dashboard') }}</span>
            </a>
            @if($user->canManageLeads())
            <a href="{{ route('leads.index') }}" class="drawer-nav-item {{ request()->is('leads*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/></svg></span>
                <span class="drawer-item-text">{{ __('Leads') }}</span>
            </a>
            @endif
            @if($isRealEstate)
            <a href="{{ route('showings.index') }}" class="drawer-nav-item {{ request()->is('showings*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6"/></svg></span>
                <span class="drawer-item-text">{{ __('Viewings') }}</span>
            </a>
            <a href="{{ route('open-houses.index') }}" class="drawer-nav-item {{ request()->is('open-houses*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M5 12l-2 0l9 -9l9 9l-2 0"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/></svg></span>
                <span class="drawer-item-text">{{ __('Open Houses') }}</span>
            </a>
            <a href="{{ route('leases.index') }}" class="drawer-nav-item {{ request()->is('leases*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M7 7m-2 0a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M17 7m-2 0a2 2 0 1 0 4 0a2 2 0 0 0 -4 0"/><path d="M5 19h14"/></svg></span>
                <span class="drawer-item-text">{{ __('Leases') }}</span>
            </a>
            <a href="{{ route('inventory.index') }}" class="drawer-nav-item {{ request()->is('inventory*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M3 21l18 0"/><path d="M5 21v-14l8 -4v18"/><path d="M19 21v-10l-6 -4"/></svg></span>
                <span class="drawer-item-text">{{ __('Inventory') }}</span>
            </a>
            @else
            <a href="{{ route('pipeline') }}" class="drawer-nav-item {{ request()->is('pipeline*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><rect x="4" y="4" width="6" height="16" rx="1"/><rect x="14" y="4" width="6" height="10" rx="1"/></svg></span>
                <span class="drawer-item-text">{{ __('Deals & Pipeline') }}</span>
            </a>
            <a href="{{ route('buyers.index') }}" class="drawer-nav-item {{ request()->is('buyers*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M3 21l18 0"/><path d="M9 8h1"/><path d="M9 12h1"/><path d="M9 16h1"/><path d="M14 8h1"/><path d="M14 12h1"/><path d="M14 16h1"/><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16"/></svg></span>
                <span class="drawer-item-text">{{ __('Cash Buyers') }}</span>
            </a>
            @endif
            <a href="{{ route('calendar.index') }}" class="drawer-nav-item {{ request()->is('calendar*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><rect x="4" y="5" width="16" height="16" rx="2"/><line x1="16" y1="3" x2="16" y2="7"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="4" y1="11" x2="20" y2="11"/></svg></span>
                <span class="drawer-item-text">{{ __('Calendar & Sync') }}</span>
            </a>
            <a href="{{ route('activities.index') }}" class="drawer-nav-item {{ request()->is('activities*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M3 7m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z"/><path d="M3 7l9 6l9 -6"/></svg></span>
                <span class="drawer-item-text">{{ __('Activity Inbox') }}</span>
            </a>
        </div>

        <div class="drawer-nav-group">
            <div class="drawer-group-title">{{ __('Account & Preferences') }}</div>
            <a href="{{ route('my-cloud.show') }}" class="drawer-nav-item {{ request()->is('my-cloud*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M6.657 18c-2.572 0 -4.657 -2.007 -4.657 -4.483c0 -2.475 2.085 -4.482 4.657 -4.482c.393 -1.762 1.794 -3.2 3.675 -3.708c2.613 -.709 5.378 .742 6.136 3.238c1.867 .14 3.342 1.637 3.342 3.469c0 1.916 -1.612 3.473 -3.6 3.473l-9.553 .093z"/></svg></span>
                <span class="drawer-item-text">{{ __('My Cloud (Google / Microsoft)') }}</span>
            </a>
            <a href="{{ route('profile.edit') }}" class="drawer-nav-item {{ request()->is('profile*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 10m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0"/><path d="M6.168 18.849a4 4 0 0 1 3.832 -2.849h4a4 4 0 0 1 3.834 2.855"/></svg></span>
                <span class="drawer-item-text">{{ __('My Profile') }}</span>
            </a>
            @if($user->isAdmin())
            <a href="{{ route('reports.index') }}" class="drawer-nav-item {{ request()->is('reports*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                <span class="drawer-item-text">{{ __('Reports & Analytics') }}</span>
            </a>
            <a href="{{ route('settings.index') }}" class="drawer-nav-item {{ request()->is('settings*') ? 'active' : '' }}">
                <span class="drawer-item-icon"><svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><circle cx="12" cy="12" r="3"/></svg></span>
                <span class="drawer-item-text">{{ __('Settings') }}</span>
            </a>
            @endif
        </div>

        <!-- System Controls -->
        <div class="drawer-actions">
            <button type="button" class="btn btn-outline-secondary w-100 mb-2 d-flex align-items-center justify-content-center gap-2" onclick="toggleTheme()">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z"/></svg>
                <span>{{ __('Toggle Dark / Light') }}</span>
            </button>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline-danger w-100 d-flex align-items-center justify-content-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"/><path d="M9 12h12l-3 -3"/><path d="M18 15l3 -3"/></svg>
                    <span>{{ __('Logout') }}</span>
                </button>
            </form>
        </div>
    </div>
</aside>

<!-- Mobile Live Search Modal Sheet -->
<div id="mobile-search-sheet" class="mobile-search-sheet" aria-hidden="true">
    <div class="mobile-search-header">
        <form action="{{ route('search') }}" method="GET" autocomplete="off" class="mobile-search-form" onsubmit="MobileApp.onSearchSubmit(event)">
            <div class="mobile-search-input-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon search-input-icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><circle cx="10" cy="10" r="7"/><line x1="21" y1="21" x2="15" y2="15"/></svg>
                <input type="search" id="mobile-search-input" name="q" class="mobile-search-input" placeholder="{{ $isRealEstate ? __('Search leads, units, clients...') : __('Search leads, deals, buyers...') }}" autofocus>
                <button type="button" class="mobile-search-clear" onclick="MobileApp.clearSearch()" style="display:none;">&times;</button>
            </div>
        </form>
        <button type="button" class="mobile-search-cancel" onclick="MobileApp.closeSearch()">{{ __('Cancel') }}</button>
    </div>
    <div id="mobile-search-results" class="mobile-search-body">
        <div class="text-center text-muted py-5 small" id="mobile-search-hint">
            {{ __('Type at least 2 characters to search across all records...') }}
        </div>
        <div id="mobile-search-list" class="mobile-search-list"></div>
    </div>
</div>
