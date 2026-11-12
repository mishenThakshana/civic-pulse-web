<?php

use App\Enums\ReportStatus;
use App\Http\Middleware\SetCitizenLocale;
use App\Models\Department;
use App\Models\DeviceToken;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportStatusUpdated;
use App\Services\ReportUpdateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;

test('the accept-language header picks a supported language and falls back to English', function (?string $header, string $expected) {
    expect(SetCitizenLocale::resolve($header))->toBe($expected);
})->with([
    'sinhala' => ['si', 'si'],
    'tamil with region and weights' => ['ta-LK,ta;q=0.9,en;q=0.8', 'ta'],
    'first supported wins' => ['fr-FR,si;q=0.8', 'si'],
    'unsupported' => ['fr', 'en'],
    'missing' => [null, 'en'],
]);

test('sign in refusals are returned in the language the app asks for', function () {
    User::factory()->create(['email' => 'pending@example.lk']);

    $this->postJson('/api/v1/login', ['email' => 'pending@example.lk', 'password' => 'password', 'device_name' => 'phone'], ['Accept-Language' => 'si'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'ඔබගේ ගිණුම තවමත් පරිපාලක අනුමැතිය සඳහා රැඳී ඇත.');

    $this->postJson('/api/v1/login', ['email' => 'pending@example.lk', 'password' => 'password', 'device_name' => 'phone'], ['Accept-Language' => 'ta'])
        ->assertJsonPath('errors.email.0', 'உங்கள் கணக்கு இன்னும் நிர்வாகியின் அங்கீகாரத்திற்காகக் காத்திருக்கிறது.');

    $this->postJson('/api/v1/login', ['email' => 'pending@example.lk', 'password' => 'password', 'device_name' => 'phone'])
        ->assertJsonPath('errors.email.0', 'Your account is still pending admin approval.');
});

test('a rejection reason typed by staff is kept as written inside the translated message', function () {
    User::factory()->rejected()->create(['email' => 'rejected@example.lk', 'rejection_reason' => 'NIC photo was too blurry to verify.']);

    $this->postJson('/api/v1/login', ['email' => 'rejected@example.lk', 'password' => 'password', 'device_name' => 'phone'], ['Accept-Language' => 'si'])
        ->assertJsonPath('errors.email.0', 'ඔබගේ ලියාපදිංචිය අනුමත කර නැත: NIC photo was too blurry to verify.');
});

test('validation errors are translated with translated field names', function () {
    $this->postJson('/api/v1/register', [], ['Accept-Language' => 'si'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.nic_number.0', 'ජාතික හැඳුනුම්පත් අංකය අවශ්‍යයි.');
});

test('the screening refusal is returned in the citizen\'s language', function () {
    Storage::fake('public');
    config(['services.civic_ai.enabled' => true]);
    Http::fake(['*/api/v1/analyze' => Http::response(['spam' => ['is_suspicious' => true, 'reasons' => ['advertisement']]])]);
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')->postJson('/api/v1/reports', [
        'description' => 'Best price cement, call now',
        'latitude' => 6.9,
        'longitude' => 79.9,
        'address' => 'Galle Road',
        'contact_phone' => '0771234567',
        'photo' => UploadedFile::fake()->image('photo.jpg'),
    ], ['Accept-Language' => 'ta'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.screening.0', fn (string $message) => str_starts_with($message, 'எங்கள் AI சோதனை'));
});

test('alerts are rendered in the language the app asks for', function () {
    $citizen = User::factory()->approved()->create();
    $department = Department::factory()->create(['name' => 'Drainage']);
    $report = Report::factory()->for($citizen)->resolved()->create(['department_id' => $department->id, 'title' => 'Blocked drain']);
    $citizen->notify(new ReportStatusUpdated($report));

    $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/notifications', ['Accept-Language' => 'si'])
        ->assertOk()
        ->assertJsonPath('data.0.title', 'ඔබගේ වාර්තාවේ ගැටලුව විසඳා ඇත')
        ->assertJsonPath('data.0.body', '"Blocked drain" සම්බන්ධයෙන් Drainage කටයුතු කරමින් සිටී.');

    $this->actingAs($citizen, 'sanctum')->getJson('/api/v1/notifications')
        ->assertJsonPath('data.0.title', 'Your report was resolved');
});

test('the automatic first history note is translated but staff notes are left as written', function () {
    $citizen = User::factory()->approved()->create();
    $report = Report::factory()->for($citizen)->create();
    $report->statusHistory()->create(['to_status' => ReportStatus::Open, 'changed_by' => $citizen->id, 'note' => 'Report received.']);
    $report->statusHistory()->create(['to_status' => ReportStatus::InProgress, 'changed_by' => $citizen->id, 'note' => 'Crew booked for Monday.']);

    $notes = collect($this->actingAs($citizen, 'sanctum')->getJson("/api/v1/reports/{$report->id}", ['Accept-Language' => 'ta'])
        ->assertOk()
        ->json('data.status_history'))->pluck('note');

    expect($notes)->toContain('அறிக்கை பெறப்பட்டது.')->toContain('Crew booked for Monday.');
});

test('a device remembers the language it registered with', function () {
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')
        ->postJson('/api/v1/devices', ['token' => 'device-si', 'platform' => 'android'], ['Accept-Language' => 'si'])
        ->assertCreated()
        ->assertJsonPath('message', 'උපාංගය ලියාපදිංචි කරන ලදී.');

    expect(DeviceToken::where('token', 'device-si')->value('locale'))->toBe('si');
});

test('push notifications are sent to each device in its own language', function () {
    $citizen = User::factory()->approved()->create();
    DeviceToken::factory()->for($citizen)->create(['token' => 'phone-si', 'locale' => 'si']);
    DeviceToken::factory()->for($citizen)->create(['token' => 'tablet-en', 'locale' => null]);
    $report = Report::factory()->for($citizen)->create();

    $sent = [];
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->twice()->andReturnUsing(function ($message, array $tokens) use (&$sent) {
        $sent[implode(',', $tokens)] = json_decode(json_encode($message), true)['notification']['title'];

        return MulticastSendReport::withItems([]);
    });
    app()->instance(Messaging::class, $messaging);

    app(ReportUpdateService::class)->record($report, ReportStatus::InProgress, 'On our list.');

    expect($sent)->toBe([
        'phone-si' => 'ඔබගේ වාර්තාව සම්බන්ධයෙන් කටයුතු ආරම්භ කර ඇත',
        'tablet-en' => 'Work has started on your report',
    ]);
});
