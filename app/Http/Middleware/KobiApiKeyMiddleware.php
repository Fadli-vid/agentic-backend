<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class KobiApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = env('KOBI_API_KEY');

        if (!$expectedKey) {
            Log::warning('KOBI_API_KEY is not configured.');
            return response()->json(['message' => 'API key is not configured.'], 503);
        }

        $providedKey = $request->header('X-KOBI-KEY');

        if (!$providedKey || !hash_equals($expectedKey, $providedKey)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
