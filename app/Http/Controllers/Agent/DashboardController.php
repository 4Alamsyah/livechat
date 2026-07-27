<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $conversations = Conversation::query()
            ->with(['agent:id,name'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'uuid', 'property_id', 'visitor_id', 'visitor_name',
                'agent_id', 'status', 'last_message_at', 'created_at',
            ]);

        return Inertia::render('Agent/Dashboard', [
            'conversations' => $conversations,
        ]);
    }
}
