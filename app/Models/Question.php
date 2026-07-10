<?php

namespace App\Models;

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
}
