<?php

use App\Enums\ReportStatus;
use App\Models\Department;
use App\Models\Report;
use App\Models\User;

test('admin can view the departments list with workload counts', function () {
    $admin = User::factory()->admin()->create();
    $department = Department::factory()->create(['name' => 'Roads and Highways']);
    Report::factory()->create(['department_id' => $department->id, 'status' => ReportStatus::Open]);

    $this->actingAs($admin)->get('/departments')
        ->assertOk()
        ->assertSee('Roads and Highways');
});

test('admin can add a department', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/departments', [
        'name' => 'Electrical',
        'contact_email' => 'electrical@council.lk',
    ])->assertRedirect();

    expect(Department::where('name', 'Electrical')->exists())->toBeTrue();
});

test('deleting a department leaves its reports unassigned rather than deleting them', function () {
    $admin = User::factory()->admin()->create();
    $department = Department::factory()->create();
    $report = Report::factory()->create(['department_id' => $department->id]);

    $this->actingAs($admin)->delete("/departments/{$department->id}")->assertRedirect();

    expect(Department::find($department->id))->toBeNull();
    expect($report->refresh()->department_id)->toBeNull();
});

test('a department contact email must be valid', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/departments', [
        'name' => 'Waste Management',
        'contact_email' => 'not-an-email',
    ])->assertSessionHasErrors('contact_email');
});
