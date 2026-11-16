<?php

namespace App\Http\Controllers;

use App\Enums\ReportStatus;
use App\Models\Category;
use App\Models\Department;
use App\Models\Report;
use Illuminate\Contracts\View\View;

class AnalyticsController extends Controller
{
    public function index(): View
    {
        $totalReports = Report::count();
        $resolvedCount = Report::where('status', ReportStatus::Resolved)->count();

        $categoryCounts = Category::withCount('reports')->orderByDesc('reports_count')->get();

        $months = collect(range(5, 0))->map(function (int $monthsAgo) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            return [
                'label' => $start->format('M'),
                'submitted' => Report::whereBetween('created_at', [$start, $end])->count(),
                'resolved' => Report::whereHas('statusHistory', fn ($q) => $q
                    ->where('to_status', ReportStatus::Resolved)
                    ->whereBetween('created_at', [$start, $end]))->count(),
            ];
        });

        $resolutionByDepartment = Department::orderBy('name')->get()->map(fn (Department $department) => [
            'name' => $department->name,
            'days' => Report::averageResolutionDays($department->id),
        ]);

        return view('panel.analytics.index', [
            'totalReports' => $totalReports,
            'resolutionRate' => $totalReports > 0 ? round($resolvedCount / $totalReports * 100) : 0,
            'medianFirstResponseHours' => $this->medianFirstResponseHours(),
            'categoryCounts' => $categoryCounts,
            'months' => $months,
            'resolutionByDepartment' => $resolutionByDepartment,
        ]);
    }

    /**
     * Median hours between a report being submitted and the first update
     * posted on it. The relation is ordered newest-first by default, so it is
     * re-ordered here; otherwise the second-newest update would be measured.
     */
    private function medianFirstResponseHours(): ?float
    {
        $hours = Report::with(['statusHistory' => fn ($q) => $q->reorder()->oldest()->oldest('id')])
            ->get()
            ->map(function (Report $report) {
                $secondEntry = $report->statusHistory->skip(1)->first();

                return $secondEntry?->created_at ? $report->created_at->diffInHours($secondEntry->created_at) : null;
            })
            ->filter(fn ($value) => $value !== null)
            ->sort()
            ->values();

        if ($hours->isEmpty()) {
            return null;
        }

        $middle = intdiv($hours->count(), 2);

        $median = $hours->count() % 2 === 0
            ? ($hours[$middle - 1] + $hours[$middle]) / 2
            : $hours[$middle];

        return round($median, 1);
    }
}
