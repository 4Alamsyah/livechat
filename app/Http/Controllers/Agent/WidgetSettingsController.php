<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\UpdateWidgetSettingsRequest;
use App\Models\WidgetSetting;
use App\Models\WidgetSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class WidgetSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Agent/WidgetSettings', [
            'sites' => WidgetSite::directory(),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $settings = WidgetSetting::forProperty($request->query('property_id'));

        return response()->json([
            ...$settings->toArray(),
            'logo_url' => $settings->logoUrl(),
        ]);
    }

    public function store(UpdateWidgetSettingsRequest $request): JsonResponse
    {
        $data = collect($request->validated())->except(['logo', 'remove_logo'])->all();

        $settings = WidgetSetting::query()->firstOrNew(['property_id' => $data['property_id']]);

        if ($request->boolean('remove_logo') && $settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
            $data['logo_path'] = null;
        }

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('widget-logos', 'public');
        }

        $settings->fill($data)->save();
        $settings->refresh();

        return response()->json([
            ...$settings->toArray(),
            'logo_url' => $settings->logoUrl(),
        ]);
    }
}
