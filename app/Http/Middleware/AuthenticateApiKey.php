<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates wakatime-cli requests by API key.
 *
 * The key arrives base64-encoded in a `Basic`/`Bearer` Authorization header
 * (optionally with a trailing colon) or as an `api_key` query param. On success
 * the resolved user is authenticated for the request.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = self::extractKey($request);
        $user = $key === null ? null : User::where('api_key', $key)->first();

        if (! $user instanceof User) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Don't let the credential travel further into the application.
        $request->query->remove('api_key');

        Auth::setUser($user);

        return $next($request);
    }

    private static function extractKey(Request $request): ?string
    {
        $key = self::keyFromAuthorizationHeader($request->header('Authorization'));

        if ($key !== null) {
            return $key;
        }

        $query = $request->query('api_key');

        return is_string($query) && $query !== '' ? $query : null;
    }

    /**
     * wakatime-cli base64-encodes the key into a `Basic`/`Bearer` header, often
     * with the trailing colon from Basic auth's `key:password` form. Decode it
     * when it is valid base64, otherwise treat the token as the raw key, then
     * strip any surrounding whitespace and the colon.
     */
    private static function keyFromAuthorizationHeader(?string $header): ?string
    {
        if (! is_string($header) || $header === '') {
            return null;
        }

        $token = Str::after($header, ' ');
        $decoded = base64_decode($token, true);
        $key = trim($decoded !== false && $decoded !== '' ? $decoded : $token, " \t\n\r\0\x0B:");

        return $key === '' ? null : $key;
    }
}
