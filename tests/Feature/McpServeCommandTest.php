<?php

namespace Tests\Feature;

use App\Models\AgentToken;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class McpServeCommandTest extends TestCase
{
    // DatabaseMigrations (no RefreshDatabase): el proceso hijo lanzado con proc_open
    // es otra conexión MySQL y no vería los datos dentro de la transacción de
    // RefreshDatabase. Con migraciones commiteadas el hijo lee los datos reales.
    use DatabaseMigrations;

    public function test_command_serves_initialize_and_tools_call_over_stdio(): void
    {
        $user = User::factory()->create();
        $plainToken = 'kqt_'.str_repeat('b', 32);

        AgentToken::create([
            'user_id' => $user->uuid,
            'name' => 'claude',
            'token_hash' => Hash::make($plainToken),
            'scopes' => ['read'],
        ]);

        Question::factory()->create(['user_id' => $user->uuid, 'question_text' => '¿Qué es Kuestion?']);

        $process = proc_open(
            ['php', 'artisan', 'mcp:serve', '--token='.$plainToken],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );

        $this->assertIsResource($process);

        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_questions', 'arguments' => []]],
        ];

        foreach ($messages as $message) {
            fwrite($pipes[0], json_encode($message).PHP_EOL);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $responses = collect(explode(PHP_EOL, trim($stdout)))
            ->map(fn ($line) => json_decode($line, true))
            ->filter()
            ->keyBy('id');

        // initialize: handshake válido.
        $this->assertSame('kuestion', $responses[1]['result']['serverInfo']['name']);

        // tools/list: 3 tools.
        $this->assertCount(3, $responses[2]['result']['tools']);

        // tools/call list_questions: JSON scoped por el usuario del token.
        $data = json_decode($responses[3]['result']['content'][0]['text'], true);
        $this->assertSame(1, $data['total']);
        $this->assertSame('¿Qué es Kuestion?', $data['questions'][0]['question_text']);
    }

    public function test_command_rejects_invalid_token(): void
    {
        $user = User::factory()->create();

        AgentToken::create([
            'user_id' => $user->uuid,
            'name' => 'claude',
            'token_hash' => Hash::make('kqt_'.str_repeat('c', 32)),
            'scopes' => ['read'],
        ]);

        $process = proc_open(
            ['php', 'artisan', 'mcp:serve', '--token=kqt_wrongtoken'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );

        $this->assertIsResource($process);

        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]).PHP_EOL);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $response = json_decode(trim($stdout), true);

        $this->assertSame(-32001, $response['error']['code']);
        $this->assertSame('Token de agente inválido o expirado', $response['error']['message']);
    }
}
