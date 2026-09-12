<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi\Tests\Enums;

use Nvade\NumerosisUi\Enums\Severity;
use Nvade\NumerosisUi\Tests\TestCase;

class SeverityTest extends TestCase
{
    public function test_danger_resolves_to_error(): void
    {
        $this->assertSame(Severity::Error, Severity::fromAlias('danger'));
    }

    public function test_message_resolves_to_info(): void
    {
        $this->assertSame(Severity::Info, Severity::fromAlias('message'));
    }

    public function test_a_direct_case_value_resolves_to_itself(): void
    {
        $this->assertSame(Severity::Success, Severity::fromAlias('success'));
    }

    public function test_an_unknown_alias_resolves_to_null(): void
    {
        $this->assertNull(Severity::fromAlias('default'));
        $this->assertNull(Severity::fromAlias(null));
        $this->assertNull(Severity::fromAlias('something-else'));
    }

    public function test_danger_and_error_produce_identical_classes(): void
    {
        $error = Severity::Error;
        $danger = Severity::fromAlias('danger');

        $this->assertNotNull($danger);
        $this->assertSame($error->classes(), $danger->classes());
        $this->assertSame($error->surfaceClasses(), $danger->surfaceClasses());
        $this->assertSame($error->icon(), $danger->icon());
    }
}
