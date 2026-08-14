<?php

namespace Tests\Feature;

use App\Jobs\CleanupOldVersionsJob;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupOldVersionsJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_keeps_only_last_versions_for_archived_questions(): void
    {
        $archived = Question::factory()->create(['user_id' => $this->user->uuid]);
        $archived->delete(); // archivada = soft-deleted
        $this->seedVersions($archived, 7);

        $active = Question::factory()->create(['user_id' => $this->user->uuid]);
        $this->seedVersions($active, 7);

        (new CleanupOldVersionsJob)->handle();

        // Archivada: conserva las últimas 5 (default).
        $this->assertSame(5, $archived->versions()->count());
        $this->assertSame(7, $active->versions()->count());
    }

    public function test_retention_is_configurable(): void
    {
        config(['kuestion.retention.archived_versions' => 3]);

        $archived = Question::factory()->create(['user_id' => $this->user->uuid]);
        $archived->delete();
        $this->seedVersions($archived, 7);

        (new CleanupOldVersionsJob)->handle();

        $this->assertSame(3, $archived->versions()->count());
    }

    private function seedVersions(Question $question, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $question->versions()->create([
                'version_number' => $i,
                'answer_text' => "v{$i}",
                'confidence' => 80 + $i,
                'sources' => [],
                'response_hash' => hash('sha256', "v{$i}"),
                'is_current' => $i === $count,
            ]);
        }
    }
}
