<?php

namespace App\Livewire;

use App\Contracts\RagProviderInterface;
use App\Exceptions\KuaforiaException;
use App\Models\Question;
use App\Services\DiffGenerator;
use App\Services\QuestionChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::app')]
class QuestionDetail extends Component
{
    public Question $question;

    public bool $confirmDelete = false;

    public bool $showVersions = false;

    public bool $showReview = false;

    public ?int $diffFrom = null;

    public ?int $diffTo = null;

    public string $statusMessage = '';

    public string $followUpQuestion = '';

    public ?string $followUpAnswer = null;

    public ?string $followUpError = null;

    public bool $followUpLoading = false;

    public bool $checkNowLoading = false;

    public ?string $checkResult = null;

    public ?string $checkResultType = null;

    private MarkdownConverter $markdown;

    public function boot(): void
    {
        $config = ['html_input' => 'escape', 'allow_unsafe_links' => false];
        $env = new Environment($config);
        $env->addExtension(new CommonMarkCoreExtension);
        $this->markdown = new MarkdownConverter($env);
    }

    public function mount(Question $question): void
    {
        // F1 — eager load del repositorio: el badge de estado del detalle no hace N+1.
        $this->question = Question::where('user_id', current_user_id())
            ->where('id', $question->id)
            ->firstOrFail()
            ->load('currentVersion', 'repository');
        $this->showReview = $this->question->has_unreviewed_changes;
        if ($this->showReview) {
            $versions = $this->question->versions()->orderBy('version_number', 'desc')->limit(2)->get();
            if ($versions->count() === 2) {
                $this->diffFrom = $versions[1]->version_number;
                $this->diffTo = $versions[0]->version_number;
            }
        }
    }

    /**
     * "Comprobar ahora" — re-consulta inmediata contra Kuaforia, sin esperar la
     * frecuencia de revisión de la pregunta. Reutiliza QuestionChecker (la misma
     * lógica del job horario): consulta → detecta → versiona → notifica.
     * Rate-limited (5/min por usuario): cada clic es una consulta LLM real.
     */
    public function checkNow(): void
    {
        $executed = RateLimiter::attempt('check-now:'.current_user_id(), 5, fn () => true, 60);

        if (! $executed) {
            $this->checkResult = 'Demasiadas comprobaciones. Esperá un momento.';
            $this->checkResultType = 'error';

            return;
        }

        $this->checkNowLoading = true;
        $this->checkResult = null;
        $this->checkResultType = null;

        try {
            $result = app(QuestionChecker::class)->check($this->question);
            $this->checkResult = $result['message'];
            $this->checkResultType = match ($result['status']) {
                'changed' => 'success',
                'error' => 'error',
                default => 'info',
            };
        } catch (\Throwable $e) {
            Log::warning('QuestionDetail: checkNow falló', [
                'question_id' => $this->question->id,
                'error' => $e->getMessage(),
            ]);
            $this->checkResult = 'No se pudo completar la comprobación. Intenta de nuevo.';
            $this->checkResultType = 'error';
        } finally {
            $this->checkNowLoading = false;

            // Refrescar el estado: nueva versión / review / diff (misma lógica que mount).
            $this->question->refresh()->load('currentVersion', 'repository');
            $this->showReview = $this->question->has_unreviewed_changes;
            $this->diffFrom = null;
            $this->diffTo = null;

            if ($this->showReview) {
                $versions = $this->question->versions()->orderBy('version_number', 'desc')->limit(2)->get();
                if ($versions->count() === 2) {
                    $this->diffFrom = $versions[1]->version_number;
                    $this->diffTo = $versions[0]->version_number;
                }
            }
        }
    }

    public function toggleStar(): void
    {
        $this->question->update(['is_starred' => ! $this->question->is_starred]);
        $this->question->refresh();
    }

    public function archive(): void
    {
        $this->question->delete();
        $this->redirect(route('questions.index'), navigate: true);
    }

    public function toggleVersions(): void
    {
        $this->showVersions = ! $this->showVersions;
    }

    public function showDiff(int $from, int $to): void
    {
        $this->diffFrom = $from;
        $this->diffTo = $to;
    }

    public function clearDiff(): void
    {
        $this->diffFrom = null;
        $this->diffTo = null;
        $this->showReview = false;
    }

