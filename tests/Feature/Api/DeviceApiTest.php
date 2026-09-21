<?php

use App\Models\DeviceToken;
use App\Models\User;

test('a citizen can register a device token for push notifications', function () {
    $citizen = User::factory()->approved()->create();

    $response = $this->actingAs($citizen, 'sanctum')->postJson('/api/v1/devices', [
        'token' => 'expo-or-fcm-token-123',
        'platform' => 'android',
    ]);

    $response->assertCreated();
    expect(DeviceToken::where('user_id', $citizen->id)->where('token', 'expo-or-fcm-token-123')->exists())->toBeTrue();
});

test('registering the same token again updates the owning citizen instead of duplicating it', function () {
    $first = User::factory()->approved()->create();
    $second = User::factory()->approved()->create();

    $this->actingAs($first, 'sanctum')->postJson('/api/v1/devices', ['token' => 'shared-token', 'platform' => 'ios']);
    $this->actingAs($second, 'sanctum')->postJson('/api/v1/devices', ['token' => 'shared-token', 'platform' => 'ios']);

    expect(DeviceToken::where('token', 'shared-token')->count())->toBe(1);
    expect(DeviceToken::where('token', 'shared-token')->first()->user_id)->toBe($second->id);
});

test('registering a device requires a valid platform', function () {
    $citizen = User::factory()->approved()->create();

    $this->actingAs($citizen, 'sanctum')->postJson('/api/v1/devices', ['token' => 'abc', 'platform' => 'windows'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platform']);
});
