<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Integrations\StoreReportUpdateRequest;
use App\Http\Resources\DepartmentReportResource;
use App\Http\Resources\ReportStatusHistoryResource;
use App\Models\Department;
use App\Models\Report;
use App\Services\ReportUpdateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /**
     * Sort keys accepted by `?sort=`, mapped to [column, direction].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SORTS = [
        '-created_at' => ['created_at', 'desc'],
        'created_at' => ['created_at', 'asc'],
        '-updated_at' => ['updated_at', 'desc'],
        'updated_at' => ['updated_at', 'asc'],
        '-votes_count' => ['upvotes_count', 'desc'],
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ReportStatus::class)],
            'category_id' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'],
            'sort' => ['nullable', Rule::in(array_keys(self::SORTS))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$column, $direction] = self::SORTS[$filters['sort'] ?? '-created_at'];

        $reports = $this->departmentReports($request)
            ->with(['category', 'user'])
            ->withCount('upvotes')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $q, int $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['updated_since'] ?? null, fn (Builder $q, string $since) => $q->where('updated_at', '>=', Carbon::parse($since)->setTimezone(config('app.timezone'))))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return DepartmentReportResource::collection($reports);
    }

    public function show(Request $request, int $reportId): DepartmentReportResource
    {
        return new DepartmentReportResource($this->findReport($request, $reportId));
    }

    public function storeUpdate(StoreReportUpdateRequest $request, int $reportId, ReportUpdateService $reportUpdates): JsonResponse
    {
        $report = $this->findReport($request, $reportId);

        $entry = $reportUpdates->record(
            $report,
            ReportStatus::from($request->validated('status')),
            $request->validated('note'),
            $request->file('photo'),
            byDepartment: $request->attributes->get('department'),
        );

        $entry->load(['changedBy', 'changedByDepartment']);

        return response()->json([
            'data' => [
                'update' => new ReportStatusHistoryResource($entry),
                'report' => new DepartmentReportResource($this->findReport($request, $reportId)),
            ],
        ], 201);
    }

    /**
     * @return Builder<Report>
     */
    private function departmentReports(Request $request): Builder
    {
        /** @var Department $department */
        $department = $request->attributes->get('department');

        return Report::query()->where('department_id', $department->id);
    }

    /**
     * Reports outside this department are reported as not found, so a key can
     * never be used to discover what other departments are working on.
     */
    private function findReport(Request $request, int $reportId): Report
    {
        $report = $this->departmentReports($request)
            ->with(['category', 'user', 'statusHistory.changedBy', 'statusHistory.changedByDepartment'])
            ->withCount('upvotes')
            ->find($reportId);

        abort_if($report === null, 404, 'Report not found, or not assigned to your department.');

        return $report;
    }
}
