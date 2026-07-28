<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * The latest announcement reaching this property, for widgets that load
     * after it was broadcast.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $announcement = Announcement::query()
            ->matchingProperty($request->query('property_id'))
            ->latest()
            ->first(['id', 'title', 'message', 'level', 'property_ids', 'expires_at']);

        return response()->json($announcement);
    }
}
