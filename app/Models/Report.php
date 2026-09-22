<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'category_id', 'department_id', 'title', 'description',
    'latitude', 'longitude', 'address', 'contact_phone', 'photo_path', 'status',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<ReportStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReportStatusHistory::class)->latest();
    }

    /**
     * @return HasMany<Upvote, $this>
     */
    public function upvotes(): HasMany
    {
        return $this->hasMany(Upvote::class);
    }

    /**
     * Average time, in days, between a report being created and the most
     * recent time it was marked resolved — optionally scoped to one department.
     */
    public static function averageResolutionDays(?int $departmentId = null): ?float
    {
        $resolved = static::query()
            ->where('status', ReportStatus::Resolved)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->with(['statusHistory' => fn ($q) => $q->where('to_status', ReportStatus::Resolved)])
            ->get();

        $days = $resolved
            ->map(fn (self $report) => $report->statusHistory->first()?->created_at?->diffInHours($report->created_at) / 24)
            ->filter(fn ($value) => $value !== null);

        return $days->isNotEmpty() ? round($days->avg(), 1) : null;
    }
}
