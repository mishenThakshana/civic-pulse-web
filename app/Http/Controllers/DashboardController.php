<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalStatus;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $reports = Report::query();

        $departmentWorkload = Department::withCount([
            'reports as open_count' => fn ($q) => $q->whereIn('status', [ReportStatus::Open, ReportStatus::InProgress]),
        ])->orderByDesc('open_count')->get();

        $needsAttention = Report::query()
            ->with(['category', 'department'])
            ->withCount('upvotes')
            ->where('status', ReportStatus::Open)
            ->orderByDesc('upvotes_count')
            ->limit(8)
            ->get();

        return view('panel.dashboard', [
            'openCount' => (clone $reports)->where('status', ReportStatus::Open)->count(),
            'inProgressCount' => (clone $reports)->where('status', ReportStatus::InProgress)->count(),
            'resolvedCount' => (clone $reports)->where('status', ReportStatus::Resolved)->count(),
            'avgResolutionDays' => Report::averageResolutionDays(),
            'pendingCitizens' => User::where('role', UserRole::Citizen)->where('approval_status', ApprovalStatus::Pending)->count(),
            'needsAttention' => $needsAttention,
            'departmentWorkload' => $departmentWorkload,
        ]);
    }
}
