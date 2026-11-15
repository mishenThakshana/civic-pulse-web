<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Report;
use App\Services\ReportUpdateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportUpdateService $reportUpdates) {}

    public function index(Request $request): View
    {
        $query = Report::query()->with(['category', 'department'])->withCount('upvotes');

        if ($request->filled('status')) {
            $query->where('status', ReportStatus::from($request->string('status')->toString()));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search): void {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $counts = Report::query();

        return view('panel.reports.index', [
            'reports' => $query->orderByDesc('upvotes_count')->orderBy('created_at')->paginate(15)->withQueryString(),
            'allCount' => (clone $counts)->count(),
            'openCount' => (clone $counts)->where('status', ReportStatus::Open)->count(),
            'inProgressCount' => (clone $counts)->where('status', ReportStatus::InProgress)->count(),
            'resolvedCount' => (clone $counts)->where('status', ReportStatus::Resolved)->count(),
            'statuses' => ReportStatus::cases(),
            'categories' => Category::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function show(Report $report): View
    {
        $report->load(['category', 'department', 'user', 'statusHistory.changedBy', 'statusHistory.changedByDepartment'])->loadCount('upvotes');

        return view('panel.reports.show', [
            'report' => $report,
            'statuses' => ReportStatus::cases(),
            'departments' => Department::orderBy('name')->get(),
            'categories' => Category::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Report $report): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(ReportStatus::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'image', 'max:6144'],
        ]);

        $report->department_id = $data['department_id'] ?? null;

        if (array_key_exists('category_id', $data)) {
            $report->category_id = $data['category_id'];
        }

        $this->reportUpdates->record(
            $report,
            ReportStatus::from($data['status']),
            $data['note'] ?? null,
            $request->file('photo'),
            byUser: $request->user(),
        );

        return back()->with('status', "Report #{$report->id} has been updated and the citizen notified.");
    }
}
