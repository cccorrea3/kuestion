<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class VersionTimelineFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->question = Question::factory()->create(['user_id' => $this->user->uuid]);
    }

    private function renderTimeline(array $feedbacks): string
    {
        $versions = collect();

        foreach ($feedbacks as $number => $feedback) {
            $versions->push($this->question->versions()->create([
                'version_number' => $number,
                'answer_text' => 'Respuesta v'.$number,
                'confidence' => 80,
                'sources' => [],
                'response_hash' => hash('sha256', 'Respuesta v'.$number),
                'is_current' => $number === count($feedbacks),
                'status' => 'accepted',
                'feedback' => $feedback,
            ]));
        }

        // El detalle pasa las versiones en orden desc (más nueva primero).
        $versions = $versions->sortByDesc('version_number')->values();

        return View::make('components.version-timeline', [
            'versions' => $versions,
            'currentVersionId' => $versions->first()?->id,
        ])->render();
    }

    public function test_timeline_shows_feedback_icons_per_version(): void
    {
        $html = $this->renderTimeline([1 => 'helpful', 2 => 'not_helpful']);

        $this->assertStringContainsString('thumbs-up', $html);
        $this->assertStringContainsString('thumbs-down', $html);
    }

    public function test_timeline_shows_improved_trend(): void
    {
        // v1 = not_helpful, v2 = helpful → "mejoró" en la versión más nueva.
        $html = $this->renderTimeline([1 => 'not_helpful', 2 => 'helpful']);

        $this->assertStringContainsString('mejoró', $html);
        $this->assertStringNotContainsString('empeoró', $html);
    }

    public function test_timeline_shows_worsened_trend(): void
    {
        // v1 = helpful, v2 = not_helpful → "empeoró" en la versión más nueva.
        $html = $this->renderTimeline([1 => 'helpful', 2 => 'not_helpful']);

        $this->assertStringContainsString('empeoró', $html);
        $this->assertStringNotContainsString('mejoró', $html);
    }

    public function test_timeline_hides_trend_when_previous_has_no_feedback(): void
    {
        $html = $this->renderTimeline([1 => null, 2 => 'helpful']);

        $this->assertStringContainsString('thumbs-up', $html);
        $this->assertStringNotContainsString('mejoró', $html);
        $this->assertStringNotContainsString('empeoró', $html);
    }

    public function test_timeline_shows_no_icon_without_feedback(): void
    {
        $html = $this->renderTimeline([1 => null, 2 => null]);

        $this->assertStringNotContainsString('thumbs-up', $html);
        $this->assertStringNotContainsString('thumbs-down', $html);
    }
}
