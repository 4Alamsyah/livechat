<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreAnnouncementRequest;
use App\Models\Announcement;
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

    public function propertyIds(): JsonResponse
    {
        return response()->json(WidgetSite::directory());
    }
}
