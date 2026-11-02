<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A report as seen by a department's own system through the integration API.
 * Deliberately excludes the reporter's email, NIC and account details.
 */
class DepartmentReportResource extends JsonResource
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
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'category' => $this->whenLoaded('category', fn () => $this->category ? new CategoryResource($this->category) : null),
            'location' => [
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'address' => $this->address,
                'map_url' => "https://www.google.com/maps/search/?api=1&query={$this->latitude},{$this->longitude}",
            ],
            'reporter' => $this->whenLoaded('user', fn () => [
                'name' => $this->user->name,
                'contact_phone' => $this->contact_phone ?? $this->user->phone,
            ]),
            'photo_url' => asset('storage/'.$this->photo_path),
            'votes_count' => (int) $this->upvotes_count,
            'status_history' => ReportStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
