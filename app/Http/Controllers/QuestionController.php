<?php

namespace App\Http\Controllers;

use App\Exceptions\KuaforiaException;
use App\Http\Requests\StoreQuestionRequest;
use App\Http\Requests\UpdateQuestionRequest;
use App\Models\Question;
use App\Models\QuestionRelation;
use App\Services\DiffGenerator;
use App\Services\KuaforiaService;
use App\Services\RelationSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionController extends Controller
{
    public function __construct(
        private readonly KuaforiaService $kuaforia,
    ) {}

    public function storeRelation(Request $request, string $id): JsonResponse
    {
        $source = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        $request->validate([
            'target_question_id' => 'required|string|size:36',
            'label' => 'required|string|max:100',
            'relation_type' => 'nullable|in:manual,tag_suggested',
        ]);

        $target = Question::where('user_id', config('app.user_id'))
            ->where('id', $request->target_question_id)
            ->firstOrFail();

        if ($source->id === $target->id) {
            return response()->json(['error' => 'No puedes relacionar una pregunta consigo misma'], 422);
        }

        $relation = $source->outboundRelations()->create([
            'target_question_id' => $target->id,
            'label' => $request->label,
            'relation_type' => $request->relation_type ?? 'manual',
        ]);

        return response()->json($relation, 201);
    }

    public function destroyRelation(string $id, string $rid): JsonResponse
    {
        $source = Question::where('user_id', config('app.user_id'))->findOrFail($id);
        $relation = $source->outboundRelations()->where('id', $rid)->firstOrFail();
        $relation->delete();

        return response()->noContent();
    }

    public function backlinks(string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        $backlinks = QuestionRelation::where('target_question_id', $question->id)
            ->with('source:id,question_text,tags')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'source_question_id' => $r->source_question_id,
                'question_text' => $r->source->question_text,
                'tags' => $r->source->tags,
                'label' => $r->label,
                'relation_type' => $r->relation_type,
                'created_at' => $r->created_at,
            ]);

        return response()->json($backlinks);
    }

    public function tags(): JsonResponse
    {
        $questions = Question::where('user_id', config('app.user_id'))
            ->where(function ($q) {
                $q->where('status', 'active')->orWhereNull('status');
            })
            ->get(['tags']);

        $tagCounts = [];
        foreach ($questions as $question) {
            if ($question->tags && is_array($question->tags)) {
                foreach ($question->tags as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($tagCounts);

        $result = [];
        foreach ($tagCounts as $tag => $count) {
            $result[] = ['tag' => $tag, 'count' => $count];
        }

        return response()->json($result);
    }

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

    public function suggestRelations(Request $request, RelationSuggester $suggester): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:2000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50|regex:/^[a-z0-9áéíóúüñ\-]+$/u',
        ]);

        $suggestions = $suggester->suggest(
            $request->text,
            $request->tags ?? [],
            config('app.user_id'),
        );

        return response()->json($suggestions);
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

        if ($request->filled('confirmed_relations')) {
            $relations = is_array($request->confirmed_relations)
                ? $request->confirmed_relations
                : json_decode($request->confirmed_relations, true);

            $validIds = Question::where('user_id', config('app.user_id'))
                ->whereIn('id', $relations)
                ->pluck('id')
                ->toArray();

            foreach ($validIds as $targetId) {
                $question->outboundRelations()->create([
                    'source_question_id' => $question->id,
                    'target_question_id' => $targetId,
                    'label' => $request->relation_label ?? 'relacionado con',
                    'relation_type' => 'tag_suggested',
                ]);
            }
        }

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

    public function feedback(Request $request, string $id): JsonResponse
    {
        $question = Question::where('user_id', config('app.user_id'))->findOrFail($id);

        $request->validate([
            'type' => 'required|in:helpful,not_helpful',
        ]);

        $current = $question->versions()->where('is_current', true)->first();
        if (!$current) {
            return response()->json(['error' => 'No hay versión actual para esta pregunta'], 422);
        }

        $current->update(['feedback' => $request->type]);

        return response()->json(['feedback' => $request->type]);
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
