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
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'report_id' => $this->report->id,
        ];
    }

    public function title(): string
    {
        return match ($this->report->status) {
            ReportStatus::InProgress => 'Work has started on your report',
            ReportStatus::Resolved => 'Your report was resolved',
            ReportStatus::Open => 'Your report was updated',
        };
    }

    public function body(): string
    {
        return $this->report->department
            ? "{$this->report->department->name} is handling \"{$this->report->title}\"."
            : "\"{$this->report->title}\" has been updated.";
    }
}
