<?php

use App\Enums\ActivityAction;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\Report;
use App\Models\User;

test('signing in and out is recorded', function () {
    $admin = User::factory()->admin()->create(['password' => bcrypt('password')]);

    $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
    $this->post('/logout');

    expect(ActivityLog::where('user_id', $admin->id)->pluck('action')->all())
        ->toBe([ActivityAction::SignedIn, ActivityAction::SignedOut]);
});

test('citizen review actions are recorded against the acting account', function () {
    $staff = User::factory()->staff()->create();
    $citizen = User::factory()->create(['name' => 'Nimal Perera']);

    $this->actingAs($staff)->post(route('citizens.reject', $citizen), ['reason' => 'Blurry NIC photo.']);

    $entry = ActivityLog::sole();
    expect($entry->action)->toBe(ActivityAction::CitizenRejected);
    expect($entry->user_id)->toBe($staff->id);
    expect($entry->subject->is($citizen))->toBeTrue();
    expect($entry->properties['reason'])->toBe('Blurry NIC photo.');
    expect($entry->description)->toContain('Nimal Perera');
});

test('report status changes are recorded', function () {
    $admin = User::factory()->admin()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Open]);

    $this->actingAs($admin)->patch(route('reports.update', $report), ['status' => 'in_progress']);

    $entry = ActivityLog::where('action', ActivityAction::ReportUpdated)->sole();
    expect($entry->description)->toContain('from Open to In Progress');
    expect($entry->properties['to_status'])->toBe('in_progress');
});

test('category and department changes are recorded', function () {
    $admin = User::factory()->admin()->create();
    $category = Category::factory()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)->post('/categories', ['name' => 'Street Lights']);
    $this->actingAs($admin)->delete(route('categories.destroy', $category));
    $this->actingAs($admin)->patch(route('departments.update', $department), ['name' => 'Water Board', 'contact_email' => 'water@council.lk']);

    expect(ActivityLog::pluck('action')->all())->toBe([
        ActivityAction::CategoryCreated,
        ActivityAction::CategoryDeleted,
        ActivityAction::DepartmentUpdated,
    ]);
});

test('admin can view the activity log', function () {
    $admin = User::factory()->admin()->create(['name' => 'Audit Admin']);
    ActivityLog::factory()->for($admin)->create(['action' => ActivityAction::CitizenApproved, 'description' => 'Approved Kasun\'s registration.']);

    $this->actingAs($admin)->get('/activity-log')
        ->assertOk()
        ->assertSee('Approved Kasun', false)
        ->assertSee('Audit Admin')
        ->assertSee('Citizen approved');
});

test('the activity log can be filtered by account and action', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    ActivityLog::factory()->for($admin)->create(['action' => ActivityAction::CategoryCreated, 'description' => 'Admin entry']);
    ActivityLog::factory()->for($staff)->create(['action' => ActivityAction::ReportUpdated, 'description' => 'Staff entry']);

    $this->actingAs($admin)->get('/activity-log?user_id='.$staff->id)
        ->assertSee('Staff entry')
        ->assertDontSee('Admin entry');

    $this->actingAs($admin)->get('/activity-log?action=category.created')
        ->assertSee('Admin entry')
        ->assertDontSee('Staff entry');
});

test('the activity log can be filtered by date range', function () {
    $admin = User::factory()->admin()->create();
    ActivityLog::factory()->for($admin)->create(['description' => 'Old entry', 'created_at' => now()->subDays(10)]);
    ActivityLog::factory()->for($admin)->create(['description' => 'Recent entry', 'created_at' => now()->subDay()]);

    $this->actingAs($admin)->get('/activity-log?from='.now()->subDays(3)->toDateString())
        ->assertSee('Recent entry')
        ->assertDontSee('Old entry');
});

test('entries survive the deletion of the account that made them', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();
    ActivityLog::factory()->for($staff)->create(['description' => 'Something the staff member did']);

    $this->actingAs($admin)->delete(route('staff.destroy', $staff));

    $this->actingAs($admin)->get('/activity-log')
        ->assertSee('Something the staff member did')
        ->assertSee('Deleted account');
});
