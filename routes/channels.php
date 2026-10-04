<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    if (! $user) {
        return false;
    }

    return Conversation::find($conversationId)?->isParticipant($user) ?? false;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('tenant.{id}', function ($user, $id) {
    return (int) $user->tenant_id === (int) $id;
});
