<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ButtonLayoutTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseMigrations;

    public function test_three_buttons_render_and_approve_first(): void
    {
        $user = User::factory()->create();
        Repository::factory()->create([
            'user_id' => $user->uuid,
            'credential' => ['api_token' => 'test-token'],
        ]);

        $data = [
            'session_id' => 13,
            'is_simple' => true,
            'nodos' => [
                ['id' => 'sandbox_13_n0', 'tipo' => 'Q', 'texto' => 'PREGUNTA_UNO', 'relaciones' => []],
                ['id' => 'sandbox_13_n1', 'tipo' => 'H', 'texto' => 'HIPOTESIS_UNO', 'relaciones' => []],
            ],
        ];

        Http::fake(['*/sesiones-analisis/13' => Http::response(['data' => $data], 200)]);

        $html = $this->actingAs($user)
            ->get(route('contributions.review', ['sessionId' => 13]))
            ->getContent();

        $this->assertStringContainsString('Aprobar', $html);
        $this->assertStringContainsString('Editar texto', $html);
        $this->assertStringContainsString('Descartar', $html);

        // "Aprobar" debe aparecer antes que "Descartar" (más prominente/no cortado por overflow)
        $posA = strpos($html, 'Aprobar');
        $posD = strpos($html, 'Descartar');
        $this->assertLessThan($posD, $posA, 'Aprobar debe renderizarse antes que Descartar');
    }
}
