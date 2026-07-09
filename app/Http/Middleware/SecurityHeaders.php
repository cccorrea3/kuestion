<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $response->headers->set('Content-Security-Policy',
                "default-src 'self'; ".
                "base-uri 'self'; ".
                "form-action 'self'; ".
                "script-src 'self' 'unsafe-inline' https://unpkg.com; ".
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; ".
                "font-src 'self' https://fonts.gstatic.com; ".
                "img-src 'self' data:; ".
                "connect-src 'self'; ".
                "frame-ancestors 'none'"
            );
        }

        return $response;
    }
}
