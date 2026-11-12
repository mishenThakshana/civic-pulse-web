<?php

namespace App\Services;

use App\Http\Middleware\SetCitizenLocale;
use App\Models\DeviceToken;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Throwable;

class PushNotificationService
{
    /**
     * Sends the same push notification to every device registered for the user.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $this->sendLocalizedToUser($user, fn (string $locale): array => [$title, $body], $data);
    }

    /**
     * Sends a push notification to every device registered for the user, in the
     * language each device chose in the app. Devices are grouped by locale so
     * there is one multicast per language.
     *
     * Firebase isn't required for the rest of the app to work, so a missing or
     * invalid service account (no `FIREBASE_CREDENTIALS` yet) is logged and
     * swallowed rather than thrown: push is an enhancement, the status history
     * and in-app alerts are the source of truth.
     *
     * @param  Closure(string): array{0: string, 1: string}  $messageFor  Returns [title, body] for a locale.
     * @param  array<string, mixed>  $data
     */
    public function sendLocalizedToUser(User $user, Closure $messageFor, array $data = []): void
    {
        $devices = $user->deviceTokens()->get(['token', 'locale']);

        if ($devices->isEmpty()) {
            return;
        }

        $byLocale = $devices->groupBy(fn (DeviceToken $device): string => SetCitizenLocale::normalize($device->locale));

        foreach ($byLocale as $locale => $group) {
            [$title, $body] = $messageFor($locale);

            $message = CloudMessage::new()
                ->withNotification(FirebaseNotification::create($title, $body))
                ->withData(array_map('strval', $data));

            try {
                // Resolved lazily (rather than constructor-injected) so an unconfigured
                // Firebase project only breaks this call, not every controller that
                // depends on this service.
                $report = app(Messaging::class)->sendMulticast($message, $group->pluck('token')->all());

                $invalid = array_merge($report->invalidTokens(), $report->unknownTokens());
                if ($invalid !== []) {
                    DeviceToken::whereIn('token', $invalid)->delete();
                }
            } catch (Throwable $e) {
                Log::warning('Push notification not sent: '.$e->getMessage());
            }
        }
    }
}
