<?php

use App\Enums\ActivityAction;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Category;
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

test('the report page offers every category for correction', function () {
    $staff = User::factory()->staff()->create();
    $flooding = Category::factory()->create(['name' => 'Flooding']);
    $report = Report::factory()->create();

    $this->actingAs($staff)->get("/reports/{$report->id}")
        ->assertOk()
        ->assertSee('name="category_id"', false)
        ->assertSee('<option value="'.$flooding->id.'"', false);
});

test('staff can change a report category and the change is audited', function () {
    Notification::fake();

    $staff = User::factory()->staff()->create();
    $garbage = Category::factory()->create(['name' => 'Garbage']);
    $flooding = Category::factory()->create(['name' => 'Flooding']);
    $report = Report::factory()->create(['category_id' => $garbage->id]);

    $this->actingAs($staff)->patch("/reports/{$report->id}", [
        'category_id' => $flooding->id,
        'status' => 'open',
    ])->assertRedirect();

    expect($report->refresh()->category_id)->toBe($flooding->id);

    $entry = ActivityLog::where('action', ActivityAction::ReportUpdated)->latest('id')->first();
    expect($entry->user_id)->toBe($staff->id);
    expect($entry->description)->toContain('changed its category from Garbage to Flooding');
    expect($entry->properties['from_category'])->toBe('Garbage');
    expect($entry->properties['to_category'])->toBe('Flooding');
});

test('an uncategorised report can be given a category', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $pothole = Category::factory()->create(['name' => 'Pothole']);
    $report = Report::factory()->create(['category_id' => null]);

    $this->actingAs($admin)->patch("/reports/{$report->id}", [
        'category_id' => $pothole->id,
        'status' => 'open',
    ])->assertRedirect();

    expect($report->refresh()->category_id)->toBe($pothole->id);
    expect(ActivityLog::latest('id')->first()->properties['from_category'])->toBe('Uncategorized');
});

test('an unknown category is rejected and the report is unchanged', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $report = Report::factory()->create(['category_id' => $category->id]);

    $this->actingAs($admin)->patch("/reports/{$report->id}", [
        'category_id' => 999999,
        'status' => 'in_progress',
    ])->assertSessionHasErrors('category_id');

    expect($report->refresh()->category_id)->toBe($category->id);
    expect($report->status)->toBe(ReportStatus::Open);
});

test('an update without a category change does not record one', function () {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $report = Report::factory()->create(['category_id' => $category->id]);

    $this->actingAs($admin)->patch("/reports/{$report->id}", [
        'category_id' => $category->id,
        'status' => 'in_progress',
    ])->assertRedirect();

    $entry = ActivityLog::latest('id')->first();
    expect($entry->description)->not->toContain('category');
    expect($entry->properties)->not->toHaveKey('from_category');
});

test('a citizen cannot reach the reports panel', function () {
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen)->get('/reports')->assertForbidden();
});
