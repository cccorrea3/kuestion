<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'connector_type',
        'name',
        'credential',
        'resolved_tenant_slug',
        'resolved_tenant_name',
        'resolved_workspace_id',
        'status',
        'is_default',
        'last_validated_at',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted:array',
            'is_default' => 'boolean',
            'last_validated_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Nombre autogenerado de un repositorio (P7): "{display_name} - {tenant_name}",
     * truncado a 100 caracteres si hace falta.
     */
    public static function defaultName(string $displayName, ?string $tenantName): string
    {
        $tenantName ??= '';

        return mb_strimwidth("{$displayName} - {$tenantName}", 0, 100);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
