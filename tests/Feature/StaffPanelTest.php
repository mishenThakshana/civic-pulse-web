<?php

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('staff can sign in and use the operational pages', function () {
    $staff = User::factory()->staff()->create(['password' => bcrypt('password')]);

    $this->post('/login', ['email' => $staff->email, 'password' => 'password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($staff);

    foreach (['dashboard', 'citizens', 'reports', 'analytics'] as $slug) {
        $this->get("/{$slug}")->assertOk();
    }
});

test('staff cannot reach the administration pages', function () {
    $staff = User::factory()->staff()->create();

    foreach (['categories', 'departments', 'staff', 'activity-log'] as $slug) {
        $this->actingAs($staff)->get("/{$slug}")->assertForbidden();
    }
});

test('staff do not see the administration section in the sidebar', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)->get('/dashboard')
        ->assertOk()
        ->assertDontSee('Administration')
        ->assertDontSee(route('staff.index'));
});

test('staff cannot delete citizens', function () {
    $staff = User::factory()->staff()->create();
    $citizen = User::factory()->create();

    $this->actingAs($staff)->delete(route('citizens.destroy', $citizen))->assertForbidden();

    expect(User::find($citizen->id))->not->toBeNull();
});

test('citizen routes cannot be used to act on panel accounts', function () {
    $staff = User::factory()->staff()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($staff)->patch(route('citizens.toggle-active', $admin))->assertNotFound();

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('a deactivated staff member cannot sign in', function () {
    $staff = User::factory()->staff()->create(['password' => bcrypt('password'), 'is_active' => false]);

    $this->post('/login', ['email' => $staff->email, 'password' => 'password']);

    $this->assertGuest();
});

test('a staff member deactivated mid-session is signed out on their next request', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)->get('/dashboard')->assertOk();
    $staff->update(['is_active' => false]);

    $this->get('/reports')->assertRedirect(route('login'));
    $this->assertGuest();
});

test('admin can view panel accounts but not citizens on the staff page', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->staff()->create(['name' => 'Kamal Silva']);
    User::factory()->create(['name' => 'Citizen Person']);

    $this->actingAs($admin)->get('/staff')
        ->assertOk()
        ->assertSee('Kamal Silva')
        ->assertDontSee('Citizen Person');
});

test('admin can create a staff account', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/staff', [
        'name' => 'Kamal Silva',
        'email' => 'kamal@council.lk',
        'role' => 'staff',
        'password' => 'secret-pass',
        'password_confirmation' => 'secret-pass',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $staff = User::where('email', 'kamal@council.lk')->first();
    expect($staff->role)->toBe(UserRole::Staff);
    expect($staff->is_active)->toBeTrue();
    expect(Hash::check('secret-pass', $staff->password))->toBeTrue();
    expect(ActivityLog::where('action', ActivityAction::StaffCreated)->where('subject_id', $staff->id)->exists())->toBeTrue();
});

test('the staff page cannot create citizen accounts', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/staff', [
        'name' => 'Sneaky',
        'email' => 'sneaky@example.com',
        'role' => 'citizen',
        'password' => 'secret-pass',
        'password_confirmation' => 'secret-pass',
    ])->assertSessionHasErrors('role');
});

test('creating a staff account requires a confirmed password', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/staff', [
        'name' => 'Kamal Silva',
        'email' => 'kamal@council.lk',
        'role' => 'staff',
        'password' => 'secret-pass',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
});

test('admin can update a staff account without changing the password', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create(['password' => bcrypt('original-pass')]);

    $this->actingAs($admin)->patch(route('staff.update', $staff), [
        'name' => 'Renamed',
        'email' => $staff->email,
        'role' => 'admin',
        'password' => '',
        'password_confirmation' => '',
    ])->assertSessionHasNoErrors();

    $staff->refresh();
    expect($staff->name)->toBe('Renamed');
    expect($staff->role)->toBe(UserRole::Admin);
    expect(Hash::check('original-pass', $staff->password))->toBeTrue();
});

test('admin can reset a staff member\'s password', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    $this->actingAs($admin)->patch(route('staff.update', $staff), [
        'name' => $staff->name,
        'email' => $staff->email,
        'role' => 'staff',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('brand-new-pass', $staff->refresh()->password))->toBeTrue();
});

test('an admin cannot demote, deactivate or delete themselves', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();

    $this->actingAs($admin)->patch(route('staff.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email, 'role' => 'staff',
    ])->assertSessionHasErrors('role');

    $this->actingAs($admin)->patch(route('staff.toggle-active', $admin))->assertSessionHasErrors('staff');
    $this->actingAs($admin)->delete(route('staff.destroy', $admin))->assertSessionHasErrors('staff');

    $admin->refresh();
    expect($admin->role)->toBe(UserRole::Admin);
    expect($admin->is_active)->toBeTrue();
});

test('admin can deactivate and delete a staff account', function () {
    $admin = User::factory()->admin()->create();
    $staff = User::factory()->staff()->create();

    $this->actingAs($admin)->patch(route('staff.toggle-active', $staff))->assertRedirect();
    expect($staff->refresh()->is_active)->toBeFalse();

    $this->actingAs($admin)->delete(route('staff.destroy', $staff))->assertRedirect();
    expect(User::find($staff->id))->toBeNull();
});

test('the staff routes cannot touch citizen accounts', function () {
    $admin = User::factory()->admin()->create();
    $citizen = User::factory()->create();

    $this->actingAs($admin)->delete(route('staff.destroy', $citizen))->assertNotFound();

    expect(User::find($citizen->id))->not->toBeNull();
});
