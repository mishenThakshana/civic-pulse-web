<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::whereIn('role', UserRole::panelRoles());

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $counts = User::whereIn('role', UserRole::panelRoles());

        return view('panel.staff.index', [
            'staffMembers' => $query
                ->withCount('activityLogs')
                ->addSelect(['last_active_at' => ActivityLog::select('created_at')
                    ->whereColumn('user_id', 'users.id')
                    ->latest('created_at')
                    ->limit(1)])
                ->orderBy('name')
                ->paginate(15)
                ->withQueryString(),
            'adminCount' => (clone $counts)->where('role', UserRole::Admin)->count(),
            'staffCount' => (clone $counts)->where('role', UserRole::Staff)->count(),
            'inactiveCount' => (clone $counts)->where('is_active', false)->count(),
            'roles' => UserRole::panelRoles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)->only(UserRole::panelRoles())],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $staff = User::create([
            ...$data,
            'approval_status' => ApprovalStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'is_active' => true,
        ]);

        ActivityLog::record(ActivityAction::StaffCreated, "Added {$staff->role->label()} account for {$staff->name}.", $staff);

        return back()->with('status', "{$staff->name}'s account has been created.");
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email,'.$staff->id],
            'role' => ['required', Rule::enum(UserRole::class)->only(UserRole::panelRoles())],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);

        $newRole = UserRole::from($data['role']);

        if ($staff->is($request->user()) && $newRole !== UserRole::Admin) {
            return back()->withErrors(['role' => 'You cannot remove your own administrator access.']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $staff->fill($data);
        $changed = array_keys($staff->getDirty());
        $staff->save();

        ActivityLog::record(
            ActivityAction::StaffUpdated,
            "Updated {$staff->name}'s account.",
            $staff,
            ['changed' => array_values(array_diff($changed, ['remember_token']))],
        );

        return back()->with('status', "{$staff->name}'s account has been updated.");
    }

    public function toggleActive(Request $request, User $staff): RedirectResponse
    {
        if ($staff->is($request->user())) {
            return back()->withErrors(['staff' => 'You cannot deactivate your own account.']);
        }

        $staff->update(['is_active' => ! $staff->is_active]);

        ActivityLog::record(
            $staff->is_active ? ActivityAction::StaffActivated : ActivityAction::StaffDeactivated,
            ($staff->is_active ? 'Activated' : 'Deactivated')." {$staff->name}'s account.",
            $staff,
        );

        return back()->with('status', "{$staff->name}'s account has been ".($staff->is_active ? 'activated' : 'deactivated').'.');
    }

    public function destroy(Request $request, User $staff): RedirectResponse
    {
        if ($staff->is($request->user())) {
            return back()->withErrors(['staff' => 'You cannot delete your own account.']);
        }

        $name = $staff->name;
        ActivityLog::record(ActivityAction::StaffDeleted, "Deleted {$staff->role->label()} account for {$name}.", $staff, ['email' => $staff->email]);
        $staff->delete();

        return back()->with('status', "{$name}'s account has been deleted.");
    }
}
