<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Safe to re-run (including in production): existing departments are
     * matched by name and left untouched, so this only ever adds what's missing.
     */
    public function run(): void
    {
        foreach ([
            ['name' => 'Roads and Highways', 'contact_email' => 'roads@council.lk'],
            ['name' => 'Drainage', 'contact_email' => 'drainage@council.lk'],
            ['name' => 'Electrical', 'contact_email' => 'electrical@council.lk'],
            ['name' => 'Waste Management', 'contact_email' => 'waste@council.lk'],
            ['name' => 'Parks and Recreation', 'contact_email' => 'parks@council.lk'],
            ['name' => 'Water Board Liaison', 'contact_email' => 'water@council.lk'],
        ] as $department) {
            Department::firstOrCreate(['name' => $department['name']], $department);
        }
    }
}
