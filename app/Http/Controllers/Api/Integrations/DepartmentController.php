<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * The department this key belongs to, what the key may do, and a summary
     * of the department's current workload. Useful as a connection check.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Department $department */
        $department = $request->attributes->get('department');
        /** @var DepartmentApiKey $apiKey */
        $apiKey = $request->attributes->get('apiKey');

        $counts = $department->reports()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'id' => $department->id,
                'name' => $department->name,
                'contact_email' => $department->contact_email,
                'report_counts' => collect(ReportStatus::cases())
                    ->mapWithKeys(fn (ReportStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)]),
                'api_key' => [
                    'name' => $apiKey->name,
                    'prefix' => $apiKey->key_prefix,
                    'can_update_reports' => $apiKey->can_update_reports,
                ],
            ],
        ]);
    }
}
