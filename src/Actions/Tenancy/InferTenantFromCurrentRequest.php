<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Tenancy;

use Nvade\Numerosis\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

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
        $centralDomains = Config::get('tenancy.central_domains', []);

        if (in_array($host, $centralDomains, true)) {
            return null;
        }

        $subdomain = Str::before($host, '.');

        return Tenant::firstWhere('id', $subdomain);
    }

    public function asController(): void
    {
        $this->handle();
    }
}
