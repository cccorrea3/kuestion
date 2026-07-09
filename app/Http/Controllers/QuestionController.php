<?php

namespace App\Http\Controllers;

use App\Exceptions\KuaforiaException;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Models\Question;
use App\Services\DiffGenerator;
use App\Services\KuaforiaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    public function __construct(
        private readonly KuaforiaService $kuaforia,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Question::where('user_id', config('app.user_id'));

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('tag')) {
            $query->whereJsonContains('tags', $request->tag);
        }

        if ($request->has('search')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->search);
            $query->where('question_text', 'like', '%' . $search . '%');
        }

        if ($request->boolean('starred')) {
            $query->where('is_starred', true);
        }

        if ($request->boolean('has_changes')) {
            $query->where('has_unreviewed_changes', true);
        }

        $questions = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json($questions);
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        try {
            $response = $this->kuaforia->consult(
                $request->question_text,
                $request->conversation_id
            );
        } catch (KuaforiaException $e) {
            return response()->json(['error' => $e->getMessage()], $e->getCode());
        }

        $question = Question::create([
            'user_id' => config('app.user_id'),
            'question_text' => $request->question_text,
            'answer_text' => $response->answerText,
            'tags' => $request->tags ?? [],
            'review_frequency' => $request->review_frequency ?? 'weekly',
            'last_consulted_at' => now(),
        ]);

        $question->versions()->create([
            'version_number' => 1,
            'answer_text' => $response->answerText,
            'confidence' => $response->confidence,
            'sources' => $response->sources,
            'response_hash' => hash('sha256', $response->answerText),
            'is_current' => true,
        ]);

        $question->load('currentVersion');

        return response()->json($question, 201);
    }

    public function show(string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->with('currentVersion')->findOrFail($id);

        return response()->json($question);
    }

    public function update(UpdateQuestionRequest $request, string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        $data = $request->safe()->only(['tags', 'is_starred', 'status', 'review_frequency']);

        if (isset($data['tags'])) {
            $data['tags'] = array_values(array_unique($data['tags']));
        }

        $question->update($data);

        return response()->json($question);
    }

    public function destroy(string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);
        $question->update(['status' => 'archived']);
        $question->delete();

        return response()->noContent();
    }

    public function versions(string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        $versions = $question->versions()
            ->orderBy('version_number', 'desc')
            ->get(['id', 'version_number', 'confidence', 'response_hash', 'is_current', 'status', 'created_at', 'sources'])
            ->each->setAppends([]);

        return response()->json($versions);
    }

    public function acceptChange(string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        DB::transaction(function () use ($question) {
            if (!$question->has_unreviewed_changes) return;

            // ponytail: new version is already is_current=true from job, just mark accepted
            $current = $question->versions()->where('is_current', true)->first();
            if ($current) {
                $current->update(['status' => 'accepted']);
            }

            $question->update(['has_unreviewed_changes' => false]);
            $this->markNotificationRead($question->id);
        });

        $question->load('currentVersion');
        return response()->json($question);
    }

    public function dismissChange(string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        DB::transaction(function () use ($question) {
            if (!$question->has_unreviewed_changes) return;

            $current = $question->versions()->where('is_current', true)->first();
            $previous = $question->versions()
                ->where('is_current', false)
                ->latest('version_number')
                ->first();

            if ($current && $previous) {
                $current->update(['is_current' => false, 'status' => 'dismissed']);
                $previous->update(['is_current' => true]);
                $question->update([
                    'has_unreviewed_changes' => false,
                    'answer_text' => $previous->answer_text,
                ]);
            } else {
                $question->update(['has_unreviewed_changes' => false]);
            }

            $this->markNotificationRead($question->id);
        });

        $question->load('currentVersion');
        return response()->json($question);
    }

    private function markNotificationRead(string $questionId): void
    {
        DB::table('notifications')
            ->where('user_id', config('app.user_id'))
            ->whereNull('read_at')
            ->where('data->question_id', $questionId)
            ->update(['read_at' => now()]);
    }

    public function diff(Request $request, string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        $from = (int) ($request->from ?: 1);
        $to = (int) ($request->to ?: $question->versions()->max('version_number'));

        $fromVersion = $question->versions()->where('version_number', $from)->firstOrFail();
        $toVersion = $to
            ? $question->versions()->where('version_number', $to)->firstOrFail()
            : $fromVersion;

        $diff = (new DiffGenerator)->diff($fromVersion->answer_text, $toVersion->answer_text);

        return response()->json([
            'from' => ['version' => $fromVersion->version_number, 'created_at' => $fromVersion->created_at],
            'to' => ['version' => $toVersion->version_number, 'created_at' => $toVersion->created_at],
            'diff' => $diff,
        ]);
    }
}
