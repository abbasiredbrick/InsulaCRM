/**
 * Team Chat client.
 *
 * Powered by silent polling with an optional Reverb/Pusher upgrade:
 *   - the active thread's messages are fetched incrementally (after=lastId);
 *   - the conversation sidebar is refreshed as an HTML fragment;
 *   - when Echo is available (see layouts/_broadcasting.blade.php) the thread
 *     also listens on private-conversation.{id} and refetches instantly;
 *   - @ mentions are resolved against tenant users and sent as
 *     mentioned_user_ids[] (the server renders them and keeps the mention sets).
 *
 * All rendering is server-authoritative: message bodies arrive as body_html
 * produced by Message::renderBody() (already escaped), so the client never
 * interpolates raw text.
 */
(function () {
    if (window.__chatBooted) { return; }
    window.__chatBooted = true;

    var app = document.getElementById('chat-app');
    if (!app) { return; }

    var CSRF = null;
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) { CSRF = csrfMeta.getAttribute('content'); }

    var CURRENT_USER_ID = parseInt(app.dataset.userId, 10) || 0;
    var POLL_MS = 5000;
    var SIDEBAR_URL = app.dataset.sidebarUrl || '/chat/sidebar';

    var AVATAR_COLORS = ['bg-blue', 'bg-purple', 'bg-orange', 'bg-green', 'bg-cyan', 'bg-pink', 'bg-teal', 'bg-indigo'];

    var convList = document.getElementById('chat-conv-list');
    var thread = document.getElementById('chat-thread');
    var msgList = document.getElementById('chat-msg-list');
    var composer = document.getElementById('chat-composer');
    var input = document.getElementById('chat-input');
    var sendBtn = document.getElementById('chat-send');
    var mentionMenu = document.getElementById('mention-menu');
    var mentionIdsInput = document.getElementById('mention-ids');

    var activeId = thread ? (parseInt(thread.dataset.conversationId, 10) || 0) : 0;
    var lastMsgId = lastMessageId();

    // --- core state -------------------------------------------------------

    function lastMessageId() {
        var max = 0;
        if (!msgList) { return max; }
        var nodes = msgList.querySelectorAll('[data-msg-id]');
        for (var i = 0; i < nodes.length; i++) {
            var id = parseInt(nodes[i].getAttribute('data-msg-id'), 10) || 0;
            if (id > max) { max = id; }
        }
        return max;
    }

    function scrollToBottom(force) {
        if (!msgList) { return; }
        var nearBottom = msgList.scrollHeight - msgList.scrollTop - msgList.clientHeight < 120;
        if (force || nearBottom) {
            msgList.scrollTop = msgList.scrollHeight;
        }
    }

    // --- message rendering ------------------------------------------------

    function payloadToRow(p) {
        var mine = !!p.is_mine;
        var mentionedMe = (p.mention_ids || []).indexOf(CURRENT_USER_ID) !== -1;

        var row = document.createElement('div');
        row.className = 'd-flex ' + (mine ? 'justify-content-end' : 'justify-content-start') + ' mb-3';
        row.setAttribute('data-msg-id', p.id);
        row.setAttribute('data-mentions', JSON.stringify(p.mention_ids || []));
        if (mentionedMe) { row.setAttribute('data-mentioned', '1'); }

        if (!mine) {
            var avatarWrap = document.createElement('span');
            var uid = p.user && p.user.id ? p.user.id : 0;
            avatarWrap.className = 'avatar avatar-sm ' + AVATAR_COLORS[uid % AVATAR_COLORS.length] + ' me-2 flex-shrink-0';
            avatarWrap.textContent = initialsFor(p.user ? p.user.name : '?');
            row.appendChild(avatarWrap);
        }

        var inner = document.createElement('div');
        inner.className = 'd-flex flex-column ' + (mine ? 'align-items-end' : 'align-items-start');
        inner.style.maxWidth = '78%';

        var meta = document.createElement('small');
        meta.className = 'text-muted mb-1 px-1';
        meta.style.fontSize = '11px';
        meta.textContent = (p.user ? p.user.name : 'System') + ' · ' + (p.time_human || '');

        var bubble = document.createElement('div');
        bubble.className = 'rounded-2 px-3 py-2 shadow-sm ' +
            (mine ? 'bg-primary text-white' : 'bg-white') +
            (mentionedMe ? ' chat-mentioned' : '');
        bubble.style.cssText = mine
            ? 'border:1px solid transparent; font-size:0.875rem; line-height:1.5;'
            : 'border:1px solid var(--tblr-border-color); font-size:0.875rem; line-height:1.5;';

        var body = document.createElement('div');
        body.className = 'chat-body';
        body.innerHTML = p.body_html || escapeText(p.body || '');

        bubble.appendChild(body);
        inner.appendChild(meta);
        inner.appendChild(bubble);
        row.appendChild(inner);

        return row;
    }

    function initialsFor(name) {
        if (!name) { return '?'; }
        var parts = String(name).trim().split(/\s+/);
        if (parts.length === 1) { return parts[0].charAt(0).toUpperCase(); }
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }

    function escapeText(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function appendMessage(p) {
        if (!msgList || !p || !p.id) { return; }
        if (msgList.querySelector('[data-msg-id="' + p.id + '"]')) { return; }

        var empty = document.getElementById('chat-msg-empty');
        if (empty) { empty.remove(); }

        msgList.appendChild(payloadToRow(p));
        lastMsgId = Math.max(lastMsgId, p.id);
        scrollToBottom(!!p.is_mine);
    }

    // --- fetching ---------------------------------------------------------

    function fetchMessages() {
        if (!activeId || !thread) { return; }
        var url = thread.dataset.messagesUrl || ('/chat/' + activeId + '/messages');
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        url += sep + 'after=' + lastMsgId;

        fetch(url, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                var msgs = data.messages || [];
                for (var i = 0; i < msgs.length; i++) { appendMessage(msgs[i]); }
            })
            .catch(function () { /* transient; next poll retries */ });
    }

    function fetchSidebar() {
        if (!convList) { return; }
        fetch(SIDEBAR_URL, { headers: { 'Accept': 'text/html', 'X-CSRF-TOKEN': CSRF } })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var frag = document.createDocumentFragment();
                while (doc.body.firstChild) { frag.appendChild(doc.body.firstChild); }
                convList.replaceChildren(frag);
                highlightActive();
            })
            .catch(function () { /* transient; next poll retries */ });
    }

    function highlightActive() {
        if (!convList || !activeId) { return; }
        var items = convList.querySelectorAll('[data-conv-id]');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('bg-azure-lt', parseInt(items[i].getAttribute('data-conv-id'), 10) === activeId);
        }
    }

    function poll() {
        if (document.hidden) { return; }
        fetchSidebar();
        fetchMessages();
    }

    // --- echoing ----------------------------------------------------------

    function subscribeEcho() {
        if (!window.Echo || !activeId) { return; }
        try {
            window.Echo.private('conversation.' + activeId)
                .listen('.message.created', function () { fetchMessages(); });
        } catch (e) { /* polling stays active */ }
    }

    // --- composer ---------------------------------------------------------

    var mentionState = { open: false, term: '', start: -1, users: [], index: 0 };

    function currentMentionIds() {
        var val = mentionIdsInput ? mentionIdsInput.value : '';
        if (!val) { return []; }
        try { return JSON.parse(val); } catch (e) { return []; }
    }

    function setMentionIds(ids) {
        if (mentionIdsInput) { mentionIdsInput.value = JSON.stringify(ids); }
    }

    function resetComposer() {
        if (input) { input.value = ''; }
        setMentionIds([]);
        closeMentionMenu();
        if (input) { input.focus(); }
    }

    function setSendBusy(busy) {
        if (!sendBtn) { return; }
        if (busy) {
            if (sendBtn.dataset.originalHtml === undefined) {
                sendBtn.dataset.originalHtml = sendBtn.innerHTML;
            }
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        } else {
            sendBtn.disabled = false;
            sendBtn.innerHTML = sendBtn.dataset.originalHtml || '';
        }
    }

    var sending = false;

    if (composer && input) {
        composer.addEventListener('submit', function (e) {
            e.preventDefault();
            if (sending) { return; }

            var body = input.value.trim();
            if (!body) { return; }

            var url = composer.dataset.url;
            if (!url) {
                if (typeof window.showToast === 'function') {
                    window.showToast('Send URL is not set — please reload the page.', 'error');
                }
                return;
            }

            var fd = new FormData();
            fd.append('body', bodyinnerHTML);
            var ids = currentMentionIds().filter(function (id) { return id !== CURRENT_USER_ID; });
            for (var i = 0; i < ids.length; i++) { fd.append('mentioned_user_ids[]', ids[i]); }

            sending = true;
            setSendBusy(true);

            fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: fd
            })
                .then(function (res) {
                    if (res.ok) { return res.json(); }
                    return res.json().catch(function () { return {}; }).then(function (data) {
                        var msg = 'HTTP ' + res.status;
                        if (data && data.message) { msg += ': ' + data.message; }
                        else if (data && data.errors) {
                            for (var k in data.errors) {
                                if (Object.prototype.hasOwnProperty.call(data.errors, k)) {
                                    msg += ' — ' + data.errors[k][0];
                                    break;
                                }
                            }
                        }
                        var e = new Error(msg);
                        e.status = res.status;
                        throw e;
                    });
                })
                .then(function (p) {
                    if (p && p.id) {
                        appendMessage(p);
                        resetComposer();
                        fetchSidebar();
                    }
                })
                .catch(function (err) {
                    if (typeof window.showToast === 'function') {
                        window.showToast('Message not sent' + (err && err.message ? ' (' + err.message + ')' : '') + '.', 'error');
                    } else {
                        console.error('Chat send failed', err);
                    }
                })
                .then(function () {
                    sending = false;
                    setSendBusy(false);
                });
        });

        input.addEventListener('keydown', function (e) {
            if (mentionState.open && (e.key === 'Enter' || e.key === 'Tab' || e.key === ' ')) {
                e.preventDefault();
                selectMention(mentionState.index);
                return;
            }
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                composer.dispatchEvent(new Event('submit', { cancelable: true }));
                return;
            }
            if (mentionState.open && e.key === 'ArrowDown') {
                e.preventDefault();
                moveMention(1);
            } else if (mentionState.open && e.key === 'ArrowUp') {
                e.preventDefault();
                moveMention(-1);
            } else if (mentionState.open && (e.key === 'Escape')) {
                e.preventDefault();
                closeMentionMenu();
            }
        });

        input.addEventListener('input', function () { maybeOpenMention(); });
        input.addEventListener('blur', function () {
            setTimeout(closeMentionMenu, 150);
        });
    }

    // --- @ mentions -------------------------------------------------------

    var usersCache = null;
    var usersInFlight = null;

    // Never cache a failed fetch: a transient CSRF/network blip must not leave
    // the @mention menu and the new-chat modal permanently empty. Only a
    // successful response is memoized.
    function getUsers() {
        if (usersCache) { return Promise.resolve(usersCache); }
        if (usersInFlight) { return usersInFlight; }
        usersInFlight = fetch('/chat/users/search?q=', { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
            .then(function (res) { return res.ok ? res.json() : { results: [] }; })
            .then(function (data) {
                usersCache = data.results || [];
                return usersCache;
            })
            .catch(function () { return []; })
            .then(function (result) { usersInFlight = null; return result; });
        return usersInFlight;
    }

    function maybeOpenMention() {
        if (!input || !mentionMenu) { return; }

        var pos = input.selectionStart;
        var text = input.value;

        // Find the last '@' before the caret (skip if preceded by another '@').
        var atIdx = text.lastIndexOf('@', pos - 1);
        if (atIdx === -1) {
            closeMentionMenu();
            return;
        }
        var sinceAt = text.slice(atIdx + 1, pos);
        // '@' must sit at word start (or after whitespace) to count as a mention.
        var before = text.slice(atIdx - 1, atIdx);
        if (before !== '' && !/[\s@]/.test(before)) {
            closeMentionMenu();
            return;
        }
        if (/\s/.test(sinceAt)) {
            closeMentionMenu();
            return;
        }

        mentionState.open = false;
        mentionState.term = sinceAt.toLowerCase();
        mentionState.start = atIdx + 1;

        getUsers().then(function (users) {
            var term = mentionState.term;
            var filtered = users.filter(function (u) {
                return u.id !== CURRENT_USER_ID &&
                    (!term || u.name.toLowerCase().indexOf(term) !== -1 ||
                        (u.email || '').toLowerCase().indexOf(term) !== -1);
            });
            if (!filtered.length) { closeMentionMenu(); return; }
            mentionState.open = true;
            mentionState.users = filtered;
            mentionState.index = 0;
            renderMentionMenu();
        });
    }

    function renderMentionMenu() {
        if (!mentionMenu) { return; }
        mentionMenu.innerHTML = '';
        mentionState.users.forEach(function (u, i) {
            var item = document.createElement('a');
            item.className = 'dropdown-item d-flex align-items-center gap-2';
            item.href = '#';
            if (i === mentionState.index) { item.classList.add('active'); }

            var avatar = document.createElement('span');
            avatar.className = 'avatar avatar-xs ' + AVATAR_COLORS[(u.id || 0) % AVATAR_COLORS.length];
            avatar.textContent = initialsFor(u.name);

            var info = document.createElement('span');
            info.className = 'd-block';
            info.innerHTML = '<span class="d-block small fw-semibold">' + escapeText(u.name) + '</span>' +
                '<span class="d-block text-secondary" style="font-size:11px;">' +
                escapeText(u.email || '') + (u.role ? ' · ' + escapeText(u.role) : '') + '</span>';

            item.appendChild(avatar);
            item.appendChild(info);
            item.addEventListener('mousedown', function (ev) { ev.preventDefault(); selectMention(i); });
            mentionMenu.appendChild(item);
        });
        mentionMenu.style.display = 'block';
    }

    function moveMention(dir) {
        if (!mentionState.open) { return; }
        var n = mentionState.users.length;
        mentionState.index = (mentionState.index + dir + n) % n;
        renderMentionMenu();
    }

    function selectMention(index) {
        if (!mentionState.open || !input) { return; }
        var user = mentionState.users[index];
        if (!user) { return; }

        var start = mentionState.start;
        var end = input.selectionStart;
        var text = input.value;
        var prefix = text.slice(0, start - 1);
        var suffix = text.slice(end);
        input.value = prefix + '@' + user.name + ' ' + suffix;

        var ids = currentMentionIds().filter(function (id) { return id !== user.id; });
        ids.push(user.id);
        setMentionIds(ids);

        var cursor = input.value.length - suffix.length;
        input.setSelectionRange(cursor, cursor);
        closeMentionMenu();
        input.focus();
    }

    function closeMentionMenu() {
        mentionState.open = false;
        if (mentionMenu) { mentionMenu.style.display = 'none'; mentionMenu.innerHTML = ''; }
    }

    // --- new chat modal ---------------------------------------------------

    function wireNewChatModal() {
        var modal = document.getElementById('newChatModal');
        var directResults = document.getElementById('chat-direct-results');
        var directInput = document.getElementById('chat-direct-search');
        var directUserId = document.getElementById('chat-direct-user-id');
        var directSubmit = document.getElementById('chat-direct-submit');
        var groupMembers = document.getElementById('chat-group-members');
        var groupInput = document.getElementById('chat-group-search');

        if (!modal || !directResults || !groupMembers) { return; }

        var allUsers = [];

        function renderDirect(filtered) {
            directResults.innerHTML = '';
            if (!filtered.length) {
                directResults.innerHTML = '<div class="text-muted small text-center py-3">' + 'No matches' + '</div>';
                return;
            }
            filtered.forEach(function (u) {
                if (u.id === CURRENT_USER_ID) { return; }
                var item = document.createElement('a');
                item.href = '#';
                item.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2 rounded-2 mb-1 text-decoration-none text-reset';
                var avatar = document.createElement('span');
                avatar.className = 'avatar avatar-sm ' + AVATAR_COLORS[(u.id || 0) % AVATAR_COLORS.length];
                avatar.textContent = initialsFor(u.name);
                var info = document.createElement('span');
                info.className = 'd-block';
                info.innerHTML = '<span class="d-block small fw-semibold">' + escapeText(u.name) + '</span>' +
                    '<span class="d-block text-secondary" style="font-size:11px;">' +
                    escapeText(u.role || '') + (u.agent_code ? ' · ' + escapeText(u.agent_code) : '') + '</span>';
                item.appendChild(avatar);
                item.appendChild(info);
                item.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    var siblings = directResults.querySelectorAll('.active');
                    for (var s = 0; s < siblings.length; s++) { siblings[s].classList.remove('active'); }
                    item.classList.add('active');
                    directUserId.value = u.id;
                    if (directSubmit) { directSubmit.disabled = false; }
                });
                directResults.appendChild(item);
            });
        }

        function renderGroup(filtered) {
            groupMembers.innerHTML = '';
            if (!filtered.length) {
                groupMembers.innerHTML = '<div class="text-muted small text-center py-3">' + 'No matches' + '</div>';
                return;
            }
            filtered.forEach(function (u) {
                if (u.id === CURRENT_USER_ID) { return; }
                var label = document.createElement('label');
                label.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2 rounded-2 mb-1';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'user_ids[]';
                cb.value = u.id;
                cb.className = 'form-check-input m-0 flex-shrink-0';
                var avatar = document.createElement('span');
                avatar.className = 'avatar avatar-sm ' + AVATAR_COLORS[(u.id || 0) % AVATAR_COLORS.length];
                avatar.textContent = initialsFor(u.name);
                var info = document.createElement('span');
                info.className = 'd-block';
                info.textContent = u.name;
                label.appendChild(cb);
                label.appendChild(avatar);
                label.appendChild(info);
                groupMembers.appendChild(label);
            });
        }

        modal.addEventListener('shown.bs.modal', function () {
            getUsers().then(function (users) {
                allUsers = users;
                renderGroup(users);
                if (directInput) { directInput.value = ''; }
                renderDirect(users);
            });
        });

        if (directInput) {
            directInput.addEventListener('input', function () {
                var term = directInput.value.toLowerCase();
                renderDirect(allUsers.filter(function (u) {
                    return !term || u.name.toLowerCase().indexOf(term) !== -1 || (u.email || '').toLowerCase().indexOf(term) !== -1;
                }));
            });
        }

        if (groupInput) {
            groupInput.addEventListener('input', function () {
                var term = groupInput.value.toLowerCase();
                renderGroup(allUsers.filter(function (u) {
                    return !term || u.name.toLowerCase().indexOf(term) !== -1 || (u.email || '').toLowerCase().indexOf(term) !== -1;
                }));
            });
        }
    }

    // --- boot -------------------------------------------------------------

    function boot() {
        subscribeEcho();
        if (!msgList && !convList) { return; }

        // Immediate first pass so late-arriving messages are visible without waiting.
        fetchSidebar();
        fetchMessages();

        var timer = window.setInterval(poll, POLL_MS);

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                // Fresh data the moment the tab regains focus.
                fetchSidebar();
                fetchMessages();
            }
        });

        document.addEventListener('insulacrm:live-updated', function () {
            // Nothing swaps the chat page today, but keep this cheap guard in line
            // with the rest of the app.
        });

        // Re-subscribe if a broadcast reconnects later (Echo loads on DOMContentLoaded).
        window.setTimeout(subscribeEcho, 1500);
        window._chatPollTimer = timer;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            wireNewChatModal();
            boot();
        });
    } else {
        wireNewChatModal();
        boot();
    }
})();