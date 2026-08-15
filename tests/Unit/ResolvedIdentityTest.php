<?php

namespace Tests\Unit;

use App\Services\ResolvedIdentity;
use Tests\TestCase;

class ResolvedIdentityTest extends TestCase
{
    public function test_dto_holds_resolved_values(): void
    {
        $identity = new ResolvedIdentity(
            tenantSlug: 'ispend',
            tenantName: 'Ispend',
            workspaceId: 'ws-1',
            raw: ['tenant' => ['slug' => 'ispend']],
        );

        $this->assertSame('ispend', $identity->tenantSlug);
        $this->assertSame('Ispend', $identity->tenantName);
        $this->assertSame('ws-1', $identity->workspaceId);
        $this->assertSame(['tenant' => ['slug' => 'ispend']], $identity->raw);
    }

    public function test_dto_defaults_to_null_name_and_workspace(): void
    {
        $identity = new ResolvedIdentity(tenantSlug: 'ispend');

        $this->assertSame('ispend', $identity->tenantSlug);
        $this->assertNull($identity->tenantName);
        $this->assertNull($identity->workspaceId);
        $this->assertSame([], $identity->raw);
    }
}
