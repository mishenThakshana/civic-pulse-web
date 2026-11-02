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

class CitizenController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::where('role', UserRole::Citizen);

        if ($request->filled('status')) {
            $query->where('approval_status', $request->string('status'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nic_number', 'like', "%{$search}%");
            });
        }

        $counts = User::where('role', UserRole::Citizen);

        return view('panel.citizens.index', [
            'citizens' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'pendingCount' => (clone $counts)->where('approval_status', ApprovalStatus::Pending)->count(),
            'approvedCount' => (clone $counts)->where('approval_status', ApprovalStatus::Approved)->count(),
            'rejectedCount' => (clone $counts)->where('approval_status', ApprovalStatus::Rejected)->count(),
            'statuses' => ApprovalStatus::cases(),
        ]);
    }

    public function update(Request $request, User $citizen): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email,'.$citizen->id],
            'phone' => ['required', 'string', 'max:30'],
            'nic_number' => ['required', 'string', 'max:20', 'unique:users,nic_number,'.$citizen->id],
        ]);

        $citizen->fill($data);
        $changed = array_keys($citizen->getDirty());
        $citizen->save();

        ActivityLog::record(ActivityAction::CitizenUpdated, "Updated {$citizen->name}'s details.", $citizen, ['changed' => $changed]);

        return back()->with('status', "{$citizen->name}'s details have been updated.");
    }

    public function approve(Request $request, User $citizen): RedirectResponse
    {
        $citizen->update([
            'approval_status' => ApprovalStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'rejection_reason' => null,
        ]);

        ActivityLog::record(ActivityAction::CitizenApproved, "Approved {$citizen->name}'s registration.", $citizen);

        return back()->with('status', "{$citizen->name}'s account has been approved.");
    }

    public function reject(Request $request, User $citizen): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $citizen->update([
            'approval_status' => ApprovalStatus::Rejected,
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'rejection_reason' => $data['reason'],
        ]);

        ActivityLog::record(ActivityAction::CitizenRejected, "Rejected {$citizen->name}'s registration.", $citizen, ['reason' => $data['reason']]);

        return back()->with('status', "{$citizen->name}'s account has been rejected.");
    }

    public function toggleActive(User $citizen): RedirectResponse
    {
        $citizen->update(['is_active' => ! $citizen->is_active]);

        ActivityLog::record(
            $citizen->is_active ? ActivityAction::CitizenActivated : ActivityAction::CitizenDeactivated,
            ($citizen->is_active ? 'Activated' : 'Deactivated')." {$citizen->name}'s account.",
            $citizen,
        );

        return back()->with('status', "{$citizen->name}'s account has been ".($citizen->is_active ? 'activated' : 'deactivated').'.');
    }

    public function destroy(User $citizen): RedirectResponse
    {
        $name = $citizen->name;
        ActivityLog::record(ActivityAction::CitizenDeleted, "Deleted {$name}'s citizen account.", $citizen, ['email' => $citizen->email, 'nic_number' => $citizen->nic_number]);
        $citizen->delete();

        return back()->with('status', "{$name}'s account has been deleted.");
    }
}
