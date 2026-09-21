<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Throwable;

class PushNotificationService
{
    /**
     * Sends a push notification to every device registered for the given user.
     *
     * Firebase isn't required for the rest of the app to work, so a missing or
     * invalid service account (no `FIREBASE_CREDENTIALS` yet) is logged and
     * swallowed rather than thrown — see the interim report's risk register
     * (R3): push is an enhancement, the status history is the source of truth.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = $user->deviceTokens()->pluck('token', 'id');

        if ($tokens->isEmpty()) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FirebaseNotification::create($title, $body))
            ->withData(array_map('strval', $data));

        try {
            // Resolved lazily (rather than constructor-injected) so an unconfigured
            // Firebase project only breaks this call, not every controller that
            // depends on this service.
            $report = app(Messaging::class)->sendMulticast($message, $tokens->values()->all());

            $invalid = array_merge($report->invalidTokens(), $report->unknownTokens());
            if ($invalid !== []) {
                DeviceToken::whereIn('token', $invalid)->delete();
            }
        } catch (Throwable $e) {
            Log::warning('Push notification not sent: '.$e->getMessage());
        }
    }
}
