<?php

namespace App\Services;

use App\Enums\AnnouncementLevel;
use App\Events\AnnouncementCleared;
use App\Events\AnnouncementCreated;
use App\Models\Announcement;
use App\Models\User;

class AnnouncementService
{
    /**
     * @param  array{title?: string|null, message: string, level?: string|null, property_ids?: list<string>|null, expires_in_minutes?: int|null}  $data
     */
    public function create(array $data, User $agent): Announcement
    {
        $propertyIds = array_values(array_filter($data['property_ids'] ?? []));

        $announcement = Announcement::create([
            'title' => $data['title'] ?? null,
            'message' => $data['message'],
            'level' => AnnouncementLevel::tryFrom($data['level'] ?? '') ?? AnnouncementLevel::Warning,
            'property_ids' => $propertyIds ?: null,
            'expires_at' => isset($data['expires_in_minutes'])
                ? now()->addMinutes($data['expires_in_minutes'])
                : null,
            'created_by' => $agent->id,
        ]);

        AnnouncementCreated::dispatch($announcement);

        return $announcement;
    }

    public function deactivate(Announcement $announcement): void
    {
        $announcement->update(['is_active' => false]);

        AnnouncementCleared::dispatch($announcement);
    }
}
