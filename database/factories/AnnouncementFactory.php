<?php

namespace Database\Factories;

use App\Enums\AnnouncementLevel;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Scheduled maintenance',
            'message' => $this->faker->sentence(),
            'level' => AnnouncementLevel::Warning,
            'property_ids' => null,
            'expires_at' => null,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * @param  list<string>  $propertyIds
     */
    public function targeting(array $propertyIds): static
    {
        return $this->state(fn () => ['property_ids' => $propertyIds]);
    }
}
