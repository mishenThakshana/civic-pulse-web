<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['report_id', 'from_status', 'to_status', 'changed_by', 'changed_by_department_id', 'note', 'photo_path'])]
class ReportStatusHistory extends Model
{
    protected $table = 'report_status_history';

    protected function casts(): array
    {
        return [
            'from_status' => ReportStatus::class,
            'to_status' => ReportStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function changedByDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'changed_by_department_id');
    }

    /**
     * Who posted this update: a panel account, or a department's own system.
     */
    public function authorName(): string
    {
        return $this->changedBy?->name
            ?? ($this->changedByDepartment ? "{$this->changedByDepartment->name} (via API)" : 'System');
    }
}
