<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Publishes config + model stubs, appends the env keys host-requirements.md
 * documents, then verifies the result rather than trusting it — a host that
 * skips a step here fails at first tenant request with an error that reads
 * like a routing or database bug (see docs/host-requirements.md's own
 * "why the package can't own this file" note), not at install time. Every
 * check below mirrors a row in that document; the two must not drift.
 */
class InstallNumerosisCommand extends Command
{
    public $signature = 'numerosis:install';

    public $description = 'Publish Numerosis config and model stubs, then verify the host is wired correctly';

    /** @var list<string> */
    private array $failures = [];

    public function handle(): int
    {
        $this->publishAssets();
        $this->appendEnvKeys();

        $this->newLine();
        $this->components->info('Verifying host configuration');

        $this->verifyDatabaseConnections();
        $this->verifySessionDomain();
        $this->verifyAuthGuards();
        $this->verifyLivewireDiskExclusion();
        $this->verifyTenantMigrationPath();
        $this->verifyStripeKeys();

        if ($this->failures !== []) {
            $this->newLine();
            $this->components->error('numerosis:install found '.count($this->failures).' problem(s):');

            foreach ($this->failures as $failure) {
                $this->line("  - {$failure}");
            }

            $this->newLine();
            $this->components->error('See docs/host-requirements.md for the required shape of each config file above.');

            return self::FAILURE;
        }

        $this->components->info('Host configuration looks correct.');
        $this->printManualSteps();

        return self::SUCCESS;
    }

    private function publishAssets(): void
    {
        $this->call('vendor:publish', ['--tag' => 'numerosis-config', '--force' => false]);
        $this->call('vendor:publish', ['--tag' => 'numerosis-models', '--force' => false]);

        // verifyTenantMigrationPath() below checks this directory exists —
        // config/tenancy.php's default '--path' points at it, but nothing
        // creates it unless this tag is published too. Without this, a
        // fresh install always fails the check it added itself.
        $this->call('vendor:publish', ['--tag' => 'numerosis-tenant-migrations', '--force' => false]);
    }

    /**
     * Appends the env keys docs/host-requirements.md documents as required,
     * if missing — never overwrites a key the host already set.
     */
    private function appendEnvKeys(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            $this->components->warn('.env not found — skipping env key check.');

            return;
        }

        $env = File::get($envPath);

        $keys = [
            'DOMAIN' => 'localhost',
            'CENTRAL_SUBDOMAIN' => 'app',
            'SESSION_DOMAIN' => 'null',
            'STRIPE_KEY' => 'pk_test_your_stripe_publishable_key',
            'STRIPE_SECRET' => 'sk_test_your_stripe_secret_key',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_your_webhook_secret',
        ];

        $missing = [];

        foreach ($keys as $key => $placeholder) {
            if (preg_match('/^'.preg_quote($key, '/').'=/m', $env) !== 1) {
                $missing[] = "{$key}={$placeholder}";
            }
        }

        if ($missing === []) {
            return;
        }

        File::append($envPath, "\n# Added by numerosis:install — see docs/host-requirements.md\n".implode("\n", $missing)."\n");

        $this->components->info('Appended '.count($missing).' missing env key(s) to .env: '.implode(', ', array_keys($keys)));
    }

    private function verifyDatabaseConnections(): void
    {
        /** @var array<string, mixed> $connections */
        $connections = Config::array('database.connections');

        // Only 'central' is a static config key. 'tenant' is stancl's
        // reserved name for a connection it builds dynamically at
        // tenancy-bootstrap time from 'template_tenant_connection' — it
        // never exists in config('database.connections') outside a request
        // that has actually initialized tenancy, so checking for it here
        // always fails on a freshly installed host. See config/tenancy.php's
        // own "don't name your template connection tenant" comment.
        if (! array_key_exists('central', $connections)) {
            $this->failures[] = "config('database.connections.central') is missing — see docs/host-requirements.md's config/database.php row.";
        }

        $template = Config::get('tenancy.database.template_tenant_connection');

        if (is_string($template) && ! array_key_exists($template, $connections)) {
            $this->failures[] = "config('tenancy.database.template_tenant_connection') names '{$template}', which is not in config('database.connections').";
        }
    }

    private function verifySessionDomain(): void
    {
        $domain = Config::get('session.domain');

        if (! is_string($domain) || ! str_starts_with($domain, '.')) {
            $this->failures[] = "config('session.domain') must start with a leading dot (e.g. '.example.com') — see docs/host-requirements.md's config/session.php row.";
        }
    }

    private function verifyAuthGuards(): void
    {
        /** @var array<string, mixed> $guards */
        $guards = Config::array('auth.guards');

        foreach (['central', 'tenant'] as $context) {
            $guardName = Config::get("auth.defaults.guards.context.{$context}");

            if (! is_string($guardName) || ! array_key_exists($guardName, $guards)) {
                $this->failures[] = "config('auth.defaults.guards.context.{$context}') does not resolve to a real guard in config('auth.guards') — see docs/host-requirements.md's config/auth.php row.";
            }
        }
    }

    private function verifyLivewireDiskExclusion(): void
    {
        /** @var list<string> $disks */
        $disks = Config::array('tenancy.filesystem.disks');

        if (in_array('livewire', $disks, true)) {
            $this->failures[] = "config('tenancy.filesystem.disks') must NOT contain 'livewire' — see docs/host-requirements.md's config/filesystems.php row.";
        }
    }

    private function verifyTenantMigrationPath(): void
    {
        /** @var array<string, mixed> $parameters */
        $parameters = Config::array('tenancy.migration_parameters');
        $paths = $parameters['--path'] ?? null;

        if (! is_array($paths) || $paths === []) {
            $this->failures[] = "config('tenancy.migration_parameters')['--path'] is missing — see docs/host-requirements.md's config/tenancy.php row.";

            return;
        }

        foreach ($paths as $path) {
            if (! is_string($path)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] contains a non-string entry.";

                continue;
            }

            if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] must be absolute, got '{$path}' — pass --realpath and point it at vendor/nvade/numerosis/database/migrations/tenant.";

                continue;
            }

            if (! File::isDirectory($path)) {
                $this->failures[] = "config('tenancy.migration_parameters')['--path'] entry '{$path}' does not exist.";
            }
        }
    }

    private function verifyStripeKeys(): void
    {
        foreach (['key', 'secret'] as $setting) {
            if (! is_string(Config::get("cashier.{$setting}")) || Config::get("cashier.{$setting}") === '') {
                $this->failures[] = "config('cashier.{$setting}') is not set — add STRIPE_".strtoupper($setting).' to .env.';
            }
        }
    }

    private function printManualSteps(): void
    {
        $this->newLine();
        $this->components->info('Manual steps this command cannot do for you:');
        $this->line('  1. Wildcard DNS: point *.'.Config::string('numerosis.domains.tenant_pattern', '{tenant}.your-domain').' at this app.');
        $this->line('  2. Register the package\'s Filament panel plugin(s) in your own AdminPanelProvider / TenantAdminPanelProvider.');
        $this->line('  3. Run a queue worker on the dedicated "provisioning" queue (see docker/8.5/supervisord.conf\'s [program:queue-provisioning] in saas-m for the reference config) — tenant provisioning is queued there, not on the default worker.');
    }
}
