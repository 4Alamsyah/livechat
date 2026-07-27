<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\CallSignalRequest;
use App\Http\Requests\Widget\StoreMessageRequest;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MessageController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function index(Conversation $conversation): JsonResponse
    {
        return response()->json(
            $conversation->messages()->oldest()->get(['id', 'sender_type', 'sender_name', 'body', 'created_at'])
        );
    }

    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $senderName = $request->validated('visitor_name') ?? $conversation->visitor_name;

        $message = $this->chat->sendMessage($conversation, 'visitor', $request->validated('body'), $senderName);

        return response()->json($message, 201);
    }

    public function signal(CallSignalRequest $request, Conversation $conversation): Response
    {
        $this->chat->signalCall($conversation, $request->validated('type'), 'visitor', [
            'mode' => $request->validated('mode'),
            'peer_id' => $request->validated('peer_id'),
            'visitor_name' => $request->validated('visitor_name') ?? $conversation->visitor_name,
        ]);

        return response()->noContent();
    }
}
