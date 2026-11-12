<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the language the citizen chose in the mobile app (sent as the
 * Accept-Language header) so API messages, alerts and system notes come back
 * in English, Sinhala or Tamil instead of a mix of languages.
 */
class SetCitizenLocale
{
    /**
     * Languages the citizen app offers.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['en', 'si', 'ta'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request->header('Accept-Language')));

        return $next($request);
    }

    /**
     * Picks the first supported language from an Accept-Language header such
     * as "si-LK,si;q=0.9,en;q=0.8", falling back to the application default.
     */
    public static function resolve(?string $header): string
    {
        foreach (explode(',', (string) $header) as $part) {
            $language = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));

            if (in_array($language, self::SUPPORTED, true)) {
                return $language;
            }
        }

        return config('app.locale');
    }

    /**
     * Normalises a stored locale (which may be null for older devices) to a supported one.
     */
    public static function normalize(?string $locale): string
    {
        return in_array($locale, self::SUPPORTED, true) ? $locale : config('app.locale');
    }
}
