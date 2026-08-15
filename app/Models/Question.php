<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'repository_id',
        'question_text',
        'answer_text',
        'status',
        'is_starred',
        'tags',
        'review_frequency',
        'last_consulted_at',
        'last_change_detected_at',
        'has_unreviewed_changes',
        'conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'is_starred' => 'boolean',
            'tags' => 'array',
            'has_unreviewed_changes' => 'boolean',
            'last_consulted_at' => 'datetime',
            'last_change_detected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AnswerVersion::class);
    }

    public function currentVersion()
    {
        return $this->hasOne(AnswerVersion::class)->where('is_current', true);
    }

    public function outboundRelations(): HasMany
    {
        return $this->hasMany(QuestionRelation::class, 'source_question_id');
    }

    public function inboundRelations(): HasMany
    {
        return $this->hasMany(QuestionRelation::class, 'target_question_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Question $question) {
            if ($question->tags && is_array($question->tags)) {
                $question->tags = array_map('strtolower', array_map('trim', $question->tags));
            }
        });
    }

    /**
     * Búsqueda de texto: usa el índice FULLTEXT cuando el término es indexable
     * (tokens de 3+ caracteres y no ignorado por el tokenizador de MySQL);
     * de lo contrario cae a LIKE para no perder coincidencias.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        // FULLTEXT indexa tokens de 3+ caracteres; abajo de eso, LIKE.
        if (mb_strlen($search) < 3) {
            return $this->likeSearch($query, $search);
        }

        // Modo natural: si MySQL descarta el término completo (stopwords/tokens cortos),
        // la búsqueda devolvería vacío aunque existan coincidencias → fallback a LIKE.
        $matches = $query->clone()
            ->whereFullText('question_text', $search)
            ->exists();

        return $matches
            ? $query->whereFullText('question_text', $search)
            : $this->likeSearch($query, $search);
    }

    /**
     * LIKE de subcadena con wildcards escapados. No se usa whereLike() porque en esta
     * versión de Laravel compila un match exacto para MySQL (sin %), no una subcadena.
     */
    private function likeSearch(Builder $query, string $term): Builder
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $term);

        return $query->where('question_text', 'like', '%'.$escaped.'%');
    }
}
