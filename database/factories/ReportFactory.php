<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $description = fake()->sentence(12);

        return [
            'user_id' => User::factory()->approved(),
            'category_id' => Category::factory(),
            'department_id' => null,
            'title' => Str::limit($description, 60),
            'description' => $description,
            'latitude' => fake()->latitude(5.9, 9.9),
            'longitude' => fake()->longitude(79.5, 81.9),
            'address' => fake()->streetAddress(),
            'photo_path' => 'report-photos/'.fake()->uuid().'.jpg',
            'status' => ReportStatus::Open,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(['status' => ReportStatus::InProgress]);
    }

    public function resolved(): static
    {
        return $this->state(['status' => ReportStatus::Resolved]);
    }
}
