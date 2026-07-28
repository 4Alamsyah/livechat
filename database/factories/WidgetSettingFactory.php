<?php

namespace Database\Factories;

use App\Models\WidgetSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetSetting>
 */
class WidgetSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => 'demo-property',
            'primary_color' => '#2563eb',
            'position' => 'bottom-right',
            'timezone' => 'Asia/Jakarta',
            'business_hours_enabled' => false,
            'business_hours' => null,
        ];
    }

    /**
     * @param  array<string, array{enabled: bool, start: string, end: string}>  $hours
     */
    public function withBusinessHours(array $hours): static
    {
        return $this->state(fn () => [
            'business_hours_enabled' => true,
            'business_hours' => $hours,
        ]);
    }
}
