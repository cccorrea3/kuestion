<?php

namespace App\Console\Commands;

use App\Models\AgentToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAgentToken extends Command
{
    protected $signature = 'agent-token:create {user : UUID o email del usuario} {name : Nombre del token}';

    protected $description = 'Crea un token de agente para el MCP Server de Kuestion (se imprime una sola vez)';

    public function handle(): int
    {
        $user = User::where('uuid', $this->argument('user'))
            ->orWhere('email', $this->argument('user'))
            ->first();

        if (! $user) {
            $this->error('Usuario no encontrado (busca por uuid o email).');

            return self::FAILURE;
        }

        $plainToken = 'kqt_'.Str::random(32);

        AgentToken::create([
            'user_id' => $user->uuid,
            'name' => $this->argument('name'),
            'token_hash' => Hash::make($plainToken),
            'scopes' => ['read'],
        ]);

        $this->info('Token creado para '.$user->email.' ('.AgentToken::where('user_id', $user->uuid)->count().' tokens).');
        $this->line('');
        $this->warn('Guardalo ahora: solo se muestra una vez y no es recuperable. Si se pierde, crea otro.');
        $this->line('');
        $this->line($plainToken);

        return self::SUCCESS;
    }
}
