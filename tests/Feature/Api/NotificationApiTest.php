<?php

use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportStatusUpdated;

test('a citizen can list their own notifications', function () {
    $citizen = User::factory()->approved()->create();
    $report = Report::factory()->for($citizen)->inProgress()->create();

    $citizen->notify(new ReportStatusUpdated($report));

    $response = $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/notifications');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.report_id'))->toBe($report->id);
    expect($response->json('data.0.title'))->toBe('Work has started on your report');
});

test('a citizen only sees their own notifications', function () {
    $citizen = User::factory()->approved()->create();
    $other = User::factory()->approved()->create();
    $report = Report::factory()->for($other)->create();

    $other->notify(new ReportStatusUpdated($report));

    $response = $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/notifications');

    $response->assertOk()->assertJsonCount(0, 'data');
});
