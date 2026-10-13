(function () {
    'use strict';

    var state = window.__waInbox || {};
    var routes = window.__waRoutes || {};
    var conversations = state.conversations || [];
    var agents = state.agents || [];
    var selectedId = window.__waSelectedId || null;
    var messages = state.messages || [];
    var lastMessageId = messages.length ? messages[messages.length - 1].id : 0;
    var convTimer = null;
    var msgTimer = null;
    var searchTimer = null;
    var recorder = null;
    var recordChunks = [];
    var recordTimer = null;
    var recordSeconds = 0;
    var recordCancelled = false;
    var listSignature = null;
    var headerKey = null;

    var SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/>';
    var ICON_PATHS = {
        'user': '<circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>',
        'map-pin': '<path d="M21 10c0 7 -9 13 -9 13s-9 -6 -9 -13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'file-text': '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><line x1="9" y1="9" x2="10" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>',
        'arrow-left': '<line x1="5" y1="12" x2="19" y2="12"/><line x1="5" y1="12" x2="11" y2="18"/><line x1="5" y1="12" x2="11" y2="6"/>'
    };
    function icon(name, extraClass) {
        var open = SVG_OPEN.replace('class="icon"', 'class="icon' + (extraClass ? ' ' + extraClass : '') + '"');
        return open + (ICON_PATHS[name] || '') + '</svg>';
    }

    var els = {
        inbox: document.getElementById('waInbox'),
        convList: document.getElementById('waConvList'),
        search: document.getElementById('waSearch'),
        main: document.getElementById('waMain'),
        empty: document.getElementById('waEmpty'),
        head: document.getElementById('waChatHead'),
        thread: document.getElementById('waThread'),
        composer: document.getElementById('waComposer'),
        form: document.getElementById('waReplyForm'),
        message: document.getElementById('waMessage'),
        fileInput: document.getElementById('waFileInput'),
        attachBtn: document.getElementById('waAttachBtn'),
        attachHint: document.getElementById('waAttachHint'),
        recordBtn: document.getElementById('waRecordBtn'),
        recording: document.getElementById('waRecording'),
        recordTimer: document.getElementById('waRecordTimer'),
        recordStop: document.getElementById('waRecordStop'),
        recordCancel: document.getElementById('waRecordCancel'),
        sendBtn: document.getElementById('waSendBtn'),
    };

    if (!els.inbox) {
        return;
    }

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function withId(template, id) {
        return template.replace('__ID__', id);
    }

    function initials(name) {
        name = (name || '?').trim();
        return name.slice(0, 1) || '?';
    }

    function fmtTime(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function fmtDay(iso) {
        var d = new Date(iso);
        var today = new Date();
        var yest = new Date();
        yest.setDate(today.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'Today';
        if (d.toDateString() === yest.toDateString()) return 'Yesterday';
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function fmtSize(bytes) {
        if (!bytes) return '';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        var n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(n >= 10 || i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function ticks(status) {
        if (status === 'read') return '<span style="color:#53bdeb">✓✓</span>';
        if (status === 'delivered') return '✓✓';
        if (status === 'sent') return '✓';
        return '🕓';
    }

    // ---- conversation list -------------------------------------------------
    function renderConversations() {
        if (!conversations.length) {
            els.convList.innerHTML = '<div class="p-4 text-center text-muted">' + escapeHtml('No conversations yet') + '</div>';
            listSignature = 'empty';
            return;
        }

        var signature = selectedId + '|' + JSON.stringify(conversations.map(function (c) {
            return [c.id, c.display_name, c.preview, c.unread, c.last_message_at, c.assigned_name, c.status];
        }));

        if (signature === listSignature) {
            return;
        }

        listSignature = signature;
        var scroll = els.convList.scrollTop;

        els.convList.innerHTML = conversations.map(function (c) {
            var active = c.id === selectedId ? ' active' : '';
            var unread = c.unread > 0 ? '<span class="wa-unread">' + c.unread + '</span>' : '';
            return '<a href="#" class="wa-conv' + active + '" data-id="' + c.id + '">' +
                '<div class="wa-avatar">' + escapeHtml(initials(c.display_name)) + '</div>' +
                '<div class="wa-conv-body">' +
                    '<div class="wa-conv-top"><span class="wa-conv-name">' + escapeHtml(c.display_name) + '</span>' +
                    '<span class="wa-conv-time">' + (c.last_message_at ? fmtTime(c.last_message_at) : '') + '</span></div>' +
                    '<div class="wa-conv-top"><span class="wa-conv-preview">' + escapeHtml(c.preview) + '</span>' + unread + '</div>' +
                '</div></a>';
        }).join('');

        els.convList.scrollTop = scroll;
    }

    els.convList.addEventListener('click', function (event) {
        var link = event.target.closest('.wa-conv');
        if (!link) return;
        event.preventDefault();
        selectConversation(parseInt(link.dataset.id, 10));
    });

    // ---- chat header -------------------------------------------------------
    function headerSignature(conversation) {
        if (!conversation) return null;
        return JSON.stringify([
            conversation.id, conversation.display_name, conversation.wa_id,
            conversation.status, conversation.assigned_user_id, conversation.lead_name,
        ]);
    }

    function renderHead(conversation) {
        if (!conversation) {
            headerKey = null;
            els.head.classList.add('d-none');
            els.thread.classList.add('d-none');
            els.composer.classList.add('d-none');
            els.empty.classList.remove('d-none');
            return;
        }

        headerKey = headerSignature(conversation);
        els.empty.classList.add('d-none');
        els.head.classList.remove('d-none');
        els.thread.classList.remove('d-none');
        els.composer.classList.remove('d-none');

        var options = '<option value="">' + escapeHtml('Unassigned') + '</option>' + agents.map(function (a) {
            return '<option value="' + a.id + '"' + (a.id === conversation.assigned_user_id ? ' selected' : '') + '>' + escapeHtml(a.name) + '</option>';
        }).join('');

        var lead = conversation.lead_name
            ? '<a href="' + conversation.lead_url + '" class="text-muted small ms-2">' + icon('user') + ' ' + escapeHtml(conversation.lead_name) + '</a>'
            : '';

        els.head.innerHTML =
            '<div class="wa-avatar">' + escapeHtml(initials(conversation.display_name)) + '</div>' +
            '<div class="min-w-0">' +
                '<div class="fw-bold text-truncate">' + escapeHtml(conversation.display_name) + '</div>' +
                '<div class="small text-muted">' + escapeHtml(conversation.wa_id) + lead + '</div>' +
            '</div>' +
            '<div class="wa-chat-head-actions">' +
                '<select class="form-select form-select-sm" id="waAssign" style="width:auto;">' + options + '</select>' +
                '<button type="button" class="btn btn-sm btn-light" id="waBack" title="Back">' + icon('arrow-left', 'd-md-none') + '<span class="d-none d-md-inline">' + escapeHtml(conversation.status === 'closed' ? 'Reopen' : 'Close') + '</span></button>' +
            '</div>';

        var assign = document.getElementById('waAssign');
        assign.addEventListener('change', function () {
            fetch(withId(routes.assign, conversation.id), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: this.value ? Number(this.value) : null }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data.success && data.conversation) {
                    applyConversation(data.conversation);
                }
            });
        });

        document.getElementById('waBack').addEventListener('click', function () {
            if (window.matchMedia('(max-width: 767.98px)').matches) {
                els.inbox.classList.remove('show-chat');
            } else {
                toggleClose();
            }
        });
    }

    function applyConversation(conversation) {
        if (!conversation) {
            return;
        }
        var existing = conversations.find(function (c) { return c.id === conversation.id; });
        if (existing) {
            Object.assign(existing, conversation);
        } else {
            conversations.unshift(conversation);
        }
        renderConversations();
        if (conversation.id === selectedId && headerSignature(conversation) !== headerKey) {
            renderHead(conversation);
        }
    }

    function toggleClose() {
        var conversation = conversations.find(function (c) { return c.id === selectedId; });
        if (!conversation) return;
        fetch(withId(routes.close, conversation.id), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.success && data.conversation) {
                conversation.status = data.conversation.status;
                renderHead(conversation);
            }
        });
    }

    // ---- messages ----------------------------------------------------------
    function renderMessages(list) {
        els.thread.innerHTML = renderMessageHtml(list);
        scrollToBottom();
    }

    function renderMessageHtml(list) {
        var html = '';
        var lastDay = null;
        list.forEach(function (m) {
            var day = fmtDay(m.created_at);
            if (day !== lastDay) {
                html += '<div class="wa-day"><span>' + escapeHtml(day) + '</span></div>';
                lastDay = day;
            }
            html += messageHtml(m);
        });
        return html;
    }

    function messageHtml(m) {
        var out = m.direction === 'outbound';
        var body = '<div class="wa-bubble">';

        if (!out && m.sender_name) {
            body += '<div class="wa-sender">' + escapeHtml(m.sender_name) + '</div>';
        }

        body += mediaHtml(m);

        if (m.body) {
            body += '<div>' + escapeHtml(m.body).replace(/\n/g, '<br>') + '</div>';
        }

        body += '<div class="wa-meta">' + escapeHtml(fmtTime(m.created_at)) + (out ? ' ' + ticks(m.status) : '') + '</div>';
        body += '</div>';

        return '<div class="wa-row' + (out ? ' out' : '') + '" data-id="' + m.id + '">' + body + '</div>';
    }

    function mediaHtml(m) {
        var media = m.media || {};
        if (!media.type || !media.url) {
            if (media.type === 'location' && m.location && m.location.latitude) {
                return '<div class="mb-1"><a href="https://maps.google.com/?q=' + m.location.latitude + ',' + m.location.longitude + '" target="_blank" rel="noopener">' + icon('map-pin') + ' ' + escapeHtml('Open location') + '</a></div>';
            }
            return '';
        }

        if (media.type === 'image') {
            return '<div class="wa-media mb-1"><a href="' + media.url + '" target="_blank" rel="noopener"><img src="' + media.url + '" alt=""></a></div>';
        }
        if (media.type === 'sticker') {
            return '<div class="wa-media mb-1"><img src="' + media.url + '" alt="" style="max-width:120px;"></div>';
        }
        if (media.type === 'video') {
            return '<div class="wa-media mb-1"><video controls preload="metadata" src="' + media.url + '"></video></div>';
        }
        if (media.type === 'audio') {
            return '<div class="wa-media mb-1"><audio controls preload="metadata" src="' + media.url + '"></audio></div>';
        }
        if (media.type === 'location') {
            return '<div class="mb-1"><a href="https://maps.google.com/?q=' + (m.location && m.location.latitude) + ',' + (m.location && m.location.longitude) + '" target="_blank" rel="noopener">' + icon('map-pin') + ' ' + escapeHtml(m.body || 'Open location') + '</a></div>';
        }

        return '<div class="wa-doc mb-1">' + icon('file-text') + '<div><a href="' + (media.download_url || media.url) + '" target="_blank" rel="noopener">' +
            escapeHtml(media.name || 'Document') + '</a><div class="small text-muted">' + escapeHtml(fmtSize(media.size)) + '</div></div></div>';
    }

    function appendMessages(list) {
        var fresh = list.filter(function (m) { return m.id > lastMessageId; });
        if (!fresh.length) return;
        var lastDay = null;
        var html = '';
        var currentLastDay = lastMessageId ? (function () {
            var found = messages.concat(fresh).find(function (m) { return m.id === lastMessageId; });
            return found ? fmtDay(found.created_at) : null;
        })() : null;
        lastDay = currentLastDay;

        fresh.forEach(function (m) {
            var day = fmtDay(m.created_at);
            if (day !== lastDay) {
                html += '<div class="wa-day"><span>' + escapeHtml(day) + '</span></div>';
                lastDay = day;
            }
            html += messageHtml(m);
        });

        els.thread.insertAdjacentHTML('beforeend', html);
        messages = messages.concat(fresh);
        lastMessageId = fresh[fresh.length - 1].id;
        scrollToBottom();
    }

    function scrollToBottom() {
        els.thread.scrollTop = els.thread.scrollHeight;
    }

    // ---- selection ---------------------------------------------------------
    function selectConversation(id) {
        selectedId = id;
        var conversation = conversations.find(function (c) { return c.id === id; });
        if (conversation) conversation.unread = 0;
        renderConversations();
        renderHead(conversation);
        els.inbox.classList.add('show-chat');

        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, '', routes.index + '?conversation=' + id);
        }

        messages = [];
        lastMessageId = 0;
        els.thread.innerHTML = '<div class="text-center text-muted p-3">' + escapeHtml('Loading…') + '</div>';

        fetch(withId(routes.messages, id) + '?limit=120', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.conversation) applyConversation(data.conversation);
            messages = data.messages || [];
            lastMessageId = messages.length ? messages[messages.length - 1].id : 0;
            renderMessages(messages);
            els.message.focus();
        }).catch(function () {
            els.thread.innerHTML = '<div class="text-center text-muted p-3">' + escapeHtml('Could not load messages.') + '</div>';
        });
    }

    // ---- polling -----------------------------------------------------------
    function pollConversations() {
        var term = els.search.value.trim();
        var url = routes.conversations + (term ? '?q=' + encodeURIComponent(term) : '');
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                conversations = data.conversations || [];
                if (selectedId) {
                    var current = conversations.find(function (c) { return c.id === selectedId; });
                    if (current) current.unread = 0;
                }
                renderConversations();
            })
            .catch(function () {});
    }

    function pollMessages() {
        if (!selectedId) return;
        fetch(withId(routes.messages, selectedId) + '?after=' + lastMessageId, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.conversation) {
                applyConversation(data.conversation);
            }
            appendMessages(data.messages || []);
        }).catch(function () {});
    }

    els.search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(pollConversations, 300);
    });

    // ---- composer ----------------------------------------------------------
    function autoGrow() {
        els.message.style.height = 'auto';
        els.message.style.height = Math.min(els.message.scrollHeight, 120) + 'px';
    }
    els.message.addEventListener('input', autoGrow);

    els.message.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            els.form.requestSubmit();
        }
    });

    function send(payload, isForm) {
        els.sendBtn.disabled = true;
        var options = {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: payload,
        };
        if (!isForm) {
            options.headers['Content-Type'] = 'application/json';
        }
        return fetch(withId(routes.reply, selectedId), options)
            .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
            .then(function (result) {
                els.sendBtn.disabled = false;
                if (!result.ok || !result.data.success) {
                    if (window.showToast) window.showToast(result.data.message || 'Message was not sent.', 'error');
                    return false;
                }
                appendMessages([result.data.message]);
                applyConversation(conversations.find(function (c) { return c.id === selectedId; }));
                return true;
            })
            .catch(function () {
                els.sendBtn.disabled = false;
                if (window.showToast) window.showToast('Message was not sent.', 'error');
                return false;
            });
    }

    els.form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!selectedId) return;
        var text = els.message.value.trim();
        if (!text) return;
        els.message.value = '';
        autoGrow();
        send(JSON.stringify({ message: text }), false);
    });

    els.attachBtn.addEventListener('click', function () { els.fileInput.click(); });

    els.fileInput.addEventListener('change', function () {
        var file = this.files && this.files[0];
        if (!file || !selectedId) return;
        var type = mediaTypeFor(file);
        var text = els.message.value.trim();
        var form = new FormData();
        form.append('media', file);
        form.append('media_type', type);
        if (text) form.append('message', text);
        els.message.value = '';
        autoGrow();
        this.value = '';
        els.attachHint.textContent = '';
        send(form, true);
    });

    function mediaTypeFor(file) {
        var t = (file.type || '').toLowerCase();
        if (t.indexOf('image/') === 0) return 'image';
        if (t.indexOf('video/') === 0) return 'video';
        if (t.indexOf('audio/') === 0) return 'audio';
        return 'document';
    }

    // ---- voice recording ---------------------------------------------------
    els.recordBtn.addEventListener('click', function () {
        if (recorder) return;
        if (!navigator.mediaDevices || !window.MediaRecorder) {
            if (window.showToast) window.showToast('Voice recording is not supported in this browser.', 'warning');
            return;
        }
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            recordChunks = [];
            recordCancelled = false;
            recorder = new MediaRecorder(stream);
            recorder.addEventListener('dataavailable', function (e) {
                if (e.data.size > 0) recordChunks.push(e.data);
            });
            recorder.addEventListener('stop', function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                clearInterval(recordTimer);
                els.recording.classList.add('d-none');
                els.recordBtn.classList.remove('d-none');
                var wasCancelled = recordCancelled;
                recorder = null;
                if (wasCancelled) {
                    return;
                }
                var blob = new Blob(recordChunks, { type: 'audio/ogg' });
                if (blob.size > 0) {
                    var file = new File([blob], 'voice-note.ogg', { type: 'audio/ogg' });
                    var form = new FormData();
                    form.append('media', file);
                    form.append('media_type', 'audio');
                    send(form, true);
                }
            });
            recorder.start();
            recordSeconds = 0;
            els.recording.classList.remove('d-none');
            els.recordBtn.classList.add('d-none');
            recordTimer = setInterval(function () {
                recordSeconds++;
                var m = Math.floor(recordSeconds / 60);
                var s = recordSeconds % 60;
                els.recordTimer.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }, 1000);
        }).catch(function () {
            if (window.showToast) window.showToast('Microphone permission was denied.', 'warning');
        });
    });

    els.recordStop.addEventListener('click', function () {
        if (recorder) recorder.stop();
    });

    els.recordCancel.addEventListener('click', function () {
        recordCancelled = true;
        if (recorder) recorder.stop();
    });

    // ---- boot --------------------------------------------------------------
    renderConversations();

    if (selectedId) {
        var conversation = conversations.find(function (c) { return c.id === selectedId; });
        if (conversation) {
            conversation.unread = 0;
            renderConversations();
            renderHead(conversation);
            renderMessages(messages);
            els.inbox.classList.add('show-chat');
        } else {
            selectConversation(selectedId);
        }
    } else {
        renderHead(null);
    }

    convTimer = setInterval(pollConversations, 5000);
    msgTimer = setInterval(pollMessages, 4000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            pollConversations();
            pollMessages();
        }
    });
})();
