<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = ActivityLog::query()->with('user');

        if (filled($data['user_id'] ?? null)) {
            $query->where('user_id', $data['user_id']);
        }

        if ($action = ActivityAction::tryFrom($data['action'] ?? '')) {
            $query->where('action', $action);
        }

        if (filled($data['from'] ?? null)) {
            $query->where('created_at', '>=', now()->parse($data['from'])->startOfDay());
        }

        if (filled($data['to'] ?? null)) {
            $query->where('created_at', '<=', now()->parse($data['to'])->endOfDay());
        }

        if (filled($data['search'] ?? null)) {
            $query->where('description', 'like', "%{$data['search']}%");
        }

        return view('panel.activity-log.index', [
            'entries' => $query->latest('created_at')->latest('id')->paginate(25)->withQueryString(),
            'todayCount' => ActivityLog::where('created_at', '>=', now()->startOfDay())->count(),
            'weekCount' => ActivityLog::where('created_at', '>=', now()->subDays(7))->count(),
            'activeStaffCount' => ActivityLog::where('created_at', '>=', now()->subDays(7))->distinct()->count('user_id'),
            'actions' => ActivityAction::cases(),
            'staffMembers' => User::whereIn('role', UserRole::panelRoles())->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
