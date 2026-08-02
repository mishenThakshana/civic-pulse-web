<?php

use App\Enums\ApprovalStatus;
use App\Models\User;

test('admin can log in and reach the dashboard', function () {
    $admin = User::factory()->admin()->create(['password' => bcrypt('password')]);

    $response = $this->post('/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/dashboard');
    $this->actingAs($admin)->get('/dashboard')->assertOk();
});

test('citizens cannot access the admin panel even when approved', function () {
    $citizen = User::factory()->approved()->create(['password' => bcrypt('password')]);

    $this->post('/login', [
        'email' => $citizen->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('sidebar sections render as work in progress, including the dashboard', function () {
    $admin = User::factory()->admin()->create();

    foreach (['dashboard', 'reports', 'analytics', 'categories', 'departments', 'staff', 'activity-log'] as $slug) {
        $this->actingAs($admin)->get("/{$slug}")->assertOk()->assertSee('Work in progress');
    }
});

test('admin can view the citizens list with NIC details', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create(['name' => 'Nimal Perera']);

    $this->actingAs($admin)
        ->get('/citizens')
        ->assertOk()
        ->assertSee('Nimal Perera')
        ->assertSee($citizen->nic_number);
});

test('review actions live in the view modal instead of the table row', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $html = $this->actingAs($admin)->get('/citizens')->assertOk()->getContent();

    $modalPosition = strpos($html, 'id="viewModal'.$citizen->id.'"');
    $tableRowMarkup = substr($html, 0, $modalPosition);

    expect($tableRowMarkup)->not->toContain(route('citizens.approve', $citizen));
    expect($tableRowMarkup)->not->toContain(route('citizens.toggle-active', $citizen));
    expect($tableRowMarkup)->not->toContain('rejectModal'.$citizen->id);

    $modalMarkup = substr($html, $modalPosition);
    expect($modalMarkup)->toContain(route('citizens.approve', $citizen));
    expect($modalMarkup)->toContain(route('citizens.toggle-active', $citizen));
    expect($modalMarkup)->toContain('rejectModal'.$citizen->id);
});

test('citizen action buttons use the shared button styles without inline colours', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create();

    $html = $this->actingAs($admin)->get('/citizens')->assertOk()->getContent();

    expect($html)->not->toContain('style="color:var(--success)');
    expect($html)->not->toContain('style="color:var(--error)');
});

test('admin can approve a pending citizen registration', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('citizens.approve', $citizen))
        ->assertRedirect();

    $citizen->refresh();
    expect($citizen->approval_status)->toBe(ApprovalStatus::Approved);
    expect($citizen->approved_by)->toBe($admin->id);
});

test('admin can reject a pending citizen registration with a reason', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('citizens.reject', $citizen), ['reason' => 'NIC number does not match the photo.'])
        ->assertRedirect();

    $citizen->refresh();
    expect($citizen->approval_status)->toBe(ApprovalStatus::Rejected);
    expect($citizen->rejection_reason)->toBe('NIC number does not match the photo.');
});

test('rejecting a registration requires a reason', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('citizens.reject', $citizen))
        ->assertSessionHasErrors('reason');

    expect($citizen->refresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

test('admin can update a citizen\'s details', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $this->actingAs($admin)
        ->patch(route('citizens.update', $citizen), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'phone' => '0779999999',
            'nic_number' => $citizen->nic_number,
        ])
        ->assertRedirect();

    $citizen->refresh();
    expect($citizen->name)->toBe('Updated Name');
    expect($citizen->email)->toBe('updated@example.com');
    expect($citizen->phone)->toBe('0779999999');
});

test('admin can deactivate and reactivate a citizen account', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->approved()->create();

    $this->actingAs($admin)->patch(route('citizens.toggle-active', $citizen))->assertRedirect();
    expect($citizen->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->patch(route('citizens.toggle-active', $citizen))->assertRedirect();
    expect($citizen->refresh()->is_active)->toBeTrue();
});

test('a deactivated citizen cannot log in via the API', function () {
    $citizen = User::factory()->approved()->create(['password' => bcrypt('password123'), 'is_active' => false]);

    $response = $this->postJson('/api/v1/login', [
        'email' => $citizen->email,
        'password' => 'password123',
        'device_name' => 'Test Device',
    ]);

    $response->assertUnprocessable();
    expect($response->json('errors.email.0'))->toContain('deactivated');
});

test('admin can delete a citizen account', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $this->actingAs($admin)
        ->delete(route('citizens.destroy', $citizen))
        ->assertRedirect();

    expect(User::find($citizen->id))->toBeNull();
});
