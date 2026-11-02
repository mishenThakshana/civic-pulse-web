<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DepartmentApiKey;
use App\Models\User;

test('admin can issue a key, which is shown once and stored only as a hash', function () {
    $admin = User::factory()->admin()->create();
    $department = Department::factory()->create();

    $response = $this->actingAs($admin)->post(route('departments.api-keys.store', $department), [
        'name' => 'Works system',
        'access' => 'read',
    ])->assertRedirect()->assertSessionHas('new_api_key');

    $plainKey = $response->getSession()->get('new_api_key');
    $apiKey = $department->apiKeys()->sole();

    expect($plainKey)->toStartWith('cpk_');
    expect($apiKey->key_hash)->toBe(DepartmentApiKey::hash($plainKey))->not->toBe($plainKey);
    expect($apiKey->can_update_reports)->toBeFalse();
    expect($apiKey->created_by)->toBe($admin->id);
    expect(ActivityLog::where('action', ActivityAction::ApiKeyCreated)->exists())->toBeTrue();

    $this->actingAs($admin)->get('/departments')->assertSee($plainKey);
    $this->actingAs($admin)->get('/departments')->assertDontSee($plainKey);
});

test('admin can issue a read and update key', function () {
    $admin = User::factory()->admin()->create();
    $department = Department::factory()->create();

    $this->actingAs($admin)->post(route('departments.api-keys.store', $department), [
        'name' => 'Ticketing',
        'access' => 'read_write',
    ]);

    expect($department->apiKeys()->sole()->can_update_reports)->toBeTrue();
});

test('the departments page lists keys by prefix only', function () {
    $admin = User::factory()->admin()->create();
    $apiKey = DepartmentApiKey::factory()->create(['name' => 'Asset register']);

    $this->actingAs($admin)->get('/departments')
        ->assertOk()
        ->assertSee('Asset register')
        ->assertSee($apiKey->key_prefix)
        ->assertDontSee($apiKey->key_hash);
});

test('admin can revoke a key', function () {
    $admin = User::factory()->admin()->create();
    $apiKey = DepartmentApiKey::factory()->create();

    $this->actingAs($admin)
        ->delete(route('departments.api-keys.destroy', [$apiKey->department, $apiKey]))
        ->assertRedirect();

    expect(DepartmentApiKey::find($apiKey->id))->toBeNull();
    expect(ActivityLog::where('action', ActivityAction::ApiKeyRevoked)->exists())->toBeTrue();
});

test('a key cannot be revoked through another department\'s URL', function () {
    $admin = User::factory()->admin()->create();
    $apiKey = DepartmentApiKey::factory()->create();
    $otherDepartment = Department::factory()->create();

    $this->actingAs($admin)
        ->delete(route('departments.api-keys.destroy', [$otherDepartment, $apiKey]))
        ->assertNotFound();

    expect(DepartmentApiKey::find($apiKey->id))->not->toBeNull();
});

test('staff cannot manage API keys', function () {
    $staff = User::factory()->staff()->create();
    $department = Department::factory()->create();

    $this->actingAs($staff)
        ->post(route('departments.api-keys.store', $department), ['name' => 'X', 'access' => 'read'])
        ->assertForbidden();

    expect($department->apiKeys()->count())->toBe(0);
});

test('deleting a department revokes its keys', function () {
    $admin = User::factory()->admin()->create();
    $apiKey = DepartmentApiKey::factory()->create();

    $this->actingAs($admin)->delete(route('departments.destroy', $apiKey->department));

    expect(DepartmentApiKey::find($apiKey->id))->toBeNull();
});
