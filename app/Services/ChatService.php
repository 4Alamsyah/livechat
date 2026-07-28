<?php

namespace App\Services;

use App\Events\CallSignalSent;
use App\Events\ConversationClosed;
use App\Events\ConversationStarted;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class ChatService
{
    /**
     * @param  array{property_id?: string|null, visitor_id: string, visitor_name?: string|null, visitor_email?: string|null, topic?: string|null}  $data
     */
    public function startConversation(array $data): Conversation
    {
        $conversation = Conversation::create([
            'property_id' => $data['property_id'] ?? null,
            'visitor_id' => $data['visitor_id'],
            'visitor_name' => $data['visitor_name'] ?? null,
            'visitor_email' => $data['visitor_email'] ?? null,
            'topic' => $data['topic'] ?? null,
            'status' => 'open',
        ]);

        ConversationStarted::dispatch($conversation);

        return $conversation;
    }

    public function sendMessage(
        Conversation $conversation,
        string $senderType,
        ?string $body,
        ?string $senderName = null,
        ?UploadedFile $image = null,
    ): Message {
        $attachmentPath = $image?->store('chat-attachments', 'public');

        $message = $conversation->messages()->create([
            'sender_type' => $senderType,
            'sender_name' => $senderName,
            'type' => $attachmentPath ? 'image' : 'text',
            'body' => $body ?? '',
            'attachment_path' => $attachmentPath,
        ]);

        $conversation->update(['last_message_at' => $message->created_at]);

        MessageSent::dispatch($message->setRelation('conversation', $conversation));

        return $message;
    }

    public function closeConversation(Conversation $conversation, string $closedBy): void
    {
        $conversation->update(['status' => 'closed']);

        ConversationClosed::dispatch($conversation, $closedBy);
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
