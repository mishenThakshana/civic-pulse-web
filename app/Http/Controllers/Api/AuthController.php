<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $nicPhotoFrontPath = $request->file('nic_photo_front')->store('nic-photos', 'public');
        $nicPhotoBackPath = $request->file('nic_photo_back')->store('nic-photos', 'public');

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'nic_number' => $data['nic_number'],
            'nic_photo_front_path' => $nicPhotoFrontPath,
            'nic_photo_back_path' => $nicPhotoBackPath,
            'password' => Hash::make($data['password']),
            'role' => UserRole::Citizen,
            'approval_status' => ApprovalStatus::Pending,
            'is_active' => true,
        ]);

        // No token is issued here: the account is pending until an admin
        // approves it, and `login()` refuses unapproved citizens anyway.
        return response()->json([
            'user' => new UserResource($user),
            'message' => 'Your account has been submitted for review. You will be able to sign in once an administrator approves your NIC.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($reason = $this->accessDeniedReason($user)) {
            throw ValidationException::withMessages(['email' => [$reason]]);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'user' => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Revalidates a stored token so the app can drop sessions that belong to an
     * account which was never approved, was rejected, or has been deactivated.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($reason = $this->accessDeniedReason($user)) {
            $user->currentAccessToken()->delete();

            return response()->json(['message' => $reason], 403);
        }

        return response()->json(['user' => new UserResource($user)]);
    }

    /**
     * The reason this account may not hold a session, or null when it may.
     */
    private function accessDeniedReason(User $user): ?string
    {
        if (! $user->is_active) {
            return 'This account has been deactivated.';
        }

        if ($user->role === UserRole::Citizen && $user->approval_status !== ApprovalStatus::Approved) {
            return match ($user->approval_status) {
                ApprovalStatus::Rejected => $user->rejection_reason
                    ? "Your registration was not approved: {$user->rejection_reason}"
                    : 'Your registration was not approved. Please contact support.',
                default => 'Your account is still pending admin approval.',
            };
        }

        return null;
    }
}
