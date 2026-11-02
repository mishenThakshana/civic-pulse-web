<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Department;
use App\Models\Report;
use App\Notifications\ReportStatusUpdated;
use App\Services\PushNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly PushNotificationService $push)
    {
        //
    }

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
        $report->load(['category', 'department', 'user', 'statusHistory.changedBy'])->loadCount('upvotes');

        return view('panel.reports.show', [
            'report' => $report,
            'statuses' => ReportStatus::cases(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Report $report): RedirectResponse
    {
        $data = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['required', 'string', 'in:'.implode(',', array_column(ReportStatus::cases(), 'value'))],
            'note' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'image', 'max:6144'],
        ]);

        $fromStatus = $report->status;
        $toStatus = ReportStatus::from($data['status']);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('report-updates', 'public')
            : null;

        $report->update([
            'department_id' => $data['department_id'] ?? null,
            'status' => $toStatus,
        ]);

        $report->statusHistory()->create([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $request->user()->id,
            'note' => $data['note'] ?? null,
            'photo_path' => $photoPath,
        ]);

        $report->load('department');

        ActivityLog::record(
            ActivityAction::ReportUpdated,
            $fromStatus === $toStatus
                ? "Updated report #{$report->id} ({$toStatus->label()})."
                : "Moved report #{$report->id} from {$fromStatus->label()} to {$toStatus->label()}.",
            $report,
            ['from_status' => $fromStatus->value, 'to_status' => $toStatus->value, 'department' => $report->department?->name],
        );
        $notification = new ReportStatusUpdated($report);
        $report->user->notify($notification);
        $this->push->sendToUser($report->user, $notification->title(), $notification->body(), ['report_id' => $report->id]);

        return back()->with('status', "Report #{$report->id} has been updated and the citizen notified.");
    }
}
