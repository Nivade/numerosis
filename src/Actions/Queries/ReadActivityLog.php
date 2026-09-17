<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Models\Activity;
use Nvade\Numerosis\Models\Central\Tenant;

/**
 * Every read of an activity log, on either connection. `activity_log` exists
 * centrally and once per tenant database, so a query that does not name its
 * connection returns plausible rows from the wrong one.
 *
 * @method static Builder<Activity> run()
 */
class ReadActivityLog
{
    use AsAction;

    /**
     * @return Builder<Activity>
     */
    public function handle(): Builder
    {
        return Activity::on(Config::string('tenancy.database.central_connection'));
    }

    /**
     * The central entries about one tenant. Scoped in the query instead of at
     * the screen, since a central row bound on a tenant route carries no
     * tenant scope of its own.
     *
     * @return Builder<Activity>
     */
    public static function forTenant(Tenant $tenant): Builder
    {
        return self::run()
            ->where('subject_type', $tenant->getMorphClass())
            ->where('subject_id', $tenant->id);
    }
}
