<?php

namespace Tests\Feature;

use App\Models\AnswerVersion;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.api_key' => 'test']);
        config(['app.user_id' => '00000000-0000-0000-0000-000000000001']);
    }

    public function test_accept_change(): void
    {
        $question = Question::factory()->create([
            'user_id' => config('app.user_id'),
            'has_unreviewed_changes' => true,
        ]);

        $question->versions()->createMany([
            ['version_number' => 1, 'answer_text' => 'v1', 'confidence' => 90, 'response_hash' => hash('sha256', 'v1'), 'is_current' => false, 'status' => 'accepted'],
            ['version_number' => 2, 'answer_text' => 'v2', 'confidence' => 95, 'response_hash' => hash('sha256', 'v2'), 'is_current' => true, 'status' => 'pending'],
        ]);

        $response = $this->postJson("/api/questions/{$question->id}/accept-change", [], ['X-App-Key' => 'test']);

        $response->assertStatus(200);
        $this->assertFalse($question->fresh()->has_unreviewed_changes);
    }

    public function test_dismiss_change(): void
    {
        $question = Question::factory()->create([
            'user_id' => config('app.user_id'),
            'has_unreviewed_changes' => true,
            'answer_text' => 'v1',
        ]);

        $question->versions()->createMany([
            ['version_number' => 1, 'answer_text' => 'v1', 'confidence' => 90, 'response_hash' => hash('sha256', 'v1'), 'is_current' => false, 'status' => 'accepted'],
            ['version_number' => 2, 'answer_text' => 'v2', 'confidence' => 95, 'response_hash' => hash('sha256', 'v2'), 'is_current' => true, 'status' => 'pending'],
        ]);

        $response = $this->postJson("/api/questions/{$question->id}/dismiss-change", [], ['X-App-Key' => 'test']);

        $response->assertStatus(200);
        $this->assertFalse($question->fresh()->has_unreviewed_changes);
        $this->assertEquals('v1', $question->fresh()->answer_text);
    }
}
