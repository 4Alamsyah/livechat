<?php

namespace Database\Factories;

use App\Models\WidgetSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetSite>
 */
class WidgetSiteFactory extends Factory
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
            'origin' => 'https://'.$this->faker->domainName(),
            'last_seen_at' => now(),
        ];
    }
}
