<?php

namespace Tests\Feature;

use App\Models\AgentToken;
use App\Models\Question;
use App\Models\User;
use App\Services\Mcp\McpServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private McpServer $server;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'agente@kuestion.app']);

        $token = AgentToken::create([
            'user_id' => $this->user->uuid,
            'name' => 'claude',
            'token_hash' => Hash::make('kqt_'.str_repeat('a', 32)),
            'scopes' => ['read'],
        ]);

        $this->server = new McpServer($token);
    }

    public function test_initialize_returns_handshake(): void
    {
        $response = $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => [], 'clientInfo' => ['name' => 'test']],
        ]);

        $this->assertSame(1, $response['id']);
        $this->assertSame('2024-11-05', $response['result']['protocolVersion']);
        $this->assertSame('kuestion', $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
    }

    public function test_ping_returns_empty_object(): void
    {
        $response = $this->server->handleMessage(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);

        $this->assertSame(2, $response['id']);
        $this->assertSame('{}', json_encode($response['result']));
    }

    public function test_notifications_do_not_get_a_response(): void
    {
        $response = $this->server->handleMessage(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->assertNull($response);
    }

    public function test_tools_list_returns_three_tools_with_schema(): void
    {
        $response = $this->server->handleMessage(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);

        $names = array_column($response['result']['tools'], 'name');

        $this->assertSame(['list_questions', 'get_question_details', 'list_unreviewed_changes'], $names);

        foreach ($response['result']['tools'] as $tool) {
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
    }

    public function test_list_questions_is_scoped_by_token_user(): void
    {
        $other = User::factory()->create();
        $mine = Question::factory()->count(2)->create(['user_id' => $this->user->uuid]);
        Question::factory()->create(['user_id' => $other->uuid]);

        $response = $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => ['name' => 'list_questions', 'arguments' => []],
        ]);

        $data = json_decode($response['result']['content'][0]['text'], true);

        $this->assertFalse($response['result']['isError']);
        $this->assertSame(2, $data['total']);
        $this->assertSame(
            [$mine[0]->id, $mine[1]->id],
            array_column($data['questions'], 'id'),
        );
    }

    public function test_list_questions_applies_filters(): void
    {
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'tags' => ['laravel'], 'question_text' => '¿Qué es un framework PHP?']);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'archived', 'tags' => ['laravel']]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'status' => 'active', 'tags' => ['rag']]);

        $call = fn (array $args) => $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'list_questions', 'arguments' => $args],
        ]);

        $byStatus = json_decode($call(['status' => 'active'])['result']['content'][0]['text'], true);
        $this->assertSame(2, $byStatus['total']);

        $byTag = json_decode($call(['tag' => 'laravel'])['result']['content'][0]['text'], true);
        $this->assertSame(2, $byTag['total']);

        $bySearch = json_decode($call(['search' => 'framework'])['result']['content'][0]['text'], true);
        $this->assertSame(1, $bySearch['total']);
    }

    public function test_get_question_details_returns_current_version(): void
    {
        $question = Question::factory()->create(['user_id' => $this->user->uuid, 'question_text' => '¿Qué es RAG?']);
        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => 'RAG es recuperación aumentada por generación.',
            'confidence' => 90.5,
            'sources' => ['wikipedia'],
            'response_hash' => hash('sha256', 'RAG es recuperación aumentada por generación.'),
            'is_current' => true,
        ]);

        $response = $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'get_question_details', 'arguments' => ['question_id' => $question->id]],
        ]);

        $data = json_decode($response['result']['content'][0]['text'], true);

        $this->assertSame($question->id, $data['id']);
        $this->assertSame(1, $data['current_version']['version_number']);
        $this->assertSame('RAG es recuperación aumentada por generación.', $data['current_version']['answer_text']);
        $this->assertSame(90.5, $data['current_version']['confidence']);
    }

    public function test_get_question_details_rejects_other_users_question(): void
    {
        $other = User::factory()->create();
        $question = Question::factory()->create(['user_id' => $other->uuid]);

        $response = $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => 'get_question_details', 'arguments' => ['question_id' => $question->id]],
        ]);

        $data = json_decode($response['result']['content'][0]['text'], true);

        $this->assertTrue($response['result']['isError']);
        $this->assertSame('Pregunta no encontrada para este usuario', $data['error']);
    }

    public function test_list_unreviewed_changes_returns_only_pending(): void
    {
        $pending = Question::factory()->create(['user_id' => $this->user->uuid, 'has_unreviewed_changes' => true, 'last_change_detected_at' => now()]);
        $pending->versions()->create([
            'version_number' => 2,
            'answer_text' => 'Nueva respuesta',
            'confidence' => 88,
            'sources' => [],
            'response_hash' => hash('sha256', 'Nueva respuesta'),
            'is_current' => true,
        ]);
        Question::factory()->create(['user_id' => $this->user->uuid, 'has_unreviewed_changes' => false]);

        $response = $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => ['name' => 'list_unreviewed_changes', 'arguments' => []],
        ]);

        $data = json_decode($response['result']['content'][0]['text'], true);

        $this->assertSame(1, $data['total']);
        $this->assertSame($pending->id, $data['changes'][0]['question_id']);
        $this->assertSame(2, $data['changes'][0]['current_version']);
    }

    public function test_unknown_method_and_tool_return_rpc_errors(): void
    {
        $methodError = $this->server->handleMessage(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'bogus']);
        $this->assertSame(-32601, $methodError['error']['code']);

        $toolError = $this->server->handleMessage([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => 'nope', 'arguments' => []],
        ]);
        $this->assertSame(-32602, $toolError['error']['code']);
    }
}
