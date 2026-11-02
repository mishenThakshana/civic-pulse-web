<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Report;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(): View
    {
        $departments = Department::withCount([
            'reports as open_count' => fn ($q) => $q->where('status', ReportStatus::Open),
            'reports as in_progress_count' => fn ($q) => $q->where('status', ReportStatus::InProgress),
            'reports as resolved_count' => fn ($q) => $q->where('status', ReportStatus::Resolved),
        ])->orderBy('name')->get();

        $departments->each(fn (Department $department) => $department->avg_resolution_days = Report::averageResolutionDays($department->id));

        return view('panel.departments.index', ['departments' => $departments]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name'],
            'contact_email' => ['required', 'email', 'max:150'],
        ]);

        $department = Department::create($data);

        ActivityLog::record(ActivityAction::DepartmentCreated, "Added the \"{$department->name}\" department.", $department);

        return back()->with('status', "\"{$data['name']}\" has been added.");
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:departments,name,'.$department->id],
            'contact_email' => ['required', 'email', 'max:150'],
        ]);

        $department->update($data);

        ActivityLog::record(ActivityAction::DepartmentUpdated, "Updated the \"{$department->name}\" department.", $department);

        return back()->with('status', "\"{$department->name}\" has been updated.");
    }

    public function destroy(Department $department): RedirectResponse
    {
        $name = $department->name;
        ActivityLog::record(ActivityAction::DepartmentDeleted, "Deleted the \"{$name}\" department.", $department);
        $department->delete();

        return back()->with('status', "\"{$name}\" has been deleted. Its reports are now unassigned.");
    }
}
