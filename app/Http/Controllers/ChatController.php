<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(protected ChatService $chat) {}

    /**
     * Messenger page: conversation sidebar + optional active thread.
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $conversations = $this->chat->conversationsFor($user);

        $conversation = null;
        if ($request->integer('conversation')) {
            $candidate = Conversation::find($request->integer('conversation'));
            if ($candidate && $candidate->isParticipant($user)) {
                $conversation = $candidate;
                $conversation->markRead($user);
            }
        }

        return view('chat.index', [
            'conversations' => $conversations,
            'conversation' => $conversation,
            'unreadTotal' => $this->chat->unreadCount($user),
        ]);
    }

    /**
     * Lead-scoped entry: open (or create) the thread attached to the lead and
     * jump into the messenger focused on it.
     */
    public function leadChat(Lead $lead)
    {
        $this->authorize('view', $lead);

        $conversation = $this->chat->leadConversation($lead, auth()->user());

        return redirect()->route('chat.show', $conversation);
    }

    /**
     * Deep link straight into a conversation (used by notifications).
     */
    public function show(Conversation $conversation)
    {
        abort_unless($conversation->isParticipant(auth()->user()), 403);

        $conversation->markRead(auth()->user());

        return view('chat.index', [
            'conversations' => $this->chat->conversationsFor(auth()->user()),
            'conversation' => $conversation,
            'unreadTotal' => $this->chat->unreadCount(auth()->user()),
        ]);
    }

    /**
     * Paginated/incremental message feed for a thread.
     */
    public function messages(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->isParticipant(auth()->user()), 403);

        if ($request->filled('after')) {
            $collection = $conversation->messages()
                ->with(['author:id,name', 'mentions:id,name'])
                ->where('id', '>', $request->integer('after'))
                ->oldest('id')
                ->get();
        } else {
            $collection = $conversation->messages()
                ->with(['author:id,name', 'mentions:id,name'])
                ->latest('id')
                ->limit(200)
                ->get()
                ->reverse();
        }

        // Thread is on screen — advance the read marker.
        $conversation->markRead(auth()->user());

        return response()->json([
            'messages' => $collection->map(fn (Message $m) => $this->payload($m))->values(),
            'unread' => $conversation->unreadCountFor(auth()->user()),
        ]);
    }

    /**
     * Send a message to a conversation (AJAX).
     */
    public function storeMessage(Request $request, Conversation $conversation)
    {
        abort_unless($conversation->isParticipant(auth()->user()), 403);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'mentioned_user_ids' => ['nullable', 'array', 'max:10'],
            'mentioned_user_ids.*' => ['integer'],
        ]);

        $message = $this->chat->sendMessage(
            auth()->user(),
            $conversation,
            $validated['body'],
            $validated['mentioned_user_ids'] ?? [],
        );

        return response()->json($this->payload($message));
    }

    /**
     * Re-usable sidebar fragment used by the live polling refresh.
     */
    public function sidebar()
    {
        $conversations = $this->chat->conversationsFor(auth()->user());

        return view('chat._conv_list', [
            'conversations' => $conversations,
            'unreadTotal' => $this->chat->unreadCount(auth()->user()),
        ]);
    }

    /**
     * Mark a thread as read (AJAX).
     */
    public function read(Conversation $conversation)
    {
        abort_unless($conversation->isParticipant(auth()->user()), 403);

        $conversation->markRead(auth()->user());

        return response()->json(['success' => true]);
    }

    /**
     * Total unread count across all conversations (for the lead Chat button badge).
     */
    public function unread()
    {
        return response()->json(['unread' => $this->chat->unreadCount(auth()->user())]);
    }

    /**
     * Team members the user can message/mention.
     */
    public function searchUsers(Request $request)
    {
        $term = mb_strtolower(trim((string) $request->input('q')));

        $users = $this->chat->conversationableUsers()->filter(function (User $user) use ($term) {
            if ($term === '') {
                return true;
            }

            return str_contains(mb_strtolower($user->name), $term)
                || str_contains(mb_strtolower((string) $user->email), $term);
        });

        return response()->json([
            'results' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'value' => $user->id,
                'label' => $user->name,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'agent_code' => $user->agent_code,
            ])->values(),
        ]);
    }

    /**
     * Start (or reuse) a direct conversation, then open it.
     */
    public function startDirect(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $other = User::where('tenant_id', auth()->user()->tenant_id)
            ->where('id', $validated['user_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $leadId = ! empty($validated['lead_id'])
            ? Lead::find($validated['lead_id'])?->id
            : null;

        $conversation = $this->chat->findOrCreateDirect(auth()->user(), $other, $leadId);

        return redirect()->route('chat.show', $conversation);
    }

    /**
     * Create a group conversation, then open it.
     */
    public function startGroup(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer'],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $leadId = ! empty($validated['lead_id'])
            ? Lead::find($validated['lead_id'])?->id
            : null;

        $conversation = $this->chat->createGroup(
            auth()->user(),
            $validated['user_ids'],
            $validated['title'] ?? null,
            $leadId,
        );

        return redirect()->route('chat.show', $conversation);
    }

    /**
     * JSON shape consumed by the chat client.
     */
    protected function payload(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'body' => $message->body,
            'body_html' => $message->renderBody(),
            'preview' => $message->preview(),
            'user' => [
                'id' => $message->author?->id,
                'name' => $message->author?->name ?? __('System'),
            ],
            'is_mine' => $message->user_id === auth()->id(),
            'mention_ids' => $message->mentions->pluck('id')->all(),
            'created_at' => $message->created_at?->toIso8601String(),
            'time_human' => $message->created_at?->diffForHumans(),
        ];
    }
}
