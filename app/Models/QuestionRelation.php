<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionRelation extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'source_question_id',
        'target_question_id',
        'label',
        'relation_type',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'source_question_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'target_question_id');
    }
}
