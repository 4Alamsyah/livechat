<?php

namespace App\Services;

use App\Events\CallSignalSent;
use App\Events\ConversationStarted;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ChatService
{
    /**
     * @param  array{property_id?: string|null, visitor_id: string, visitor_name?: string|null}  $data
     */
    public function startConversation(array $data): Conversation
    {
        $conversation = Conversation::create([
            'property_id' => $data['property_id'] ?? null,
            'visitor_id' => $data['visitor_id'],
            'visitor_name' => $data['visitor_name'] ?? null,
            'status' => 'open',
        ]);

        ConversationStarted::dispatch($conversation);

        return $conversation;
    }

    public function sendMessage(Conversation $conversation, string $senderType, string $body, ?string $senderName = null): Message
    {
        $message = $conversation->messages()->create([
            'sender_type' => $senderType,
            'sender_name' => $senderName,
            'body' => $body,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        MessageSent::dispatch($message->setRelation('conversation', $conversation));

        return $message;
    }

    public function assignAgent(Conversation $conversation, User $agent): void
    {
        if ($conversation->agent_id === null) {
            $conversation->update(['agent_id' => $agent->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function signalCall(Conversation $conversation, string $type, string $from, array $extra = []): void
    {
        CallSignalSent::dispatch($conversation, [
            'type' => $type,
            'from' => $from,
            ...$extra,
        ]);
    }
}
