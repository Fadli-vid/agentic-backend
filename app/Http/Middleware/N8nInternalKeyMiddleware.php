<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class N8nInternalKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = env('N8N_INTERNAL_API_KEY');

        if (!$expectedKey) {
            Log::warning('N8N_INTERNAL_API_KEY is not configured.');
            return response()->json(['message' => 'Internal key is not configured.'], 503);
        }

        $providedKey = $request->header('X-N8N-KEY');

        if (!$providedKey || !hash_equals($expectedKey, $providedKey)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}
