<?php

declare(strict_types=1);

namespace Nvade\NumerosisUi\Tests\Views;

use Nvade\NumerosisUi\Tests\TestCase;

class AlertBadgeSeverityAliasTest extends TestCase
{
    public function test_alert_danger_and_error_render_identical_markup(): void
    {
        $error = (string) $this->blade('<x-numerosis::ui.alert type="error" message="Broke." />');
        $danger = (string) $this->blade('<x-numerosis::ui.alert type="danger" message="Broke." />');

        $this->assertSame($error, $danger);
    }

    public function test_badge_danger_and_error_render_identical_markup(): void
    {
        $error = (string) $this->blade('<x-numerosis::ui.badge variant="error">Failed</x-numerosis::ui.badge>');
        $danger = (string) $this->blade('<x-numerosis::ui.badge variant="danger">Failed</x-numerosis::ui.badge>');

        $this->assertSame($error, $danger);
    }

    public function test_badge_default_variant_is_unaffected_by_severity(): void
    {
        $badge = (string) $this->blade('<x-numerosis::ui.badge>Neutral</x-numerosis::ui.badge>');

        $this->assertStringContainsString('bg-zinc-100', $badge);
    }
}
