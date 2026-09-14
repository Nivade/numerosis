<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Requests\Billing;

use Nvade\Numerosis\Data\Tenancy\BillingContribution;
use Nvade\Numerosis\Data\Tenancy\OwnerContribution;
use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Http\Requests\Billing\StartCheckoutRequest;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * A rule key that names neither a `TenantProvisionData` field nor a field of a
 * contribution the request builds is validated and then dropped, so the value
 * silently arrives null. Renaming `domain` to `slug` on the DTO without
 * renaming the rule did exactly that, and nothing else noticed.
 */
class StartCheckoutRequestTest extends TestCase
{
    public function test_every_rule_key_reaches_the_provision_data_it_builds(): void
    {
        $fields = [
            ...$this->fieldsOf(TenantProvisionData::class),
            ...$this->fieldsOf(OwnerContribution::class),
            ...$this->fieldsOf(BillingContribution::class),
        ];

        $rules = array_keys((new StartCheckoutRequest)->rules());

        $this->assertNotSame([], $rules);

        foreach ($rules as $rule) {
            $this->assertContains(
                $rule,
                $fields,
                "StartCheckoutRequest validates [{$rule}], which neither TenantProvisionData nor any contribution it builds has a field for, so the value is dropped.",
            );
        }
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function fieldsOf(string $class): array
    {
        return array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            new ReflectionClass($class)->getConstructor()?->getParameters() ?? [],
        );
    }
}
