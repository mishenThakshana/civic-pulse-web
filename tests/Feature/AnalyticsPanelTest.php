<?php

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use App\Support\Duration;

/**
 * Builds a report created at $createdAt that was picked up after $hoursToRespond
 * and resolved after $hoursToResolve, with a matching status history.
 */
function reportResolvedAfter(int $hoursToRespond, int $hoursToResolve): Report
{
    $createdAt = now()->subDays(20)->startOfHour();
    $report = Report::factory()->resolved()->create();
    $report->forceFill(['created_at' => $createdAt])->saveQuietly();

    $entries = [
        [null, ReportStatus::Open, 0],
        [ReportStatus::Open, ReportStatus::InProgress, $hoursToRespond],
        [ReportStatus::InProgress, ReportStatus::Resolved, $hoursToResolve],
    ];

    foreach ($entries as [$from, $to, $hours]) {
        $report->statusHistory()->create(['from_status' => $from, 'to_status' => $to, 'changed_by' => $report->user_id])
            ->forceFill(['created_at' => $createdAt->copy()->addHours($hours)])
            ->saveQuietly();
    }

    return $report;
}

test('the average resolution time is measured forwards from submission', function () {
    reportResolvedAfter(hoursToRespond: 6, hoursToResolve: 48);
    reportResolvedAfter(hoursToRespond: 10, hoursToResolve: 96);

    expect(Report::averageResolutionDays())->toBe(3.0);
});

test('the analytics page shows positive resolution and first response times', function () {
    $admin = User::factory()->admin()->create();
    reportResolvedAfter(hoursToRespond: 4, hoursToResolve: 72);
    reportResolvedAfter(hoursToRespond: 8, hoursToResolve: 24);

    $this->actingAs($admin)->get('/analytics')
        ->assertOk()
        ->assertSee('6h')
        ->assertDontSee('-6h');
});

test('the dashboard shows a positive average resolution time', function () {
    $admin = User::factory()->admin()->create();
    reportResolvedAfter(hoursToRespond: 2, hoursToResolve: 120);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertSee('5d')
        ->assertDontSee('-5d');
});

test('the median first response measures the first update, not the second newest', function () {
    $admin = User::factory()->admin()->create();
    $createdAt = now()->subDays(3)->startOfHour();
    $report = Report::factory()->inProgress()->create();
    $report->forceFill(['created_at' => $createdAt])->saveQuietly();

    foreach ([[null, ReportStatus::Open, 0], [ReportStatus::Open, ReportStatus::InProgress, 5]] as [$from, $to, $hours]) {
        $report->statusHistory()->create(['from_status' => $from, 'to_status' => $to, 'changed_by' => $report->user_id])
            ->forceFill(['created_at' => $createdAt->copy()->addHours($hours)])
            ->saveQuietly();
    }

    $this->actingAs($admin)->get('/analytics')
        ->assertOk()
        ->assertSee('5h');
});

test('long first response times are shown in days and hours, not raw decimals', function () {
    $admin = User::factory()->admin()->create();
    reportResolvedAfter(hoursToRespond: 313, hoursToResolve: 400);

    $this->actingAs($admin)->get('/analytics')
        ->assertOk()
        ->assertSee('13d 1h')
        ->assertDontSee('313h');
});

test('durations are formatted for the stat cards', function (?float $hours, string $expected) {
    expect(Duration::hours($hours))->toBe($expected);
})->with([
    'none' => [null, '—'],
    'minutes' => [0.25, '15m'],
    'whole hours' => [6.0, '6h'],
    'fractional hours' => [6.54, '6.5h'],
    'days and hours' => [312.927, '13d 1h'],
    'whole days' => [48.0, '2d'],
]);
