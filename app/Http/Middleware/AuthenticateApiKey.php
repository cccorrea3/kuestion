<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = config('app.api_key');

        if (!$key || $request->header('X-App-Key') !== $key) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
