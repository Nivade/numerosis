<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Modules;

use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvade\Notes\Models\Note;
use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;

class NotesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrating_creates_the_notes_table_on_the_tenant_only_and_seeds_permissions(): void
    {
        $tenant = Tenant::factory()->create();

        MigrateModules::dispatchSync($tenant, 'notes');

        $this->assertFalse(Schema::hasTable('notes'));

        $tenant->run(function () {
            $this->assertTrue(Schema::hasTable('notes'));
            $this->assertTrue(
                Permission::where('name', 'view notes')->where('guard_name', 'tenant')->exists()
            );
        });
    }

    public function test_pinned_notes_sort_first(): void
    {
        $tenant = Tenant::factory()->create();
        MigrateModules::dispatchSync($tenant, 'notes');

        $tenant->run(function () {
            $author = User::factory()->create();

            Note::factory()->for($author, 'author')->create(['title' => 'unpinned']);
            $pinned = Note::factory()->pinned()->for($author, 'author')->create(['title' => 'pinned']);

            $first = Note::query()->orderByDesc('pinned')->first();

            $this->assertNotNull($first);
            $this->assertSame($pinned->id, $first->id);
        });
    }
}
