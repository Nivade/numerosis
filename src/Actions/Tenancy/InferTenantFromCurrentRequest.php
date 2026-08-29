<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Central\Tenant;
use Nvade\Numerosis\Support\Numerosis;
use Nvade\Numerosis\Support\Tenancy\TenancyConfigKeys;

class InferTenantFromCurrentRequest
{
    use AsAction;

    /**
     * @returns ?Tenant
     */
    public function handle(): ?Model
    {
        $host = request()->getHost();

        /** @var list<string> $centralDomains */
        $centralDomains = Config::get(TenancyConfigKeys::key('central_domains'), []);

        if (in_array($host, $centralDomains, true)) {
            return null;
        }

        $subdomain = Str::before($host, '.');

        return Numerosis::model(Tenant::class)::firstWhere('id', $subdomain);
    }

    public function asController(): void
    {
        $this->handle();
    }
}
