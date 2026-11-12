<?php

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;

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
