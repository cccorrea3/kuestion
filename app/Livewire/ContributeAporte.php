<?php

namespace App\Livewire;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Componente para el flujo de "Aportar conocimiento" (Ola 1, Punto 3 — Fase 1).
 *
 * Estados de UI:
 * - idle: formulario listo para recibir texto
 * - analyzing: spinner + "Analizando tu aporte..."
 * - saved: confirmación "Gracias, quedó guardado y pendiente de revisión"
 * - error: mensaje de error + botón reintentar si hay draft
 */
#[Layout('layouts::app')]
class ContributeAporte extends Component
{
    public string $texto = '';

    public ?string $preguntaPrevia = null;

    public string $status = 'idle';

    public ?string $error = null;

    public string $resumen = '';

    public ?string $repositoryId = null;

    public function getRepositoriesProperty()
    {
        return auth()->user()->repositories()
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->get();
    }

    public function mount(): void
    {
        $this->repositoryId = $this->repositories->first()?->id;

        // Si viene de una búsqueda sin resultados, capturar la pregunta previa.
        $prev = request()->query('prev');
        if ($prev && is_string($prev)) {
            $this->preguntaPrevia = mb_strimwidth($prev, 0, 2000);
        }
    }

    protected function rules(): array
    {
        return [
            'texto' => 'required|string|min:10|max:2000',
        ];
    }

    /**
     * Enviar el aporte al servicio de clasificación de QuBeKa.
     *
     * Fase 2 (QbkContributionService) reemplazará esta llamada directa.
     * Por ahora, se construye con mock para validar la interfaz.
     */
    public function submit(): void
    {
        $this->validate();

        $this->status = 'analyzing';
        $this->error = null;

        $repo = $this->repositories->firstWhere('id', $this->repositoryId)
            ?? $this->repositories->first();

        if (! $repo) {
            $this->error = 'Conectá una fuente de conocimiento en Configuración para aportar.';
            $this->status = 'error';

            return;
        }

        try {
            $apiToken = $repo->credential['api_token'] ?? null;

            if (! is_string($apiToken) || $apiToken === '') {
                throw new \RuntimeException('Token de agente no disponible.');
            }

            $url = rtrim(config('services.qubeka.api_url'), '/').'/contribute';

            $payload = [
                'texto' => $this->texto,
                'origen' => 'kuestion',
            ];

            if ($this->preguntaPrevia) {
                $payload['pregunta_previa'] = $this->preguntaPrevia;
            }

            $response = Http::timeout(30)
                ->withToken($apiToken)
                ->post($url, $payload);

            if ($response->failed()) {
                $status = $response->status();

                if ($status === 401) {
                    throw new \RuntimeException('El token de QuBeKa es inválido o fue revocado.');
                }

                if ($status === 403) {
                    throw new \RuntimeException('No tenés permiso de escritura en este workspace de QuBeKa.');
                }

                throw new \RuntimeException('QuBeKa respondió con error: '.$status);
            }

            $body = $response->json();

            // QuBeKa envuelve respuestas en {success, data, ...}.
            $data = $body['data'] ?? $body;

            $this->resumen = $data['resumen'] ?? 'Tu aporte quedó registrado.';
            $this->status = 'saved';
            $this->texto = '';
        } catch (ConnectionException $e) {
            Log::warning('QbK contribute timeout', ['error' => $e->getMessage()]);
            $this->error = 'La conexión con QuBeKa tardó demasiado. Intentá de nuevo.';
            $this->status = 'error';
        } catch (\RuntimeException $e) {
            Log::warning('QbK contribute error', ['error' => $e->getMessage()]);
            $this->error = $e->getMessage();
            $this->status = 'error';
        } catch (\Throwable $e) {
            Log::error('QbK contribute unexpected', ['error' => $e->getMessage()]);
            $this->error = 'Error inesperado. Intentá de nuevo.';
            $this->status = 'error';
        }
    }

    public function resetForm(): void
    {
        $this->status = 'idle';
        $this->error = null;
        $this->resumen = '';
    }

    public function render()
    {
        return view('livewire.contribute-aporte');
    }
}
