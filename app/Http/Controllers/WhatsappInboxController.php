<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationManager;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\LeadSearchService;
use App\Services\WhatsApp\WhatsAppConversationService;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class WhatsappInboxController extends Controller
{
    protected array $mediaTypes = ['image', 'video', 'audio', 'document'];

    public function __construct(
        protected WhatsAppConversationService $conversationService,
        protected WhatsAppMediaService $mediaService,
        protected IntegrationManager $integrationManager,
        protected LeadSearchService $leadSearch,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeInbox();
        $user = Auth::user();

        $conversations = $this->scopedConversations($user)->get();

        $selected = null;

        if ($request->query('conversation')) {
            $selected = $conversations->firstWhere('id', (int) $request->query('conversation'));
            $this->authorizeConversation($selected);
        }

        if ($selected) {
            $conversations = $conversations->reject(fn ($c) => $c->id !== $selected->id)->push($selected);
        }

        $unread = $this->conversationService->unreadCounts($user->id, $conversations->pluck('id')->all());

        $list = $conversations->map(fn ($conversation) => $this->conversationArray($conversation, $unread[$conversation->id] ?? 0))->values();

        $selectedData = null;
        $initialMessages = [];

        if ($selected) {
            $selectedData = $this->conversationArray($selected, 0);

            $rows = WhatsappMessage::where('whatsapp_conversation_id', $selected->id)
                ->orderBy('id', 'asc')
                ->limit(120)
                ->get();

            $this->conversationService->markSeen(
                $selected,
                $user->id,
                $rows->where('direction', 'inbound')->max('id'),
            );

            $initialMessages = $rows->map(fn ($message) => $this->messageArray($message, $selected))->values();
        }

        return view('whatsapp.inbox.index', [
            'initial' => [
                'conversations' => $list,
                'selected' => $selectedData,
                'messages' => $initialMessages,
                'agents' => $this->inboxAgents($user)->map(fn ($agent) => ['id' => $agent->id, 'name' => $agent->name])->values(),
                'currentUserId' => $user->id,
            ],
            'selectedId' => $selected?->id,
        ]);
    }

    public function show(WhatsappConversation $conversation, Request $request)
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        return redirect()->route('whatsapp.inbox.index', ['conversation' => $conversation->id]);
    }

    /**
     * Live list for the left pane: scoped, searchable, with unread counts.
     */
    public function conversations(Request $request): JsonResponse
    {
        $this->authorizeInbox();
        $user = Auth::user();

        $query = $this->scopedConversations($user);

        if (filled($term = trim((string) $request->query('q', '')))) {
            $query->where(function ($search) use ($term) {
                $search->where('display_name', 'like', "%{$term}%")
                    ->orWhere('wa_id', 'like', "%{$term}%")
                    ->orWhere(function ($lead) use ($term) {
                        $this->leadSearch->applyRelatedTerm($lead, 'lead', $term);
                    });
            });
        }

        $conversations = $query->limit(50)->get();
        $unread = $this->conversationService->unreadCounts($user->id, $conversations->pluck('id')->all());

        $result = $conversations->map(function ($conversation) use ($unread) {
            return $this->conversationArray($conversation, $unread[$conversation->id] ?? 0);
        })->values();

        return response()->json(['conversations' => $result]);
    }

    /**
     * Messages for the chat pane. Supports `after=<id>` to only fetch rows newer
     * than an id (used by the polls). Opening a conversation advances the user's
     * read watermark to the newest inbound message.
     */
    public function messages(WhatsappConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);
        $user = Auth::user();

        $query = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->orderBy('id', 'asc');

        if (filled($after = (int) $request->query('after', 0))) {
            $query->where('id', '>', $after);
        }

        $rows = $query->limit((int) $request->query('limit', 120))->get();

        $newestInbound = $rows->where('direction', 'inbound')->sortByDesc('id')->first();
        $previousSeen = (int) $this->conversationService->seenMessageId($conversation, $user->id);

        if ($newestInbound && (int) $newestInbound->id > $previousSeen && $newestInbound->wamid) {
            try {
                $this->integrationManager
                    ->getWhatsAppProvider($conversation->tenant_id)
                    ->markAsRead($conversation->wa_id, $newestInbound->wamid);
            } catch (\Throwable $e) {
                // A failed read receipt must never break loading the inbox.
            }
        }

        $this->conversationService->markSeen($conversation, $user->id, $newestInbound?->id);

        return response()->json([
            'conversation' => $this->conversationArray($conversation, 0),
            'messages' => $rows->map(fn ($message) => $this->messageArray($message, $conversation))->values(),
        ]);
    }

    public function reply(WhatsappConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);
        $user = Auth::user();

        $message = trim((string) $request->input('message', ''));
        $upload = $request->file('media');

        if ($message === '' && ! $upload) {
            return $this->replyError(__('Type a message before sending.'));
        }

        if (! $conversation->assigned_user_id) {
            $conversation->assigned_user_id = $user->id;
            $conversation->save();
        }

        $provider = $this->integrationManager->getWhatsAppProvider($user->tenant_id);

        // -- text reply ------------------------------------------------------
        if (! $upload) {
            $wamid = $provider->sendText($conversation->wa_id, $message);

            if ($wamid === null) {
                return $this->replyError(__('WhatsApp could not send the message. Check the WhatsApp integration configuration.'));
            }

            $saved = $this->conversationService->addOutboundMessage($conversation, $user->id, $message, $wamid);

            return response()->json([
                'success' => true,
                'message' => $this->messageArray($saved, $conversation),
            ]);
        }

        // -- media reply -----------------------------------------------------
        $mediaType = strtolower((string) $request->input('media_type', ''));

        if (! in_array($mediaType, $this->mediaTypes, true)) {
            return $this->replyError(__('Unsupported attachment type.'));
        }

        if ($upload->getSize() > 25600000) {
            return $this->replyError(__('Attachment is too large (max 25 MB).'));
        }

        try {
            $media = $this->mediaService->storeOutboundUpload($upload, $conversation);
        } catch (\Throwable $e) {
            return $this->replyError(__('The attachment could not be stored.'));
        }

        $wamid = $provider->sendMedia(
            $conversation->wa_id,
            Storage::disk('local')->path($media['path']),
            $mediaType,
            $message !== '' ? $message : null,
            $media['mime'],
            $media['name'],
        );

        if ($wamid === null) {
            Storage::disk('local')->delete($media['path']);

            return $this->replyError(__('WhatsApp could not send the attachment. Check the WhatsApp integration configuration.'));
        }

        $saved = $this->conversationService->addOutboundMediaMessage(
            $conversation,
            $user->id,
            $mediaType,
            $message,
            $media,
            $wamid,
        );

        return response()->json([
            'success' => true,
            'message' => $this->messageArray($saved, $conversation),
        ]);
    }

    /**
     * Stream a stored media file. Only forms that live under this conversation's
     * directory are served, and only to users who can see the conversation.
     */
    public function media(WhatsappConversation $conversation, Request $request)
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $file = basename((string) $request->query('file', ''));
        $dir = sprintf('whatsapp/%d/%d', $conversation->tenant_id, $conversation->id);
        $path = $dir.'/'.$file;

        $disk = Storage::disk('local');

        if ($file === '' || ! $disk->exists($path)) {
            abort(404);
        }

        $message = WhatsappMessage::where('whatsapp_conversation_id', $conversation->id)
            ->where('media_path', $path)
            ->first();

        $mime = (string) ($message?->media_mime ?: 'application/octet-stream');
        $inlineSafe = (bool) preg_match('#^image/|^video/|^audio/#', $mime);

        if (! $inlineSafe) {
            $mime = 'application/octet-stream';
        }

        $disposition = ($request->query('download', '') === '1' || ! $inlineSafe) ? 'attachment' : 'inline';

        return $disk->response($path, $file, [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$file.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function assign(WhatsappConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $validated = $request->validate([
            'user_id' => 'nullable|integer',
        ]);

        $target = null;

        if (! empty($validated['user_id'])) {
            $target = User::where('tenant_id', Auth::user()->tenant_id)
                ->whereKey($validated['user_id'])
                ->firstOrFail();
        }

        $conversation->assigned_user_id = $target?->id;
        $conversation->save();

        return response()->json([
            'success' => true,
            'conversation' => $this->conversationArray($conversation, 0),
        ]);
    }

    public function close(WhatsappConversation $conversation): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $conversation->status = $conversation->status === 'closed' ? 'open' : 'closed';
        $conversation->save();

        return response()->json([
            'success' => true,
            'conversation' => $this->conversationArray($conversation, 0),
        ]);
    }

    protected function scopedConversations(User $user)
    {
        $query = WhatsappConversation::where('tenant_id', $user->tenant_id)
            ->with(['lead', 'assignedUser', 'latestMessage'])
            ->orderBy('last_message_at', 'desc')
            ->orderByDesc('id');

        if (! $this->seesEveryConversation($user)) {
            $query->where(function ($outer) use ($user) {
                $outer->where('assigned_user_id', $user->id)
                    ->orWhereNull('assigned_user_id');
            });
        }

        return $query;
    }

    protected function conversationArray(WhatsappConversation $conversation, int $unread): array
    {
        $latest = $conversation->relationLoaded('latestMessage') ? $conversation->latestMessage : $conversation->latestMessage()->first();

        return [
            'id' => $conversation->id,
            'wa_id' => $conversation->wa_id,
            'display_name' => $conversation->display_name ?: $conversation->wa_id,
            'lead_id' => $conversation->lead_id,
            'lead_name' => $conversation->lead?->full_name,
            'lead_url' => $conversation->lead ? route('leads.show', $conversation->lead) : null,
            'assigned_user_id' => $conversation->assigned_user_id,
            'assigned_name' => $conversation->assignedUser?->name,
            'status' => $conversation->status,
            'unread' => $unread,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'preview' => $latest ? $latest->previewText() : '',
        ];
    }

    protected function messageArray(WhatsappMessage $message, WhatsappConversation $conversation): array
    {
        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
            'sender_name' => $message->user?->name,
            'status' => $message->status,
            'media' => [
                'type' => $message->media_type,
                'url' => $message->media_path
                    ? route('whatsapp.inbox.media', ['conversation' => $conversation->id, 'file' => basename($message->media_path)])
                    : null,
                'download_url' => $message->media_path
                    ? route('whatsapp.inbox.media', ['conversation' => $conversation->id, 'file' => basename($message->media_path), 'download' => '1'])
                    : null,
                'name' => $message->media_name,
                'mime' => $message->media_mime,
                'size' => $message->media_size,
            ],
            'location' => [
                'latitude' => $message->meta['latitude'] ?? null,
                'longitude' => $message->meta['longitude'] ?? null,
            ],
        ];
    }

    /**
     * Agents that can be assigned conversations (admins and inbox-enabled agents).
     */
    protected function inboxAgents(User $user): \Illuminate\Support\Collection
    {
        return User::where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('whatsapp_inbox_enabled', true)->orWhere('whatsapp_inbox_admin', true))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    protected function replyError(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }

    protected function authorizeInbox(): void
    {
        $user = Auth::user();

        if (! $user->whatsapp_inbox_enabled && ! $this->seesEveryConversation($user)) {
            abort(403, 'WhatsApp Inbox is not enabled for your account.');
        }
    }

    /**
     * Admins/owners and designated inbox managers can see every conversation;
     * everyone else only their own and the unassigned queue.
     */
    protected function seesEveryConversation(User $user): bool
    {
        return $user->isAdmin() || $user->whatsapp_inbox_admin;
    }

    protected function authorizeConversation(WhatsappConversation $conversation): void
    {
        $user = Auth::user();

        if (! $conversation) {
            abort(404);
        }

        abort_if($conversation->tenant_id !== $user->tenant_id, 404);

        if ($this->seesEveryConversation($user)) {
            return;
        }

        abort_unless(
            $conversation->assigned_user_id === null || $conversation->assigned_user_id === $user->id,
            403,
        );
    }
}
