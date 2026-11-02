<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\DepartmentApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DepartmentApiKey>
 */
class DepartmentApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plainKey = DepartmentApiKey::PREFIX.Str::random(40);

        return [
            'department_id' => Department::factory(),
            'name' => fake()->words(2, true).' integration',
            'key_prefix' => substr($plainKey, 0, 12),
            'key_hash' => DepartmentApiKey::hash($plainKey),
            'can_update_reports' => true,
        ];
    }

    public function readOnly(): static
    {
        return $this->state(['can_update_reports' => false]);
    }
}
