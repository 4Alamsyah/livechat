<?php

namespace App\Models;

use App\Enums\AnnouncementLevel;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'message',
        'level',
        'property_ids',
        'expires_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'level' => AnnouncementLevel::class,
            'property_ids' => 'array',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<Announcement>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Active announcements reaching the given property, including untargeted
     * ones, which reach every site.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeMatchingProperty(Builder $query, ?string $propertyId): void
    {
        $query->active()->where(function (Builder $query) use ($propertyId) {
            $query->whereNull('property_ids')->orWhereJsonLength('property_ids', 0);

            if ($propertyId !== null) {
                $query->orWhereJsonContains('property_ids', $propertyId);
            }
        });
    }
}
