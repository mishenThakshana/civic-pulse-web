<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationController extends Controller
{
    public function index(Request $request): LengthAwarePaginator
    {
        return $request->user()->notifications()->paginate(20)->through(fn ($notification) => [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? null,
            'body' => $notification->data['body'] ?? null,
            'report_id' => $notification->data['report_id'] ?? null,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ]);
    }
}
