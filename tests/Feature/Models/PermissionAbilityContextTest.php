<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `ability` and `context` were `virtualAs("SUBSTRING_INDEX(name, ' ', 1)")` and
 * `(name, ' ', -1)` on the tenant connection only. The four cases below were
 * measured against MySQL 8.4 before the swap and the accessors return the same
 * strings for all four, empty name included.
 */
class PermissionAbilityContextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function names(): array
    {
        return [
            'two words' => ['deleteAny payment_plans', 'deleteAny', 'payment_plans'],
            'no space' => ['single', 'single', 'single'],
            'three words' => ['a b c', 'a', 'c'],
            'empty' => ['', '', ''],
        ];
    }

    #[DataProvider('names')]
    public function test_it_splits_a_name_the_way_the_generated_columns_did(string $name, string $ability, string $context): void
    {
        $permission = new Permission(['name' => $name]);

        $this->assertSame($ability, $permission->ability);
        $this->assertSame($context, $permission->context);
    }

    public function test_it_reads_a_persisted_tenant_permission(): void
    {
        $tenant = TestTenant::provisioned(['id' => 'permission-accessor-'.uniqid()]);

        $tenant->run(function (): void {
            $permission = Permission::query()->where('name', 'forceDelete invitations')->sole();

            $this->assertSame('forceDelete', $permission->ability);
            $this->assertSame('invitations', $permission->context);
        });
    }

    /**
     * The central permission tables never carried the generated columns, so
     * both attributes were null here until they became accessors.
     */
    public function test_it_reads_a_persisted_central_permission(): void
    {
        Permission::create(['name' => 'viewAny tenants', 'guard_name' => 'web']);

        $permission = Permission::query()->where('name', 'viewAny tenants')->sole();

        $this->assertSame('viewAny', $permission->ability);
        $this->assertSame('tenants', $permission->context);
    }
}
