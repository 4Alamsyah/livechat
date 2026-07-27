<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StartConversationRequest;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ConversationController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    public function store(StartConversationRequest $request): JsonResponse
    {
        $conversation = $this->chat->startConversation($request->validated());

        return response()->json([
            'uuid' => $conversation->uuid,
            'visitor_name' => $conversation->visitor_name,
            'channel' => 'conversation.'.$conversation->uuid,
        ], 201);
    }

    public function close(Conversation $conversation): Response
    {
        $this->chat->closeConversation($conversation, 'visitor');

        return response()->noContent();
    }
}
