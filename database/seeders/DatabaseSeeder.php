<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Mishen Mendis',
            'email' => 'admin@civicpulse.test',
        ]);

        User::factory()->staff()->create([
            'name' => 'Staff Member',
            'email' => 'staff@civicpulse.test',
        ]);

        $this->call([
            CategorySeeder::class,
            DepartmentSeeder::class,
        ]);
    }
}
