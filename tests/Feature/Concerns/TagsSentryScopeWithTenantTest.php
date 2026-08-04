<?php

declare(strict_types=1);

use Nvade\Numerosis\Concerns\TagsSentryScopeWithTenant;
use Sentry\Event;
use Sentry\State\Scope;

it('tags the current Sentry scope with the tenant key', function () {
    $job = new class
    {
        use TagsSentryScopeWithTenant;

        public function fail(string $tenantKey): void
        {
            $this->tagSentryScopeWithTenant($tenantKey);
        }
    };

    $job->fail('acme');

    $event = null;
    \Sentry\configureScope(function (Scope $scope) use (&$event): void {
        $event = $scope->applyToEvent(Event::createEvent());
    });

    expect($event?->getTags())->toHaveKey('tenant_id', 'acme');
});

it('is a no-op when Sentry is not bound', function () {
    app()->offsetUnset('sentry');

    $job = new class
    {
        use TagsSentryScopeWithTenant;

        public function fail(string $tenantKey): void
        {
            $this->tagSentryScopeWithTenant($tenantKey);
        }
    };

    $job->fail('acme');
})->throwsNoExceptions();
