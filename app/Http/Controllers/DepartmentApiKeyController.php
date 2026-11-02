<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DepartmentApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DepartmentApiKeyController extends Controller
{
    public function store(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'access' => ['required', 'in:read,read_write'],
        ]);

        [$apiKey, $plainKey] = DepartmentApiKey::issue($department, $data['name'], $data['access'] === 'read_write', $request->user());

        ActivityLog::record(
            ActivityAction::ApiKeyCreated,
            "Issued API key \"{$apiKey->name}\" ({$apiKey->key_prefix}…) to {$department->name}.",
            $department,
            ['access' => $data['access']],
        );

        return back()
            ->with('new_api_key', $plainKey)
            ->with('new_api_key_department', $department->name)
            ->with('status', "API key \"{$apiKey->name}\" issued to {$department->name}.");
    }

    public function destroy(Department $department, DepartmentApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        ActivityLog::record(
            ActivityAction::ApiKeyRevoked,
            "Revoked API key \"{$apiKey->name}\" ({$apiKey->key_prefix}…) from {$department->name}.",
            $department,
        );

        return back()->with('status', "API key \"{$apiKey->name}\" has been revoked. Systems using it will stop working immediately.");
    }
}
