<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ReportRejectedException extends Exception
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(string $message, public int $status = 422, public array $reasons = [])
    {
        parent::__construct($message);
    }

    public static function flaggedAsSpam(array $reasons): self
    {
        return new self(
            'Our AI screening flagged this report as possible spam or an unrelated photo, so it was not posted. '
            .'Please submit a clear photo of the issue with a short, genuine description.',
            422,
            $reasons,
        );
    }

    public static function screeningUnavailable(): self
    {
        return new self('AI screening is unavailable right now, so your report could not be posted. Please try again shortly.', 503);
    }

    /**
     * Matches Laravel's validation error shape so the mobile app's existing
     * error handling shows the message without special-casing it.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['screening' => [$this->getMessage()]],
            'screening' => ['reasons' => $this->reasons],
        ], $this->status);
    }
}
