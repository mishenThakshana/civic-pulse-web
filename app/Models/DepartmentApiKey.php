<?php

namespace App\Models;

use Database\Factories\DepartmentApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['department_id', 'name', 'key_prefix', 'key_hash', 'can_update_reports', 'last_used_at', 'last_used_ip', 'created_by'])]
#[Hidden(['key_hash'])]
class DepartmentApiKey extends Model
{
    /** @use HasFactory<DepartmentApiKeyFactory> */
    use HasFactory;

    public const PREFIX = 'cpk_';

    protected function casts(): array
    {
        return [
            'can_update_reports' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Issue a new key. Only the hash is stored, so the plain-text key in the
     * returned pair is the one and only time it can be shown.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Department $department, string $name, bool $canUpdateReports, ?User $createdBy = null): array
    {
        $plainKey = static::PREFIX.Str::random(40);

        $apiKey = $department->apiKeys()->create([
            'name' => $name,
            'key_prefix' => substr($plainKey, 0, 12),
            'key_hash' => static::hash($plainKey),
            'can_update_reports' => $canUpdateReports,
            'created_by' => $createdBy?->id,
        ]);

        return [$apiKey, $plainKey];
    }

    public static function findByPlainKey(string $plainKey): ?self
    {
        if (! str_starts_with($plainKey, static::PREFIX)) {
            return null;
        }

        return static::with('department')->where('key_hash', static::hash($plainKey))->first();
    }

    public static function hash(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
