<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Report::query()
            ->with(['category', 'department'])
            ->withCount('upvotes')
            ->with(['upvotes' => fn ($q) => $q->where('user_id', $request->user()->id)]);

        if ($request->filled('status')) {
            $query->where('status', ReportStatus::from($request->string('status')->toString()));
        }

        match ($request->string('sort', 'newest')->toString()) {
            'most_voted' => $query->orderByDesc('upvotes_count'),
            default => $query->latest(),
        };

        return ReportResource::collection($query->paginate(20));
    }

    public function store(StoreReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        $photoPath = $request->file('photo')->store('report-photos', 'public');

        $report = Report::create([
            'user_id' => $request->user()->id,
            'category_id' => $data['category_id'] ?? null,
            'title' => Str::limit($data['description'], 60),
            'description' => $data['description'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'address' => $data['address'],
            'contact_phone' => $data['contact_phone'],
            'photo_path' => $photoPath,
            'status' => ReportStatus::Open,
        ]);

        $report->statusHistory()->create([
            'from_status' => null,
            'to_status' => ReportStatus::Open,
            'changed_by' => $request->user()->id,
            'note' => 'Report received.',
        ]);

        // A citizen reporting an issue is implicitly affected by it — count
        // their own upvote from the start instead of leaving it at zero.
        $report->upvotes()->create(['user_id' => $request->user()->id]);

        $report->load(['category', 'department'])
            ->loadCount('upvotes')
            ->load(['upvotes' => fn ($q) => $q->where('user_id', $request->user()->id)]);

        return (new ReportResource($report))->response()->setStatusCode(201);
    }

    public function show(Report $report, Request $request): ReportResource
    {
        $report->load(['category', 'department', 'user', 'statusHistory.changedBy'])
            ->loadCount('upvotes')
            ->load(['upvotes' => fn ($q) => $q->where('user_id', $request->user()->id)]);

        return new ReportResource($report);
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->reports()
            ->with(['category', 'department'])
            ->withCount('upvotes');

        if ($request->filled('status')) {
            $query->where('status', ReportStatus::from($request->string('status')->toString()));
        }

        return ReportResource::collection($query->latest()->paginate(20));
    }

    public function upvote(Report $report, Request $request): JsonResponse
    {
        try {
            $report->upvotes()->create(['user_id' => $request->user()->id]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'You have already voted on this report.'], 422);
        }

        return response()->json(['votes_count' => $report->upvotes()->count()]);
    }

    public function removeUpvote(Report $report, Request $request): JsonResponse
    {
        $report->upvotes()->where('user_id', $request->user()->id)->delete();

        return response()->json(['votes_count' => $report->upvotes()->count()]);
    }
}
