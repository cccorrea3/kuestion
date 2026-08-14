<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_csp_header_in_production_has_no_unsafe_inline_and_includes_nonce(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $response = $this->get('/');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'Falta el header Content-Security-Policy en production.');
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unpkg.com', $csp);
        $this->assertMatchesRegularExpression('/script-src[^;]*\'nonce-[^\']+\'/', $csp);
        $this->assertMatchesRegularExpression('/style-src[^;]*\'nonce-[^\']+\'/', $csp);
    }

    public function test_csp_not_emitted_outside_production(): void
    {
        $response = $this->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_base_security_headers_always_present(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_layout_has_no_inline_scripts_or_cdn_lucide(): void
    {
        $this->actingAs(User::factory()->create());

        $content = $this->get('/questions')->getContent();

        // Sin CDN ni script inline de lucide (bundleado local via Vite).
        $this->assertStringNotContainsString('unpkg.com', $content);
        $this->assertStringNotContainsString('<script>lucide.createIcons', $content);

        // Los scripts que quedan (Livewire/Vite) llevan nonce.
        preg_match_all('/<script[^>]*>/', $content, $matches);
        foreach ($matches[0] as $scriptTag) {
            $this->assertStringContainsString('nonce=', $scriptTag, "Tag sin nonce: {$scriptTag}");
        }
    }
}
