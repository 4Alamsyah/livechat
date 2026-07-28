<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\WidgetSite;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    public function index(): JsonResponse
    {
        return response()->json(
            Announcement::query()
                ->active()
                ->latest()
                ->limit(20)
                ->get(['id', 'title', 'message', 'level', 'property_ids', 'expires_at', 'created_at'])
        );
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcements->create($request->validated(), $request->user());

        return response()->json($announcement, 201);
    }

    public function deactivate(Announcement $announcement): Response
    {
        $this->announcements->deactivate($announcement);

        return response()->noContent();
    }

    /**
     * Every site we know about: those whose widget has reported in, plus any
     * that only ever appeared through a conversation.
     */
    public function propertyIds(): JsonResponse
    {
        $sites = WidgetSite::query()
            ->orderBy('property_id')
            ->get(['property_id', 'origin', 'last_seen_at'])
            ->groupBy('property_id')
            ->map(fn ($rows, $propertyId) => [
                'property_id' => $propertyId,
                'origins' => $rows->pluck('origin')->filter()->values(),
                'last_seen_at' => $rows->max('last_seen_at')?->toIso8601String(),
            ]);

        Conversation::query()
            ->whereNotNull('property_id')
            ->whereNotIn('property_id', $sites->keys())
            ->distinct()
            ->pluck('property_id')
            ->each(function (string $propertyId) use (&$sites) {
                $sites[$propertyId] = [
                    'property_id' => $propertyId,
                    'origins' => [],
                    'last_seen_at' => null,
                ];
            });

        return response()->json($sites->sortKeys()->values());
    }
}