    public function acceptChange(): void
    {
        if (! $this->question->has_unreviewed_changes) {
            return;
        }

        DB::transaction(function () {
            // Lock de fila: serializa la actualización de has_unreviewed_changes con el job.
            $locked = Question::whereKey($this->question->id)->lockForUpdate()->first();

            if (! $locked->has_unreviewed_changes) {
                return;
            }

            // ponytail: new version is already is_current=true from job, just mark accepted
            $current = $locked->versions()->where('is_current', true)->first();
            if ($current) {
                $current->update(['status' => 'accepted']);
            }

            $locked->update(['has_unreviewed_changes' => false]);
            $this->markNotificationRead();
        });

        $this->question->refresh()->load('currentVersion');
        $this->showReview = false;
        $this->diffFrom = null;
        $this->diffTo = null;
        $this->statusMessage = 'Cambio aceptado. La nueva versión ahora es la respuesta actual.';
    }

    public function dismissChange(): void
    {
        if (! $this->question->has_unreviewed_changes) {
            return;
        }

        DB::transaction(function () {
            // Lock de fila: serializa la actualización de has_unreviewed_changes con el job.
            $locked = Question::whereKey($this->question->id)->lockForUpdate()->first();

            if (! $locked->has_unreviewed_changes) {
                return;
            }

            $current = $locked->versions()->where('is_current', true)->first();
            $previous = $locked->versions()
                ->where('is_current', false)
                ->latest('version_number')
                ->first();

            if ($current && $previous) {
                $current->update(['is_current' => false, 'status' => 'dismissed']);
                $previous->update(['is_current' => true]);
                $locked->update([
                    'has_unreviewed_changes' => false,
                    'answer_text' => $previous->answer_text,
                ]);
            } else {
                $locked->update(['has_unreviewed_changes' => false]);
            }

            $this->markNotificationRead();
        });

        $this->question->refresh()->load('currentVersion');
        $this->showReview = false;
        $this->diffFrom = null;
        $this->diffTo = null;
        $this->statusMessage = 'Cambio descartado. La respuesta actual se mantiene.';
    }

    private function markNotificationRead(): void
    {
        // Bloque 1: notificaciones nativas (notifiable_id en vez de user_id).
        auth()->user()->notifications()
            ->whereNull('read_at')
            ->where('data->question_id', $this->question->id)
            ->update(['read_at' => now()]);
    }

    public function askFollowUp(): void
    {
        $executed = RateLimiter::attempt(
            'follow-up:'.($this->question->user_id ?? 'guest'),
            5,
            fn () => true,
            60,
        );
        if (! $executed) {
            $this->followUpError = 'Demasiadas consultas. Espera un momento.';

            return;
        }

        $this->validate(['followUpQuestion' => 'required|string|max:2000']);
        $this->followUpLoading = true;
        $this->followUpError = null;

        // D3 — el tenant sale del repositorio de la pregunta (no del usuario). Sin repo
        // activo el follow-up se bloquea con un mensaje de reparación (§6.5/6.12).
        $repo = $this->question->repository;

        if (! $repo || $repo->status !== 'active' || ! $repo->resolved_tenant_slug) {
            $this->followUpError = 'La conexión con tu fuente de conocimiento está inactiva. Actualizala en Configuración.';

            return;
        }

        try {
            $kuaforia = app(RagProviderInterface::class);
            $response = $kuaforia->consult(
                $this->followUpQuestion,
                $this->question->conversation_id,
                $repo->resolved_tenant_slug,
            );
            $this->followUpAnswer = $response->answerText;
            $this->followUpQuestion = '';
        } catch (KuaforiaException $e) {
            $this->followUpError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->followUpError = 'Error de conexión. Intenta de nuevo.';
        } finally {
            $this->followUpLoading = false;
        }
    }

    public function title(): string
    {
        return $this->question->question_text;
    }

    public function render()
    {
        $versions = $this->showVersions
            ? $this->question->versions()->orderBy('version_number', 'desc')->get()
            : collect();

        $diffResult = null;
        $diffLatest = null;
        if ($this->diffFrom && $this->diffTo) {
            $from = $this->question->versions()->where('version_number', $this->diffFrom)->first();
            $to = $this->question->versions()->where('version_number', $this->diffTo)->first();
            if ($from && $to) {
                $diffResult = (new DiffGenerator)->diff($from->answer_text, $to->answer_text);
                $diffLatest = ['from' => $from, 'to' => $to];
            }
        }

        return view('livewire.question-detail', [
            'markdown' => $this->markdown,
            'currentVersion' => $this->question->currentVersion,
            'versions' => $versions,
            'diffResult' => $diffResult,
            'diffLatest' => $diffLatest,
            'versionCount' => $this->question->versions()->count(),
        ]);
    }
}
