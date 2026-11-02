<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\ReportStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Report;
use App\Models\ReportStatusHistory;
use App\Models\User;
use App\Notifications\ReportStatusUpdated;
use Illuminate\Http\UploadedFile;

/**
 * The single path for posting a status update on a report, shared by the
 * admin panel and the department integration API so both record history,
 * notify the citizen, and write the audit log the same way.
 */
class ReportUpdateService
{
    public function __construct(private readonly PushNotificationService $push) {}

    public function record(
        Report $report,
        ReportStatus $toStatus,
        ?string $note = null,
        ?UploadedFile $photo = null,
        ?User $byUser = null,
        ?Department $byDepartment = null,
    ): ReportStatusHistory {
        $fromStatus = $report->status;

        $report->status = $toStatus;
        $report->save();
        $report->touch();

        $entry = $report->statusHistory()->create([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => $byUser?->id,
            'changed_by_department_id' => $byDepartment?->id,
            'note' => $note,
            'photo_path' => $photo?->store('report-updates', 'public'),
        ]);

        $report->load('department');

        $summary = $fromStatus === $toStatus
            ? "Updated report #{$report->id} ({$toStatus->label()})"
            : "Moved report #{$report->id} from {$fromStatus->label()} to {$toStatus->label()}";

        ActivityLog::record(
            ActivityAction::ReportUpdated,
            $byDepartment ? "{$byDepartment->name} ".lcfirst($summary).' via the integration API.' : "{$summary}.",
            $report,
            array_filter([
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
                'department' => $report->department?->name,
                'source' => $byDepartment ? 'api' : null,
            ]),
            $byUser,
        );

        $notification = new ReportStatusUpdated($report);
        $report->user->notify($notification);
        $this->push->sendToUser($report->user, $notification->title(), $notification->body(), ['report_id' => $report->id]);

        return $entry;
    }
}
