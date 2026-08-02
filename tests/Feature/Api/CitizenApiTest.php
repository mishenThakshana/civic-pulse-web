<?php

use App\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

test('a citizen can register with NIC front and back photos and starts out pending', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Nimal Perera',
        'email' => 'nimal@example.com',
        'phone' => '0771234567',
        'nic_number' => '199012345678',
        'nic_photo_front' => UploadedFile::fake()->image('nic-front.jpg'),
        'nic_photo_back' => UploadedFile::fake()->image('nic-back.jpg'),
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()->assertJsonPath('user.approval_status', 'pending');

    $user = User::where('email', 'nimal@example.com')->first();
    expect($user->role->value)->toBe('citizen');
    expect($user->approval_status)->toBe(ApprovalStatus::Pending);
    expect($user->nic_photo_front_path)->not->toBeNull();
    expect($user->nic_photo_back_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->nic_photo_front_path);
    Storage::disk('public')->assertExists($user->nic_photo_back_path);
});

test('a citizen can register with a scanned PDF instead of a photo', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Kavindu Silva',
        'email' => 'kavindu@example.com',
        'phone' => '0779876543',
        'nic_number' => '199512345679',
        'nic_photo_front' => UploadedFile::fake()->create('nic-front.pdf', 500, 'application/pdf'),
        'nic_photo_back' => UploadedFile::fake()->image('nic-back.jpg'),
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated();

    $user = User::where('email', 'kavindu@example.com')->first();
    Storage::disk('public')->assertExists($user->nic_photo_front_path);
    expect($user->nic_photo_front_path)->toEndWith('.pdf');
});

test('registration does not sign the citizen in while approval is pending', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'Pending Person',
        'email' => 'pending@example.com',
        'phone' => '0771112222',
        'nic_number' => '199712345670',
        'nic_photo_front' => UploadedFile::fake()->image('nic-front.jpg'),
        'nic_photo_back' => UploadedFile::fake()->image('nic-back.jpg'),
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()->assertJsonMissingPath('token');
    expect($response->json('message'))->toContain('submitted for review');

    $user = User::where('email', 'pending@example.com')->first();
    expect($user->tokens()->count())->toBe(0);
});

test('registration requires a NIC number and both NIC photos', function () {
    $response = $this->postJson('/api/v1/register', [
        'name' => 'No NIC',
        'email' => 'nonic@example.com',
        'phone' => '0771234567',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'Device',
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['nic_number', 'nic_photo_front', 'nic_photo_back']);
});

test('a pending citizen cannot log in', function () {
    $user = User::factory()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'Test Device',
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors.email.0'))->toContain('pending admin approval');
});

test('a rejected citizen cannot log in and sees the admin-provided reason', function () {
    $user = User::factory()->rejected()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'Test Device',
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors.email.0'))->toContain('not approved');
    expect($response->json('errors.email.0'))->toContain('NIC photo was too blurry to verify.');
});

test('an approved citizen can revalidate their session', function () {
    $user = User::factory()->approved()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('user.approval_status', 'approved');
});

test('a session belonging to an unapproved citizen is rejected and its token revoked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Test Device')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/me');

    $response->assertForbidden();
    expect($response->json('message'))->toContain('pending admin approval');
    expect($user->tokens()->count())->toBe(0);
});

test('a session belonging to a deactivated citizen is rejected', function () {
    $user = User::factory()->approved()->create(['is_active' => false]);
    $token = $user->createToken('Test Device')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/me');

    $response->assertForbidden();
    expect($response->json('message'))->toContain('deactivated');
});

test('an approved citizen can log in and receive a token', function () {
    $user = User::factory()->approved()->create(['password' => bcrypt('password123')]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'Test Device',
    ]);

    $response->assertOk()->assertJsonStructure(['user', 'token']);
});
