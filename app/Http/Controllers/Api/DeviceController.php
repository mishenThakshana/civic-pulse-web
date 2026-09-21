<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterDeviceRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        DeviceToken::updateOrCreate(
            ['token' => $request->validated('token')],
            ['user_id' => $request->user()->id, 'platform' => $request->validated('platform')]
        );

        return response()->json(['message' => 'Device registered.'], 201);
    }
}
