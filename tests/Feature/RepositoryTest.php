<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_repositories_are_cascade_deleted_with_user(): void
    {
        $user = User::factory()->create();
        Repository::factory()->create(['user_id' => $user->uuid]);

        $this->assertDatabaseCount('repositories', 1);

        $user->delete();

        $this->assertDatabaseCount('repositories', 0);
    }

    public function test_credential_is_encrypted_at_rest_and_decrypted_by_cast(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create([
            'user_id' => $user->uuid,
            'credential' => ['api_key' => 'kfr_secret_plain_key'],
        ]);

        $raw = DB::table('repositories')->where('id', $repo->id)->value('credential');

        $this->assertNotSame('kfr_secret_plain_key', $raw);
        $this->assertStringNotContainsString('kfr_secret_plain_key', (string) $raw);
        $this->assertSame('kfr_secret_plain_key', $repo->fresh()->credential['api_key']);
    }

    public function test_deleting_repository_with_questions_is_restricted(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create(['user_id' => $user->uuid]);

        Question::create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
            'question_text' => 'Pregunta vinculada al repositorio',
        ]);

        $this->expectException(QueryException::class);
        $repo->delete();
    }

    public function test_repository_relationships(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create(['user_id' => $user->uuid]);
        $question = Question::create([
            'user_id' => $user->uuid,
            'repository_id' => $repo->id,
            'question_text' => 'Pregunta con repositorio',
        ]);

        $this->assertTrue($repo->user->is($user));
        $this->assertTrue($user->repositories()->first()->is($repo));
        $this->assertTrue($question->repository->is($repo));
        $this->assertTrue($repo->questions()->first()->is($question));
    }

    public function test_repository_id_column_exists_with_restrict_foreign_key(): void
    {
        $this->assertTrue(Schema::hasColumn('questions', 'repository_id'));

        $fk = collect(DB::select('SHOW CREATE TABLE questions')[0]->{'Create Table'})->first(
            fn ($sql) => str_contains($sql, 'CONSTRAINT') && str_contains($sql, 'repositories') && str_contains($sql, 'RESTRICT')
        );

        $this->assertNotNull($fk);
    }
}
