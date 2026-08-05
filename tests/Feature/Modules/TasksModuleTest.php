<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Modules;

use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvade\Numerosis\Actions\Modules\MigrateModules;
use Nvade\Numerosis\Models\Permission;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\Tasks\Enums\TaskPriority;
use Nvade\Tasks\Enums\TaskStatus;
use Nvade\Tasks\Models\Task;

class TasksModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrating_creates_the_tasks_table_on_the_tenant_only_and_seeds_permissions(): void
    {
        $tenant = Tenant::factory()->create();

        MigrateModules::dispatchSync($tenant, 'tasks');

        $this->assertFalse(Schema::hasTable('tasks'));

        $tenant->run(function () {
            $this->assertTrue(Schema::hasTable('tasks'));
            $this->assertTrue(
                Permission::where('name', 'view tasks')->where('guard_name', 'tenant')->exists()
            );
        });
    }

    public function test_a_task_past_its_due_date_and_not_done_is_overdue(): void
    {
        $tenant = Tenant::factory()->create();
        MigrateModules::dispatchSync($tenant, 'tasks');

        $tenant->run(function () {
            $assignee = User::factory()->create();

            $overdue = Task::factory()->for($assignee, 'assignee')->create([
                'status' => TaskStatus::Open,
                'due_at' => now()->subDay(),
            ]);

            $notYetDue = Task::factory()->create([
                'status' => TaskStatus::Open,
                'due_at' => now()->addDay(),
            ]);

            $doneButLate = Task::factory()->create([
                'status' => TaskStatus::Done,
                'due_at' => now()->subDay(),
            ]);

            $freshOverdue = $overdue->fresh();
            $freshNotYetDue = $notYetDue->fresh();
            $freshDoneButLate = $doneButLate->fresh();
            $this->assertNotNull($freshOverdue);
            $this->assertNotNull($freshNotYetDue);
            $this->assertNotNull($freshDoneButLate);

            $this->assertTrue($freshOverdue->isOverdue());
            $this->assertFalse($freshNotYetDue->isOverdue());
            $this->assertFalse($freshDoneButLate->isOverdue());
            $this->assertNotNull($freshOverdue->assignee);
            $this->assertSame($assignee->id, $freshOverdue->assignee->id);
        });
    }

    public function test_priority_and_status_cast_to_their_enums(): void
    {
        $tenant = Tenant::factory()->create();
        MigrateModules::dispatchSync($tenant, 'tasks');

        $tenant->run(function () {
            $task = Task::factory()->create([
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
            ]);

            $fresh = $task->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame(TaskStatus::InProgress, $fresh->status);
            $this->assertSame(TaskPriority::High, $fresh->priority);
        });
    }
}
