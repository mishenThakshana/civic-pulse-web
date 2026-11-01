<?php

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function aiAnalysis(bool $isSpam, array $reasons = []): array
{
    return [
        'spam' => ['is_suspicious' => $isSpam, 'probability' => $isSpam ? 0.95 : 0.03, 'reasons' => $reasons],
        'requires_human_review' => $isSpam,
    ];
}

function validReportPayload(): array
{
    return [
        'description' => 'Large pothole near the junction, dangerous for motorcycles.',
        'latitude' => 6.8412,
        'longitude' => 79.8731,
        'address' => 'Galle Road, near the Moratuwa junction',
        'contact_phone' => '0771234567',
        'photo' => UploadedFile::fake()->image('pothole.jpg'),
    ];
}

beforeEach(function () {
    Storage::fake('public');
    config(['services.civic_ai.url' => 'http://ai.test', 'services.civic_ai.enabled' => true, 'services.civic_ai.fail_open' => false]);
    // Tests override $this->aiResponse to change what the fake AI service returns.
    $this->aiResponse = Http::response(aiAnalysis(false));
    Http::fake(['ai.test/*' => fn () => $this->aiResponse]);
});

test('an approved citizen can submit a report with a photo and location', function () {
    $citizen = User::factory()->approved()->create();
    $category = Category::factory()->create();

    $response = $this->actingAs($citizen, 'sanctum')->postJson('/api/v1/reports', [
        'category_id' => $category->id,
        'description' => 'Large pothole near the junction, dangerous for motorcycles.',
        'latitude' => 6.8412,
        'longitude' => 79.8731,
        'address' => 'Galle Road, near the Moratuwa junction',
        'contact_phone' => '0771234567',
        'photo' => UploadedFile::fake()->image('pothole.jpg'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.address', 'Galle Road, near the Moratuwa junction')
        ->assertJsonPath('data.contact_phone', '0771234567')
        ->assertJsonPath('data.votes_count', 1)
        ->assertJsonPath('data.voted_by_me', true);

    $report = Report::first();
    expect($report->user_id)->toBe($citizen->id);
    expect($report->title)->toContain('Large pothole');
    Storage::disk('public')->assertExists($report->photo_path);
    expect($report->statusHistory()->count())->toBe(1);
    expect($report->statusHistory()->first()->to_status)->toBe(ReportStatus::Open);
    expect($report->upvotes()->count())->toBe(1);
    expect($report->upvotes()->first()->user_id)->toBe($citizen->id);
});

test('submitting a report requires a description, location, address, contact phone and photo, but not a category', function () {
    $citizen = User::factory()->approved()->create();

    $response = $this->actingAs($citizen, 'sanctum')->postJson('/api/v1/reports', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['description', 'latitude', 'longitude', 'address', 'contact_phone', 'photo'])
        ->assertJsonMissingValidationErrors(['category_id']);
});

test('a citizen can submit a report without a category', function () {
    $citizen = User::factory()->approved()->create();

    $response = $this->actingAs($citizen, 'sanctum')->postJson('/api/v1/reports', [
        'description' => 'Streetlight has been flickering for a week.',
        'latitude' => 6.8412,
        'longitude' => 79.8731,
        'address' => 'Galle Road, near the Moratuwa junction',
        'contact_phone' => '0771234567',
        'photo' => UploadedFile::fake()->image('streetlight.jpg'),
    ]);

    $response->assertCreated()->assertJsonPath('data.category', null);

    expect(Report::first()->category_id)->toBeNull();
});

test('a citizen can list nearby reports sorted by votes', function () {
    $citizen = User::factory()->approved()->create();
    $popular = Report::factory()->create();
    Report::factory()->count(3)->create();
    $popular->upvotes()->create(['user_id' => User::factory()->create()->id]);
    $popular->upvotes()->create(['user_id' => User::factory()->create()->id]);

    $response = $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/reports?sort=most_voted');

    $response->assertOk();
    expect($response->json('data.0.id'))->toBe($popular->id);
    expect($response->json('data.0.votes_count'))->toBe(2);
});

test('a citizen can list only their own reports', function () {
    $citizen = User::factory()->approved()->create();
    Report::factory()->for($citizen)->create();
    Report::factory()->count(2)->create();

    $response = $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/reports/mine');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

test('a citizen can view a single report with its status history', function () {
    $citizen = User::factory()->approved()->create();
    $report = Report::factory()->create();
    $report->statusHistory()->create(['to_status' => ReportStatus::Open, 'changed_by' => $report->user_id, 'note' => 'Report received.']);

    $response = $this->actingAs($citizen, 'sanctum')->getJson("/api/v1/reports/{$report->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $report->id)
        ->assertJsonCount(1, 'data.status_history');
});

test('a citizen can upvote a report once and withdraw the vote', function () {
    $citizen = User::factory()->approved()->create();
    $report = Report::factory()->create();

    $this->actingAs($citizen, 'sanctum')->postJson("/api/v1/reports/{$report->id}/upvote")
        ->assertOk()->assertJsonPath('votes_count', 1);

    $this->actingAs($citizen, 'sanctum')->postJson("/api/v1/reports/{$report->id}/upvote")
        ->assertUnprocessable();

    expect($report->upvotes()->count())->toBe(1);

    $this->actingAs($citizen, 'sanctum')->deleteJson("/api/v1/reports/{$report->id}/upvote")
        ->assertOk()->assertJsonPath('votes_count', 0);
});

test('the categories list is available to signed in citizens', function () {
    $citizen = User::factory()->approved()->create();
    Category::factory()->count(3)->create();

    $response = $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/categories');

    $response->assertOk()->assertJsonCount(3, 'data');
});

test('a report is screened by the AI service before it is stored', function () {
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')->post('/api/v1/reports', validReportPayload(), ['Accept' => 'application/json'])
        ->assertCreated();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://ai.test/api/v1/analyze'
        && $request->isMultipart()
        && collect($request->data())->pluck('name')->contains('image')
        && collect($request->data())->firstWhere('name', 'description')['contents'] === 'Large pothole near the junction, dangerous for motorcycles.');
});

test('a report flagged as spam by the AI service is rejected and nothing is stored', function () {
    $this->aiResponse = Http::response(aiAnalysis(true, ['advertisement', 'unrelated_image']));
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')->post('/api/v1/reports', validReportPayload(), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['screening'])
        ->assertJsonPath('screening.reasons', ['advertisement', 'unrelated_image']);

    expect(Report::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a report is refused when the AI service is unavailable', function () {
    $this->aiResponse = Http::response(['error' => 'down'], 500);
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')->post('/api/v1/reports', validReportPayload(), ['Accept' => 'application/json'])
        ->assertServiceUnavailable()
        ->assertJsonValidationErrors(['screening']);

    expect(Report::count())->toBe(0);
});

test('a report is accepted without screening when the AI service is unavailable and fail-open is enabled', function () {
    config(['services.civic_ai.fail_open' => true]);
    $this->aiResponse = Http::response(['error' => 'down'], 500);
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')->post('/api/v1/reports', validReportPayload(), ['Accept' => 'application/json'])
        ->assertCreated();

    expect(Report::count())->toBe(1);
});
