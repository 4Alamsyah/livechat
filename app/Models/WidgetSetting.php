<?php

namespace App\Models;

use App\Enums\WidgetPosition;
use Carbon\Carbon;
use Database\Factories\WidgetSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class WidgetSetting extends Model
{
    /** @use HasFactory<WidgetSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'property_id',
        'primary_color',
        'position',
        'brand_name',
        'logo_path',
        'welcome_message',
        'require_name',
        'collect_email',
        'require_email',
        'collect_topic',
        'timezone',
        'business_hours_enabled',
        'business_hours',
        'offline_message',
    ];

    protected function casts(): array
    {
        return [
            'position' => WidgetPosition::class,
            'require_name' => 'boolean',
            'collect_email' => 'boolean',
            'require_email' => 'boolean',
            'collect_topic' => 'boolean',
            'business_hours_enabled' => 'boolean',
            'business_hours' => 'array',
        ];
    }

    /**
     * The saved settings for a site, or a transient default-filled instance
     * when the agent has never customized it. Eloquent does not apply the
     * migration's column defaults to an in-memory instance, so a new record
     * needs them filled in explicitly.
     */
    public static function forProperty(?string $propertyId): self
    {
        $settings = static::query()->firstOrNew(['property_id' => $propertyId]);

        if (! $settings->exists) {
            $settings->fill([
                'primary_color' => '#2563eb',
                'position' => WidgetPosition::BottomRight,
                'timezone' => 'Asia/Jakarta',
                'require_name' => false,
                'collect_email' => false,
                'require_email' => false,
                'collect_topic' => false,
                'business_hours_enabled' => false,
            ]);
        }

        return $settings;
    }

    public function isOnlineNow(): bool
    {
        if (! $this->business_hours_enabled) {
            return true;
        }

        $now = Carbon::now($this->timezone ?: 'UTC');
        $day = strtolower($now->format('D'));
        $today = $this->business_hours[$day] ?? null;

        if (! $today || ! ($today['enabled'] ?? false)) {
            return false;
        }

        return $now->format('H:i') >= ($today['start'] ?? '00:00')
            && $now->format('H:i') <= ($today['end'] ?? '23:59');
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }
}
