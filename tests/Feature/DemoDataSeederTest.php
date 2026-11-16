<?php

use App\Enums\ApprovalStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->photos = storage_path('app/demo-photos');
    File::deleteDirectory($this->photos);

    foreach (['pothole', 'road-damage', 'damaged-sign', 'garbage', 'flooding', 'blocked-drain', 'broken-street-light', 'fallen-tree', 'damaged-public-property', 'other', 'nic'] as $directory) {
        File::ensureDirectoryExists("{$this->photos}/{$directory}");
    }

    foreach (glob("{$this->photos}/*") as $directory) {
        $names = basename($directory) === 'nic'
            ? ['sample_1_front', 'sample_1_back', 'sample_2_front', 'sample_2_back', 'sample_3_front', 'sample_3_back']
            : ['photo'];

        foreach ($names as $name) {
            File::put("{$directory}/{$name}.jpg", 'jpg');
        }
    }

    User::factory()->admin()->create();
});

afterEach(fn () => File::deleteDirectory($this->photos));

test('the demo seeder fills an empty database with consistent data', function () {
    $this->seed(DemoDataSeeder::class);

    foreach (['citizen.en@civicpulse.test', 'citizen.si@civicpulse.test', 'citizen.ta@civicpulse.test'] as $email) {
        $citizen = User::where('email', $email)->firstOrFail();
        expect($citizen->approval_status)->toBe(ApprovalStatus::Approved)
            ->and($citizen->reports()->count())->toBeGreaterThan(0);
    }

    expect(User::where('role', UserRole::Citizen)->where('approval_status', ApprovalStatus::Pending)->count())->toBe(2)
        ->and(Report::count())->toBe(32);

    Report::with('statusHistory')->get()->each(function (Report $report) {
        $history = $report->statusHistory->sortBy('created_at');
        expect($history->first()->to_status)->toBe(ReportStatus::Open)
            ->and($history->last()->to_status)->toBe($report->status)
            ->and($history->last()->created_at->isFuture())->toBeFalse();
        Storage::disk('public')->assertExists($report->photo_path);
    });

    $this->actingAs(User::where('role', UserRole::Admin)->first())->get('/analytics')->assertOk();
});

test('the demo seeder refuses to run on a database that already has reports', function () {
    Report::factory()->create();

    $this->seed(DemoDataSeeder::class);
})->throws(RuntimeException::class);
