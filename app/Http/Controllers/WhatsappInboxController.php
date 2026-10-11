<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationManager;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsappInboxController extends Controller
{
    public function __construct(
        protected WhatsAppConversationService $conversationService
    ) {}

    public function index(Request $request)
    {
        $this->authorizeInbox();
        $user = Auth::user();

        $query = WhatsappConversation::where('tenant_id', $user->tenant_id)
            ->with(['lead', 'assignedUser'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at');

        if (! $this->seesEveryConversation($user)) {
            $query->where(function ($outer) use ($user) {
                $outer->where('assigned_user_id', $user->id)
                    ->orWhereNull('assigned_user_id');
            });
        }

        $conversations = $query->paginate(20);

        return view('whatsapp.inbox.index', compact('conversations'));
    }

    public function show(WhatsappConversation $conversation, Request $request)
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $conversation->load(['lead', 'assignedUser', 'messages' => function ($q) {
            $q->orderBy('created_at', 'asc')->orderBy('id', 'asc');
        }]);

        return view('whatsapp.inbox.show', compact('conversation'));
    }

    public function reply(WhatsappConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $request->validate([
            'message' => 'required|string|max:4096',
        ]);

        $user = Auth::user();

        if (! $conversation->assigned_user_id) {
            $conversation->assigned_user_id = $user->id;
            $conversation->save();
        }

        $message = $request->input('message');

        $provider = app(IntegrationManager::class)->getWhatsAppProvider($user->tenant_id);
        $wamid = $provider->sendText($conversation->wa_id, $message);

        if ($wamid === null) {
            return response()->json([
                'success' => false,
                'message' => __('WhatsApp could not send the message. Check the WhatsApp integration configuration.'),
            ], 422);
        }

        $this->conversationService->addOutboundMessage($conversation, $user->id, $message, $wamid);

        return response()->json(['success' => true]);
    }

    public function assign(WhatsappConversation $conversation, Request $request): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $target = User::where('tenant_id', Auth::user()->tenant_id)
            ->whereKey($validated['user_id'])
            ->firstOrFail();

        $conversation->assigned_user_id = $target->id;
        $conversation->save();

        return response()->json(['success' => true]);
    }

    public function close(WhatsappConversation $conversation): JsonResponse
    {
        $this->authorizeInbox();
        $this->authorizeConversation($conversation);

        $conversation->status = 'closed';
        $conversation->save();

        return response()->json(['success' => true]);
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
