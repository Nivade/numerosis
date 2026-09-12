<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Http\Requests\Billing;

use Nvade\Numerosis\Data\Tenancy\TenantProvisionData;
use Nvade\Numerosis\Http\Requests\Billing\StartCheckoutRequest;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * `toProvisionData()` builds the DTO straight from `validated()`, so a rule
 * key that does not name a constructor parameter is silently dropped and the
 * field arrives null. Renaming `domain` to `slug` on the DTO without renaming
 * the rule would do exactly that, and nothing else would notice.
 */
class StartCheckoutRequestTest extends TestCase
{
    public function test_every_rule_key_names_a_provision_data_field(): void
    {
        $fields = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionClass(TenantProvisionData::class))->getConstructor()?->getParameters() ?? [],
        );

        $rules = array_keys((new StartCheckoutRequest)->rules());

        $this->assertNotSame([], $rules);

        foreach ($rules as $rule) {
            $this->assertContains(
                $rule,
                $fields,
                "StartCheckoutRequest validates [{$rule}], which TenantProvisionData has no parameter for, so it is dropped by ::from().",
            );
        }
    }
}
