<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'scopes',
        'last_used_at',
        'expires_at',
    ];

    // Default de scopes en Eloquent (MySQL < 8.0.13 no permite default en JSON).
    protected $attributes = [
        'scopes' => '["read"]',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }
}
