<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvade\Numerosis\Actions\Tenancy\InferTenantFromCurrentRequest;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Models\Tenant\Module;
use Nvade\Numerosis\Support\Numerosis;

trait InteractsWithTenantModules
{
    /** @var Collection<int, string>|null */
    protected ?Collection $enabledModuleNames = null;

    /**
     * The enabled module names for the current tenant, fetched once and
     * memoised for the request. Filament registers plugins for every
     * configured module slug at once, so a per-module query here (as this
     * used to be) means N plugins cost N temporary database connections at
     * panel-register time. Not cached in global_cache() — that helper is
     * un-prefixed and this value is tenant-derived, exactly the mistake
     * .claude/rules/tenant-caching.md warns about. A per-request memo is
     * correct and sufficient.
     *
     * @return Collection<int, string>
     */
    public function getEnabledModuleNames(): Collection
    {
        if ($this->enabledModuleNames !== null) {
            return $this->enabledModuleNames;
        }

        $moduleClass = Numerosis::model(Module::class);

        if (tenancy()->initialized) {
            /** @var Collection<int, string> $names */
            $names = $moduleClass::where('enabled', true)->pluck('name');

            return $this->enabledModuleNames = $names;
        }

        /** @var Tenant|null $tenant */
        $tenant = InferTenantFromCurrentRequest::run();

        if (! $tenant) {
            return $this->enabledModuleNames = new Collection;
        }

        // Filament registers plugins very early, before tenancy bootstrappers are initialized.
        // We manually configure the tenant connection to fetch the module state.
        $dbName = Config::string('tenancy.database.prefix').$tenant->id.Config::string('tenancy.database.suffix');
        $centralConn = Config::string('database.default');
        $tenantConnName = 'tenant_init_'.uniqid();

        /** @var array<string, mixed> $centralConnConfig */
        $centralConnConfig = Config::get("database.connections.{$centralConn}");

        Config::set("database.connections.{$tenantConnName}", array_merge(
            $centralConnConfig,
            ['database' => $dbName]
        ));

        try {
            /** @var Collection<int, string> $names */
            $names = $moduleClass::on($tenantConnName)->where('enabled', true)->pluck('name');

            return $this->enabledModuleNames = $names;
        } catch (QueryException|InvalidArgumentException $e) {
            report($e);

            return $this->enabledModuleNames = new Collection;
        } finally {
            // Both halves are required. purge() closes the PDO handle and drops
            // it from the DatabaseManager's resolved-connection list; without
            // it the connection stays open for the rest of the request even
            // once its config is gone.
            //
            // The config entry has to be rewritten wholesale to actually
            // disappear: `Config::set($key)` with no value — and
            // `Config::offsetUnset($key)`, which is literally `set($key, null)`
            // — both *write null into the key* rather than removing it, so the
            // original one-argument call left a null connection definition
            // behind on every invocation.
            DB::purge($tenantConnName);

            $connections = Config::array('database.connections');
            unset($connections[$tenantConnName]);
            Config::set('database.connections', $connections);
        }
    }

    public function isModuleEnabled(string $moduleName): bool
    {
        return $this->getEnabledModuleNames()->contains($moduleName);
    }
}
