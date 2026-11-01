<?php

namespace App\Services;

use App\Exceptions\ReportRejectedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReportScreeningService
{
    /**
     * Sends a not-yet-saved report (photo + description + location) through the
     * Civi Pulse AI pipeline and only returns when it's safe to publish.
     *
     * Screening happens before anything is stored, so a report flagged as spam
     * never reaches storage or the database. If the AI service can't be reached
     * the report is refused too, unless `CIVIC_AI_FAIL_OPEN` is enabled.
     *
     * @param  array{description: string, latitude: float|string, longitude: float|string}  $report
     * @return array<string, mixed> The AI service's analysis payload (empty when screening is disabled or skipped).
     *
     * @throws ReportRejectedException
     */
    public function screen(UploadedFile $photo, array $report): array
    {
        $config = config('services.civic_ai');

        if (! $config['enabled']) {
            return [];
        }

        try {
            $response = Http::baseUrl($config['url'])
                ->timeout($config['timeout'])
                ->acceptJson()
                ->attach('image', $photo->getContent(), $photo->getClientOriginalName(), ['Content-Type' => $photo->getMimeType()])
                ->post('/api/v1/analyze', [
                    'report_id' => 'pending-'.Str::uuid(),
                    'description' => $report['description'],
                    'latitude' => $report['latitude'],
                    'longitude' => $report['longitude'],
                ]);
        } catch (ConnectionException $e) {
            return $this->unavailable($config['fail_open'], $e->getMessage());
        }

        if ($response->failed()) {
            return $this->unavailable($config['fail_open'], "AI service responded with HTTP {$response->status()}");
        }

        $analysis = $response->json();

        if ($analysis['spam']['is_suspicious'] ?? false) {
            throw ReportRejectedException::flaggedAsSpam($analysis['spam']['reasons'] ?? []);
        }

        return $analysis;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReportRejectedException
     */
    private function unavailable(bool $failOpen, string $reason): array
    {
        Log::warning('Report screening unavailable: '.$reason);

        if ($failOpen) {
            return [];
        }

        throw ReportRejectedException::screeningUnavailable();
    }
}
