<?php

namespace App\Models;

use Database\Factories\WidgetSiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
