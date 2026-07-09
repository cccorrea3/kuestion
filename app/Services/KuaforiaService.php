<?php

namespace App\Services;

use App\Exceptions\KuaforiaException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KuaforiaService
{
    public function consult(string $question, ?string $conversationId = null): KuaforiaResponse
    {
        if (Cache::get('kuaforia:paused')) {
            throw new KuaforiaException('Kuaforia en pausa temporal. Intenta de nuevo en unos segundos.');
        }

        $response = Http::timeout(30)
            ->withToken(config('services.kuaforia.api_key'))
            ->post(config('services.kuaforia.url'), [
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
            ]);
            throw new KuaforiaException('Kuaforia respondió con error: ' . $response->status());
        }

        Cache::forget('kuaforia:failures');

        $body = $response->json();

        return new KuaforiaResponse(
            answerText: $body['answer'] ?? $body['response'] ?? '',
            confidence: (float) ($body['confidence'] ?? 0),
            sources: $body['sources'] ?? [],
        );
    }
}
