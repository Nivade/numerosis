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
     * The enabled module names for the current tenant, read once per request.
     *
     * Memoized per request rather than cached: the value is tenant-derived,
     * and the global cache is not tenant-scoped.
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

        // Filament registers plugins before tenancy bootstraps, so the tenant
        // connection has to be built by hand here to read module state.
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
            // Both halves are required to actually remove the connection.
            // purge() closes the PDO handle; rewriting the parent array is
            // the only way to delete a config key, since Config::set() with
            // no value writes null into it rather than removing it.
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
