<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\CallSignalRequest;
use App\Http\Requests\Agent\StoreMessageRequest;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ConversationController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function messages(Conversation $conversation): JsonResponse
    {
        $this->chat->assignAgent($conversation, auth()->user());

        return response()->json(
            $conversation->messages()->oldest()->get(['id', 'sender_type', 'sender_name', 'body', 'created_at'])
        );
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $agent = $request->user();

        $this->chat->assignAgent($conversation, $agent);

        $message = $this->chat->sendMessage($conversation, 'agent', $request->validated('body'), $agent->name);

        return response()->json($message, 201);
    }

    public function signal(CallSignalRequest $request, Conversation $conversation): Response
    {
        $agent = $request->user();

        $this->chat->signalCall($conversation, $request->validated('type'), 'agent', [
            'mode' => $request->validated('mode'),
            'peer_id' => $request->validated('peer_id'),
            'agent_name' => $agent->name,
        ]);

        return response()->noContent();
    }

    public function close(Conversation $conversation): Response
    {
        $conversation->update(['status' => 'closed']);

        return response()->noContent();
    }
}
