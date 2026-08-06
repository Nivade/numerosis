<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The package registers its own cron entries
 * ({@see \Nvade\Numerosis\NumerosisServiceProvider::registerSchedule()}).
 *
 * These assertions are about *reachability*, not about the commands' own
 * behaviour — which each already has its own test. Before this existed, the
 * entries lived in a `routes/console.php` that nothing loaded: a host's
 * `bootstrap/app.php` passes its own file to `withRouting(commands: ...)`, so
 * `artisan schedule:list` in a real consumer printed "No scheduled tasks have
 * been defined" while every command below looked correctly wired from inside
 * this package. A test that only exercised the commands directly would still
 * have passed throughout — which is exactly why this one asserts through the
 * container's `Schedule`, the same object the scheduler process reads.
 */
class PackageScheduleTest extends TestCase
{
    public function test_it_schedules_the_packages_own_maintenance_commands(): void
    {
        $commands = $this->scheduledCommands();

        $this->assertContains('billing:prune-orphaned-customers', $commands);
        $this->assertContains('tenancy:prune-stalled-provisions', $commands);
    }

    public function test_it_runs_stalled_provision_pruning_hourly_and_customer_pruning_daily(): void
    {
        $schedule = $this->schedule();

        $this->assertSame('0 * * * *', $this->expressionFor($schedule, 'tenancy:prune-stalled-provisions'));
        $this->assertSame('0 0 * * *', $this->expressionFor($schedule, 'billing:prune-orphaned-customers'));
    }

    public function test_each_entry_is_gated_by_its_own_config_switch(): void
    {
        Config::set('numerosis.schedule.prune_orphaned_customers', false);
        Config::set('numerosis.schedule.prune_stalled_provisions', false);

        // The closure passed to callAfterResolving() only runs when the
        // Schedule is first resolved, so the config has to be changed before
        // anything touches it — a container already holding a resolved
        // instance would answer with the entries built under the defaults.
        app()->forgetInstance(Schedule::class);

        $commands = $this->scheduledCommands();

        $this->assertNotContains('billing:prune-orphaned-customers', $commands);
        $this->assertNotContains('tenancy:prune-stalled-provisions', $commands);
    }

    public function test_it_does_not_schedule_telescope_pruning_when_telescope_is_absent(): void
    {
        // laravel/telescope is a `suggest`, so it is genuinely not installed
        // in this package's own vendor tree. Guarding the assertion on
        // class_exists keeps this honest rather than merely true today: if a
        // future dev dependency drags Telescope in, the entry is expected.
        $commands = $this->scheduledCommands();

        if (class_exists('Laravel\Telescope\Telescope')) {
            $this->assertContains('telescope:prune', $commands);

            return;
        }

        $this->assertNotContains('telescope:prune', $commands);
    }

    private function schedule(): Schedule
    {
        return app(Schedule::class);
    }

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        return array_values(array_map(
            fn (Event $event): string => $this->commandNameOf($event),
            $this->schedule()->events(),
        ));
    }

    private function expressionFor(Schedule $schedule, string $command): string
    {
        foreach ($schedule->events() as $event) {
            if ($this->commandNameOf($event) === $command) {
                return $event->expression;
            }
        }

        $this->fail("No scheduled event runs [{$command}].");
    }

    /**
     * `Event::$command` is the full shell invocation
     * (`'/usr/bin/php' 'artisan' tenancy:prune-stalled-provisions`), so the
     * artisan command name has to be read back out of it rather than compared
     * whole — the PHP binary path and quoting differ per environment.
     */
    private function commandNameOf(Event $event): string
    {
        $parts = preg_split('/\s+/', trim((string) $event->command)) ?: [];

        foreach ($parts as $part) {
            if (str_contains($part, ':') && ! str_contains($part, '/') && ! str_contains($part, "'")) {
                return $part;
            }
        }

        return (string) $event->command;
    }
}
