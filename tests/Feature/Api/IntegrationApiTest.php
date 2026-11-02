<?php

use App\Enums\ActivityAction;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DepartmentApiKey;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportStatusUpdated;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * @return array{0: Department, 1: array<string, string>}
 */
function departmentWithKey(bool $canUpdateReports = true): array
{
    $department = Department::factory()->create(['name' => 'Roads and Highways']);
    [, $plainKey] = DepartmentApiKey::issue($department, 'Works system', $canUpdateReports);

    return [$department, ['Authorization' => "Bearer {$plainKey}", 'Accept' => 'application/json']];
}

test('requests without a valid key are rejected', function () {
    $this->getJson('/api/integrations/v1/reports')->assertUnauthorized()->assertJson(['message' => 'Missing or invalid API key.']);
    $this->getJson('/api/integrations/v1/reports', ['Authorization' => 'Bearer cpk_not-a-real-key'])->assertUnauthorized();
});

test('a citizen sanctum token cannot be used as a department key', function () {
    $citizen = User::factory()->approved()->create();
    $token = $citizen->createToken('phone')->plainTextToken;

    $this->getJson('/api/integrations/v1/reports', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
});

test('a department key cannot be used on the citizen API', function () {
    [, $headers] = departmentWithKey();

    $this->getJson('/api/v1/reports', $headers)->assertUnauthorized();
});

test('the department endpoint identifies the key and its workload', function () {
    [$department, $headers] = departmentWithKey(canUpdateReports: false);
    Report::factory()->count(2)->create(['department_id' => $department->id, 'status' => ReportStatus::Open]);
    Report::factory()->resolved()->create(['department_id' => $department->id]);

    $this->getJson('/api/integrations/v1/department', $headers)
        ->assertOk()
        ->assertJsonPath('data.name', 'Roads and Highways')
        ->assertJsonPath('data.report_counts', ['open' => 2, 'in_progress' => 0, 'resolved' => 1])
        ->assertJsonPath('data.api_key.can_update_reports', false);
});

test('a department only sees reports assigned to it', function () {
    [$department, $headers] = departmentWithKey();
    $ours = Report::factory()->create(['department_id' => $department->id]);
    $theirs = Report::factory()->create(['department_id' => Department::factory()->create()->id]);
    $unassigned = Report::factory()->create();

    $this->getJson('/api/integrations/v1/reports', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ours->id);

    $this->getJson("/api/integrations/v1/reports/{$theirs->id}", $headers)->assertNotFound();
    $this->getJson("/api/integrations/v1/reports/{$unassigned->id}", $headers)->assertNotFound();
});

test('reports do not expose the reporter\'s email or NIC', function () {
    [$department, $headers] = departmentWithKey();
    $citizen = User::factory()->approved()->create(['email' => 'private@example.com']);
    $report = Report::factory()->create(['department_id' => $department->id, 'user_id' => $citizen->id, 'contact_phone' => '0771234567']);

    $response = $this->getJson("/api/integrations/v1/reports/{$report->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.reporter.name', $citizen->name)
        ->assertJsonPath('data.reporter.contact_phone', '0771234567');

    expect($response->getContent())
        ->not->toContain('private@example.com')
        ->not->toContain($citizen->nic_number);
});

test('reports can be filtered by status and category', function () {
    [$department, $headers] = departmentWithKey();
    $match = Report::factory()->inProgress()->create(['department_id' => $department->id]);
    Report::factory()->create(['department_id' => $department->id, 'status' => ReportStatus::Open]);
    Report::factory()->inProgress()->create(['department_id' => $department->id]);

    $this->getJson("/api/integrations/v1/reports?status=in_progress&category_id={$match->category_id}", $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

test('updated_since returns reports changed at or after the timestamp, honouring offsets', function () {
    [$department, $headers] = departmentWithKey();
    $old = Report::factory()->create(['department_id' => $department->id]);
    $old->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
    $recent = Report::factory()->create(['department_id' => $department->id]);
    $recent->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $since = now()->subHour()->setTimezone('Asia/Colombo')->toIso8601String();

    $this->getJson('/api/integrations/v1/reports?updated_since='.urlencode($since), $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $recent->id);
});

test('list parameters are validated', function () {
    [, $headers] = departmentWithKey();

    $this->getJson('/api/integrations/v1/reports?status=closed&sort=random&per_page=500', $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status', 'sort', 'per_page']);
});

test('a single report includes its status history', function () {
    [$department, $headers] = departmentWithKey();
    $report = Report::factory()->create(['department_id' => $department->id]);
    $report->statusHistory()->create(['to_status' => ReportStatus::Open, 'note' => 'Report received.']);

    $this->getJson("/api/integrations/v1/reports/{$report->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.status_history.0.note', 'Report received.')
        ->assertJsonPath('data.location.latitude', $report->latitude);
});

test('a department can post a status update, which notifies the citizen and is audited', function () {
    Notification::fake();
    [$department, $headers] = departmentWithKey();
    $report = Report::factory()->create(['department_id' => $department->id, 'status' => ReportStatus::Open]);

    $this->postJson("/api/integrations/v1/reports/{$report->id}/updates", [
        'status' => 'in_progress',
        'note' => 'Crew scheduled for Thursday.',
    ], $headers)
        ->assertCreated()
        ->assertJsonPath('data.update.to_status', 'in_progress')
        ->assertJsonPath('data.update.changed_by', 'Roads and Highways (via API)')
        ->assertJsonPath('data.report.status', 'in_progress');

    expect($report->refresh()->status)->toBe(ReportStatus::InProgress);

    $entry = $report->statusHistory()->first();
    expect($entry->changed_by)->toBeNull();
    expect($entry->changed_by_department_id)->toBe($department->id);

    Notification::assertSentTo($report->user, ReportStatusUpdated::class);

    $log = ActivityLog::where('action', ActivityAction::ReportUpdated)->sole();
    expect($log->user_id)->toBeNull();
    expect($log->properties['source'])->toBe('api');
    expect($log->description)->toContain('Roads and Highways moved report');
});

test('a status update can include a photo', function () {
    Storage::fake('public');
    [$department, $headers] = departmentWithKey();
    $report = Report::factory()->create(['department_id' => $department->id]);

    $this->post("/api/integrations/v1/reports/{$report->id}/updates", [
        'status' => 'resolved',
        'photo' => UploadedFile::fake()->image('after.jpg'),
    ], $headers)->assertCreated();

    Storage::disk('public')->assertExists($report->statusHistory()->first()->photo_path);
});

test('a read-only key cannot post updates', function () {
    [$department, $headers] = departmentWithKey(canUpdateReports: false);
    $report = Report::factory()->create(['department_id' => $department->id]);

    $this->postJson("/api/integrations/v1/reports/{$report->id}/updates", ['status' => 'resolved'], $headers)
        ->assertForbidden();

    expect($report->refresh()->status)->toBe(ReportStatus::Open);
});

test('a department cannot update another department\'s report', function () {
    [, $headers] = departmentWithKey();
    $report = Report::factory()->create(['department_id' => Department::factory()->create()->id]);

    $this->postJson("/api/integrations/v1/reports/{$report->id}/updates", ['status' => 'resolved'], $headers)
        ->assertNotFound();

    expect($report->refresh()->status)->toBe(ReportStatus::Open);
});

test('a status update requires a valid status', function () {
    [$department, $headers] = departmentWithKey();
    $report = Report::factory()->create(['department_id' => $department->id]);

    $this->postJson("/api/integrations/v1/reports/{$report->id}/updates", ['status' => 'done'], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

test('a revoked key stops working immediately', function () {
    [$department, $headers] = departmentWithKey();

    $this->getJson('/api/integrations/v1/department', $headers)->assertOk();
    $department->apiKeys()->delete();
    $this->getJson('/api/integrations/v1/department', $headers)->assertUnauthorized();
});

test('using a key records when it was last used', function () {
    [$department, $headers] = departmentWithKey();

    $this->getJson('/api/integrations/v1/categories', $headers)->assertOk();

    expect($department->apiKeys()->first()->last_used_at)->not->toBeNull();
});

test('responses carry rate limit headers', function () {
    [, $headers] = departmentWithKey();

    $this->getJson('/api/integrations/v1/categories', $headers)
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 120);
});
