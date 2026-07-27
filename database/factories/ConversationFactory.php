<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
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
            'visitor_id' => (string) $this->faker->uuid(),
            'visitor_name' => $this->faker->name(),
            'status' => 'open',
        ];
    }
}
