<?php

namespace App\Notifications;

use App\Enums\ReportStatus;
use App\Models\Report;
use Illuminate\Notifications\Notification;

class ReportStatusUpdated extends Notification
{
    public function __construct(public Report $report)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The structured fields are stored alongside the English text so the
     * citizen API can render the alert in whichever language the app asks for.
     *
     * @return array{title: string, body: string, report_id: int, status: string, department: ?string, report_title: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title('en'),
            'body' => $this->body('en'),
            'report_id' => $this->report->id,
            'status' => $this->report->status->value,
            'department' => $this->report->department?->name,
            'report_title' => $this->report->title,
        ];
    }

    public function title(?string $locale = null): string
    {
        return self::titleFor($this->report->status, $locale);
    }

    public function body(?string $locale = null): string
    {
        return self::bodyFor($this->report->department?->name, $this->report->title, $locale);
    }

    public static function titleFor(ReportStatus $status, ?string $locale = null): string
    {
        return match ($status) {
            ReportStatus::InProgress => __('Work has started on your report', [], $locale),
            ReportStatus::Resolved => __('Your report was resolved', [], $locale),
            ReportStatus::Open => __('Your report was updated', [], $locale),
        };
    }

    public static function bodyFor(?string $department, string $reportTitle, ?string $locale = null): string
    {
        return $department
            ? __(':department is handling ":title".', ['department' => $department, 'title' => $reportTitle], $locale)
            : __('":title" has been updated.', ['title' => $reportTitle], $locale);
    }
}
