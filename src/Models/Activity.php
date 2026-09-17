<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Config;
use Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation;
use Nvade\Numerosis\Enums\Audit\ActivityActor;
use Nvade\Numerosis\Models\Central\ImpersonationSession;
use Override;
use Spatie\Activitylog\Models\Activity as BaseActivity;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * `activity_log` exists twice, once per connection, so an entry written against
 * the default connection records a central row inside a tenant's database with
 * an id from another one. The subject picks the connection here. A
 * central-connection subject writes centrally, everything else writes where it
 * is.
 */
class Activity extends BaseActivity
{
    /**
     * Two properties every entry carries, whether it came from an attribute
     * diff or from a domain event: who really did it while a staff user was
     * impersonating, and that a causer-less entry was the system, never an
     * anonymous someone.
     */
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $properties = $activity->properties?->toArray() ?? [];

            $session = GetCurrentImpersonation::run();

            if ($session instanceof ImpersonationSession) {
                $properties['impersonated_global_id'] = $session->target_global_id;
                $properties['impersonation_session_id'] = $session->getKey();
            }

            $properties['actor'] ??= match (true) {
                $session instanceof ImpersonationSession => ActivityActor::Staff->value,
                $activity->causer_id !== null => ActivityActor::User->value,
                default => ActivityActor::System->value,
            };

            $activity->properties = collect($properties);
        });
    }

    #[Override]
    public function getConnectionName(): ?string
    {
        if ($this->subjectIsCentral()) {
            return Config::string('tenancy.database.central_connection');
        }

        return parent::getConnectionName();
    }

    private function subjectIsCentral(): bool
    {
        $subjectType = $this->attributes['subject_type'] ?? null;

        if (! is_string($subjectType)) {
            return false;
        }

        $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

        if (! is_subclass_of($class, Model::class)) {
            return false;
        }

        return in_array(CentralConnection::class, class_uses_recursive($class), true);
    }
}
