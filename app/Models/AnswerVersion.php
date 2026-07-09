<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerVersion extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'question_id',
        'version_number',
        'answer_text',
        'confidence',
        'sources',
        'response_hash',
        'is_current',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'sources' => 'array',
            'is_current' => 'boolean',
            'version_number' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
