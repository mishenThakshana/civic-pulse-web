<?php

namespace Database\Factories;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'action' => fake()->randomElement(ActivityAction::cases()),
            'description' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
            'created_at' => now(),
        ];
    }
}
