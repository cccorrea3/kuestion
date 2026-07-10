<?php

namespace App\Livewire;

use App\Exceptions\KuaforiaException;
use App\Models\Question;
use App\Services\KuaforiaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
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
        // ponytail: single-user — scope check would be abort_unless for multi-tenant
        $this->question = $question->load('currentVersion');
        $this->showReview = $question->has_unreviewed_changes;
        if ($this->showReview) {
            $versions = $question->versions()->orderBy('version_number', 'desc')->limit(2)->get();
            if ($versions->count() === 2) {
                $this->diffFrom = $versions[1]->version_number;
                $this->diffTo = $versions[0]->version_number;
            }
        }
    }

    public function toggleStar(): void
    {
        $this->question->update(['is_starred' => !$this->question->is_starred]);
        $this->question->refresh();
    }

    public function archive(): void
    {
        $this->question->delete();
        $this->redirect(route('questions.index'), navigate: true);
    }

    public function toggleVersions(): void
    {
        $this->showVersions = !$this->showVersions;
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
        if (!$this->question->has_unreviewed_changes) return;

        DB::transaction(function () {
            // ponytail: new version is already is_current=true from job, just mark accepted
            $current = $this->question->versions()->where('is_current', true)->first();
            if ($current) {
                $current->update(['status' => 'accepted']);
            }

            $this->question->update(['has_unreviewed_changes' => false]);
            $this->markNotificationRead();
        });

        $this->question->load('currentVersion');
        $this->showReview = false;
        $this->diffFrom = null;
        $this->diffTo = null;
        $this->statusMessage = 'Cambio aceptado. La nueva versión ahora es la respuesta actual.';
    }

    public function dismissChange(): void
    {
        if (!$this->question->has_unreviewed_changes) return;

        DB::transaction(function () {
            $current = $this->question->versions()->where('is_current', true)->first();
            $previous = $this->question->versions()
                ->where('is_current', false)
                ->latest('version_number')
                ->first();

            if ($current && $previous) {
                $current->update(['is_current' => false, 'status' => 'dismissed']);
                $previous->update(['is_current' => true]);
                $this->question->update([
                    'has_unreviewed_changes' => false,
                    'answer_text' => $previous->answer_text,
                ]);
            } else {
                $this->question->update(['has_unreviewed_changes' => false]);
            }

            $this->markNotificationRead();
        });

        $this->question->load('currentVersion');
        $this->showReview = false;
        $this->diffFrom = null;
        $this->diffTo = null;
        $this->statusMessage = 'Cambio descartado. La respuesta actual se mantiene.';
    }

    private function markNotificationRead(): void
    {
        DB::table('notifications')
            ->where('user_id', config('app.user_id'))
            ->whereNull('read_at')
            ->where('data->question_id', $this->question->id)
            ->update(['read_at' => now()]);
    }

    public function askFollowUp(): void
    {
        $executed = RateLimiter::attempt(
            'follow-up:' . ($this->question->user_id ?? 'guest'),
            5,
            fn() => true,
            60,
        );
        if (!$executed) {
            $this->followUpError = 'Demasiadas consultas. Espera un momento.';
            return;
        }

        $this->validate(['followUpQuestion' => 'required|string|max:2000']);
        $this->followUpLoading = true;
        $this->followUpError = null;

        try {
            $kuaforia = app(KuaforiaService::class);
            $response = $kuaforia->consult(
                $this->followUpQuestion,
                $this->question->conversation_id,
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
                $diffResult = (new \App\Services\DiffGenerator)->diff($from->answer_text, $to->answer_text);
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
