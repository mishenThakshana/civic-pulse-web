<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'address' => $this->address,
            'photo_url' => asset('storage/'.$this->photo_path),
            'status' => $this->status->value,
            'votes_count' => (int) ($this->upvotes_count ?? $this->upvotes()->count()),
            'voted_by_me' => $this->relationLoaded('upvotes') ? $this->upvotes->isNotEmpty() : null,
            'reported_by' => $this->whenLoaded('user', fn () => $this->user->name),
            'status_history' => ReportStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
