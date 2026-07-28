<?php

namespace App\Models;

use Database\Factories\WidgetSiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class WidgetSite extends Model
{
    /** @use HasFactory<WidgetSiteFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'property_id',
        'origin',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Record that a widget for this property is live on the given origin.
     */
    public static function heartbeat(string $propertyId, ?string $origin): self
    {
        return static::updateOrCreate(
            ['property_id' => $propertyId, 'origin' => $origin ?? ''],
            ['last_seen_at' => now()],
        );
    }

    /**
     * Every site we know about: those whose widget has reported in, plus any
     * that only ever appeared through a conversation.
     *
     * @return Collection<int, array{property_id: string, origins: list<string>, last_seen_at: string|null}>
     */
    public static function directory(): Collection
    {
        $sites = static::query()
            ->orderBy('property_id')
            ->get(['property_id', 'origin', 'last_seen_at'])
            ->groupBy('property_id')
            ->map(fn ($rows, $propertyId) => [
                'property_id' => $propertyId,
                'origins' => $rows->pluck('origin')->filter()->values()->all(),
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

        return $sites->sortKeys()->values();
    }
}
