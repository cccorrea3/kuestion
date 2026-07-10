<?php

namespace App\Services;

use App\Exceptions\KuaforiaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KuaforiaService
{
    public function consult(string $question, ?string $conversationId = null, ?string $tenantSlug = null): KuaforiaResponse
    {
        if (Cache::get('kuaforia:paused')) {
            throw new KuaforiaException('Kuaforia en pausa temporal. Intenta de nuevo en unos segundos.');
        }

        $tenantSlug ??= auth()->user()?->tenant_slug;

        if (!$tenantSlug) {
            throw new KuaforiaException('No se pudo resolver el tenant para la consulta.');
        }

        $baseUrl = rtrim(config('services.kuaforia.base_url'), '/');
        $url = "{$baseUrl}/api/consult/{$tenantSlug}";

        $response = Http::timeout(120)
            ->withToken(config('services.kuaforia.api_key'))
            ->post($url, [
                'question' => $question,
                'conversation_id' => $conversationId,
            ]);

        if ($response->failed()) {
            $failures = Cache::increment('kuaforia:failures', 1, 120);
            if ($failures >= 3) {
                Cache::put('kuaforia:paused', true, 60);
                Cache::forget('kuaforia:failures');
            }
            Log::warning('Kuaforia request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'failures' => $failures,
                'tenant' => $tenantSlug,
            ]);
            throw new KuaforiaException('Kuaforia respondió con error: ' . $response->status());
        }

        Cache::forget('kuaforia:failures');

        $body = $response->json();

        return new KuaforiaResponse(
            answerText: $body['answer'] ?? $body['response'] ?? '',
            confidence: (float) ($body['confidence'] ?? 0),
            sources: $body['sources'] ?? [],
            conversationId: $body['conversation_id'] ?? null,
        );
    }
}
