<?php

namespace App\Http\Middleware;

use App\Models\DepartmentApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a department's system by its API key (sent as a bearer token)
 * and exposes the key and its department as request attributes.
 *
 * Usage: ->middleware('department.key') for read access,
 *        ->middleware('department.key:write') for endpoints that change data.
 */
class AuthenticateDepartmentApiKey
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $apiKey = $request->bearerToken() ? DepartmentApiKey::findByPlainKey($request->bearerToken()) : null;

        if (! $apiKey) {
            return response()->json(['message' => 'Missing or invalid API key.'], 401);
        }

        if ($ability === 'write' && ! $apiKey->can_update_reports) {
            return response()->json(['message' => 'This API key is read-only and cannot update reports.'], 403);
        }

        if (! $apiKey->last_used_at || $apiKey->last_used_at->lt(now()->subMinute())) {
            $apiKey->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('apiKey', $apiKey);
        $request->attributes->set('department', $apiKey->department);

        return $next($request);
    }
}
