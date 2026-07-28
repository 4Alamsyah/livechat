<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WidgetSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetSettingsController extends Controller
{
    /**
     * The public-safe subset of a site's settings the widget needs to render
     * itself, plus whether the site is currently within business hours.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $settings = WidgetSetting::forProperty($request->query('property_id'));

        return response()->json([
            'primary_color' => $settings->primary_color,
            'position' => $settings->position?->value,
            'brand_name' => $settings->brand_name,
            'logo_url' => $settings->logoUrl(),
            'welcome_message' => $settings->welcome_message,
            'require_name' => $settings->require_name,
            'collect_email' => $settings->collect_email,
            'require_email' => $settings->require_email,
            'collect_topic' => $settings->collect_topic,
            'offline_message' => $settings->offline_message,
            'is_online' => $settings->isOnlineNow(),
        ]);
    }
}
