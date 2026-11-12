<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Notifications\ReportStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        return $request->user()->notifications()->paginate(20)->through(function (DatabaseNotification $notification): array {
            $data = $notification->data;
            $status = isset($data['status']) ? ReportStatus::tryFrom($data['status']) : null;

            return [
                'id' => $notification->id,
                // Alerts stored before structured fields existed keep their original English text.
                'title' => $status ? ReportStatusUpdated::titleFor($status) : ($data['title'] ?? null),
                'body' => $status && isset($data['report_title'])
                    ? ReportStatusUpdated::bodyFor($data['department'] ?? null, $data['report_title'])
                    : ($data['body'] ?? null),
                'report_id' => $data['report_id'] ?? null,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ];
        });
    }
}
