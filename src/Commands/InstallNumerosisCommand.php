<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\Domain;
use Nvade\Numerosis\Models\Central\PaymentPlan;
use Nvade\Numerosis\Models\Central\PendingTenantProvision;
use Nvade\Numerosis\Models\Central\Subscription;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Invitation;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Models\Tenant\User as TenantUser;

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
    public $signature = 'numerosis:install {--verify-only : Run the host-configuration checks without publishing anything or touching .env}';

    public $description = 'Publish Numerosis config and model stubs, then verify the host is wired correctly';

    /** @var list<string> */
    private array $failures = [];

    /**
     * Overrides written to .env during this run. Config was already resolved
     * when they landed, so `config('numerosis.models.*')` still reads null for
     * them — verification has to consult this instead of reporting a problem
     * the command just fixed.
     *
     * @var array<class-string, string>
     */
    private array $appendedModelOverrides = [];

    public function handle(): int
    {
        if (! $this->option('verify-only')) {
            $this->publishAssets();
            $this->appendEnvKeys();
            $this->appendModelOverrides();
        }

        $this->newLine();
        $this->components->info('Verifying host configuration');

        $this->verifyDatabaseConnections();
        $this->verifySessionDomain();
        $this->verifyAuthGuards();
        $this->verifyLivewireDiskExclusion();
        $this->verifyTenantMigrationPath();
        $this->verifyStripeKeys();
        $this->verifyModelOverrides();

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

    /**
     * Points config at the model stubs that were just published. Publishing a
     * stub does nothing on its own: every package call site resolves through
     * `Numerosis::model()`, which returns the package's own class unless
     * `numerosis.models.<FQCN>` names something else. A host that publishes
     * stubs, creates rows through them, and leaves config unset gets package
     * class-strings written into morph columns and Cashier's `updateOrCreate`
     * missing rows it should have found — surfacing as SQLSTATE 1205/1062 on
     * an unrelated insert, which reads as lock contention rather than as a
     * config gap.
     *
     * Values are single-quoted. A double-quoted class-string
     * (`"App\Models\Central\Tenant"`) is an unrecognised escape sequence to
     * phpdotenv, which throws `InvalidFileException` for the *whole file* —
     * i.e. writing one here would stop the host booting at all.
     */
    private function appendModelOverrides(): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return; // appendEnvKeys() already warned about this.
        }

        $env = File::get($envPath);
        $missing = [];

        foreach ($this->modelStubMap() as $packageModel => $stub) {
            // No stub published means the host is running on the package's own
            // models, which is a supported shape (D8) — nothing to point at.
            if (! File::exists($stub['path'])) {
                continue;
            }

            if (preg_match('/^'.preg_quote($stub['env'], '/').'=/m', $env) === 1) {
                continue;
            }

            $missing[] = "{$stub['env']}='{$stub['class']}'";
            $this->appendedModelOverrides[$packageModel] = $stub['class'];
        }

        if ($missing === []) {
            return;
        }

        File::append($envPath, "\n# Added by numerosis:install — published model stubs, see docs/host-requirements.md\n".implode("\n", $missing)."\n");

        $this->components->info('Pointed '.count($missing).' model override(s) at the published stubs.');
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

    /**
     * Three failure shapes, all of which a host hits silently otherwise: a
     * stub published but not configured (see appendModelOverrides()'s note on
     * the SQLSTATE it surfaces as), a configured class that does not exist,
     * and a configured class that is not a subclass — `Numerosis::model()`
     * returns whatever the key names, so an unrelated class is handed to
     * Eloquent and fails far from here.
     */
    private function verifyModelOverrides(): void
    {
        foreach ($this->modelStubMap() as $packageModel => $stub) {
            $configured = Config::get("numerosis.models.{$packageModel}");

            if (! is_string($configured) || $configured === '') {
                $configured = $this->appendedModelOverrides[$packageModel] ?? null;
            }

            if ($configured === null) {
                if (File::exists($stub['path'])) {
                    $this->failures[] = "A model stub is published at {$stub['path']}, but config('numerosis.models.{$packageModel}') is unset — every package call site keeps using the package's own class, so rows created through the stub are written with the wrong class-string (this surfaces later as SQLSTATE 1205/1062 on an unrelated insert). Set {$stub['env']}='{$stub['class']}' in .env.";
                }

                continue;
            }

            if (! class_exists($configured)) {
                $this->failures[] = "config('numerosis.models.{$packageModel}') names '{$configured}', which does not exist — check {$stub['env']} in .env.";

                continue;
            }

            if (! is_subclass_of($configured, $packageModel)) {
                $this->failures[] = "config('numerosis.models.{$packageModel}') names '{$configured}', which does not extend {$packageModel} — an override must be a subclass, or package code hands Eloquent a class it knows nothing about.";
            }
        }
    }

    /**
     * The 9 models a host may override, each with the env key
     * config/numerosis.php reads it from and the path
     * NumerosisServiceProvider publishes its stub to. Kept in one place so
     * appendModelOverrides() and verifyModelOverrides() cannot disagree about
     * which models exist.
     *
     * @return array<class-string, array{env: string, path: string, class: string}>
     */
    private function modelStubMap(): array
    {
        /** @var array<class-string, array{string, string}> $models */
        $models = [
            Tenant::class => ['NUMEROSIS_MODEL_TENANT', 'Central/Tenant'],
            Domain::class => ['NUMEROSIS_MODEL_DOMAIN', 'Central/Domain'],
            CentralUser::class => ['NUMEROSIS_MODEL_CENTRAL_USER', 'Central/CentralUser'],
            Subscription::class => ['NUMEROSIS_MODEL_SUBSCRIPTION', 'Central/Subscription'],
            PaymentPlan::class => ['NUMEROSIS_MODEL_PAYMENT_PLAN', 'Central/PaymentPlan'],
            PendingTenantProvision::class => ['NUMEROSIS_MODEL_PENDING_TENANT_PROVISION', 'Central/PendingTenantProvision'],
            Invitation::class => ['NUMEROSIS_MODEL_INVITATION', 'Tenant/Invitation'],
            Module::class => ['NUMEROSIS_MODEL_MODULE', 'Tenant/Module'],
            TenantUser::class => ['NUMEROSIS_MODEL_TENANT_USER', 'Tenant/User'],
        ];

        $namespace = $this->laravel->getNamespace();
        $map = [];

        foreach ($models as $packageModel => [$env, $relative]) {
            $map[$packageModel] = [
                'env' => $env,
                'path' => app_path("Models/{$relative}.php"),
                'class' => $namespace.'Models\\'.str_replace('/', '\\', $relative),
            ];
        }

        return $map;
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
