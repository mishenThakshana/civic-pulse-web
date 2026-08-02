<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Citizen,
            'phone' => fake()->numerify('07########'),
            'nic_number' => fake()->numerify('##########V'),
            'nic_photo_front_path' => null,
            'nic_photo_back_path' => null,
            'approval_status' => ApprovalStatus::Pending,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state([
            'role' => UserRole::Admin,
            'approval_status' => ApprovalStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'approval_status' => ApprovalStatus::Approved,
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state([
            'approval_status' => ApprovalStatus::Rejected,
            'rejection_reason' => 'NIC photo was too blurry to verify.',
        ]);
    }
}
