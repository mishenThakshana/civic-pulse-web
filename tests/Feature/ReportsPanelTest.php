<?php

use App\Enums\ReportStatus;
use App\Models\Department;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportStatusUpdated;
use Illuminate\Support\Facades\Notification;

test('admin can view the report queue with vote counts', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create(['title' => 'Large pothole near the junction']);
    $report->upvotes()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($admin)->get('/reports')
        ->assertOk()
        ->assertSee('Large pothole near the junction')
        ->assertSee('#'.$report->id);
});

test('admin can filter the queue by status', function () {
    $admin = User::factory()->admin()->create();
    Report::factory()->create(['title' => 'Open issue']);
    Report::factory()->resolved()->create(['title' => 'Resolved issue']);

    $response = $this->actingAs($admin)->get('/reports?status=resolved');

    $response->assertOk()->assertSee('Resolved issue')->assertDontSee('Open issue');
});

test('admin can view a single report with its status history', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create();
    $report->statusHistory()->create(['to_status' => ReportStatus::Open, 'changed_by' => $report->user_id, 'note' => 'Report received.']);

    $this->actingAs($admin)->get("/reports/{$report->id}")
        ->assertOk()
        ->assertSee('Report received.');
});

test('updating a report writes a status history entry and notifies the citizen', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $department = Department::factory()->create();
    $report = Report::factory()->create();

    $response = $this->actingAs($admin)->patch("/reports/{$report->id}", [
        'department_id' => $department->id,
        'status' => 'in_progress',
        'note' => 'Material has been ordered.',
    ]);

    $response->assertRedirect();
    $report->refresh();

    expect($report->status)->toBe(ReportStatus::InProgress);
    expect($report->department_id)->toBe($department->id);
    expect($report->statusHistory()->count())->toBe(1);
    expect($report->statusHistory()->first()->note)->toBe('Material has been ordered.');

    Notification::assertSentTo($report->user, ReportStatusUpdated::class);
});

test('a citizen cannot reach the reports panel', function () {
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen)->get('/reports')->assertForbidden();
});
