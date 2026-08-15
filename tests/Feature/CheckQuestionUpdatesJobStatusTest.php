<?php

namespace Tests\Feature;

use App\Jobs\CheckQuestionUpdatesJob;
use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use App\Services\KuaforiaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckQuestionUpdatesJobStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_job_marks_repository_invalid_on_401(): void
    {
        Http::fake([
            '*/consult*' => Http::response(['error' => 'invalid key'], 401),
        ]);

        $repo = Repository::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active']);
        $this->questionWithRepo($repo);

        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        $this->assertSame('invalid', $repo->fresh()->status);
        $this->assertNotNull($repo->fresh()->last_validated_at);
        $this->assertNotNull($repo->fresh()->last_used_at); // P9
    }

    public function test_job_keeps_repository_active_on_service_error(): void
    {
        Http::fake([
            '*/consult*' => Http::response([], 503),
        ]);

        $repo = Repository::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active']);
        $this->questionWithRepo($repo);

        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        // 503: el repo sigue active y el job reintenta con el backoff existente.
        $this->assertSame('active', $repo->fresh()->status);
        $this->assertSame(1, $repo->questions()->first()->versions()->count());
    }

    public function test_401_does_not_trigger_circuit_breaker_pause(): void
    {
        Http::fake([
            '*/consult*' => Http::response(['error' => 'invalid key'], 401),
        ]);

        $repo = Repository::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active']);

        // P10 — 3 corridas con 401: el breaker (por servicio) no debe pausar Kuaforia.
        for ($i = 0; $i < 3; $i++) {
            $this->questionWithRepo($repo);
            (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));
        }

        $this->assertNull(Cache::get('kuaforia:paused'));
    }

    public function test_job_uses_repository_tenant_for_consult(): void
    {
        $requestedUrl = null;

        Http::fake(function ($request) use (&$requestedUrl) {
            $requestedUrl = $request->url();

            return Http::response([
                'answer' => 'Nueva respuesta',
                'confidence' => 90,
                'sources' => [],
            ]);
        });

        $repo = Repository::factory()->create([
            'user_id' => $this->user->uuid,
            'resolved_tenant_slug' => 'qubeka',
        ]);
        $this->questionWithRepo($repo);

        (new CheckQuestionUpdatesJob)->handle(app(KuaforiaService::class));

        $this->assertStringContainsString('/api/consult/qubeka', $requestedUrl);
    }

    private function questionWithRepo(Repository $repo): Question
    {
        $question = Question::factory()->create([
            'user_id' => $this->user->uuid,
            'repository_id' => $repo->id,
            'status' => 'active',
            'review_frequency' => 'weekly',
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'Respuesta original',
            'confidence' => 80,
            'sources' => [],
            'response_hash' => hash('sha256', 'Respuesta original'),
            'is_current' => true,
        ]);

        return $question;
    }
}
