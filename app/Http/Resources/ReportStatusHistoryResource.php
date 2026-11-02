<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportStatusHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'note' => $this->note,
            'photo_url' => $this->photo_path ? asset('storage/'.$this->photo_path) : null,
            'changed_by' => $this->when($this->relationLoaded('changedBy'), fn () => $this->authorName()),
            'created_at' => $this->created_at,
        ];
    }
}
