<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\Immutable;
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

    /**
     * Ola 2, Punto 2 — Fase B (B.2): estado de vigencia de una pregunta QBK.
     *
     * Calculado desde `sources` de la versión actual (decisión D2 del plan: sin
     * tabla espejo). La vigencia de la pregunta = la fecha de confirmación más
     * reciente entre sus fuentes (criterio D1: "por nodo, mostrado como parte
     * de la pregunta").
     *
     * Regla de degradación: campo ausente/null en el contrato → 'sin_dato'
     * (fallback copy honesto de Ola 1 P5/6). No QBK → 'no_aplica'.
     *
     * @return array{estado: string, ultima_confirmacion: Immutable|null, dias: int|null}
     *                                                                                    estado: 'no_aplica' | 'sin_dato' | 'confirmada' | 'vencida'
     */
    public function vigenciaQbk(): array
    {
        if ($this->repository?->connector_type !== 'qbk') {
            return ['estado' => 'no_aplica', 'ultima_confirmacion' => null, 'dias' => null];
        }

        $fechas = [];

        foreach ($this->currentVersion?->sources ?? [] as $source) {
            if (is_array($source) && ! empty($source['fecha_ultima_confirmacion'])) {
                $fechas[] = $source['fecha_ultima_confirmacion'];
            }
        }

        if ($fechas === []) {
            return ['estado' => 'sin_dato', 'ultima_confirmacion' => null, 'dias' => null];
        }

        $ultima = collect($fechas)
            ->map(fn ($f) => Carbon::parse($f))
            ->sortDesc()
            ->first();

        $dias = (int) floor($ultima->diffInDays(now()));
        $umbral = (int) config('kuestion.reconfirmacion.umbral_dias', 90);

        if ($dias >= $umbral) {
            return ['estado' => 'vencida', 'ultima_confirmacion' => $ultima, 'dias' => $dias];
        }

        return ['estado' => 'confirmada', 'ultima_confirmacion' => $ultima, 'dias' => $dias];
    }

    /**
     * Ola 2, Punto 2 — actualización optimista local tras reconfirmar en QuBeKa
     * (C.1 "actualización optimista" / D.2 "el ítem sale de la lista"). Marca las
     * fuentes de la versión actual como confirmadas ahora. QuBeKa sigue siendo la
     * fuente de verdad: la próxima re-consulta (/query) refresca sources completos.
     */
    public function aplicarReconfirmacionLocal(): void
    {
        $version = $this->currentVersion;

        if (! $version) {
            return;
        }

        $sources = collect($version->sources ?? [])
            ->map(fn ($s) => is_array($s) && ! empty($s['node_id'])
                ? array_merge($s, ['fecha_ultima_confirmacion' => now()->toIso8601String()])
                : $s)
            ->all();

        $version->update(['sources' => $sources]);
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
