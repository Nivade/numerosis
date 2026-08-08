<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\View\Components;

use Nvade\Numerosis\Tests\TestCase;

/**
 * Phase 6 of .claude/plans/design-system-unification.md: one notification
 * channel for every non-Filament surface. `partials/toasts.blade.php`
 * replaces the ad-hoc `<x-numerosis::ui.alert closable />` the registration
 * wizard used to render inline — see git history on
 * resources/views/livewire/tenant/registration/wizard/index.blade.php.
 *
 * Assertions target the Alpine wiring and the server-rendered legacy-flash
 * bridge, not a screenshot: `x-on:notify.window` is what makes this "the one
 * channel" rather than a component nobody dispatches into, and the bridge is
 * what keeps every existing `->with('success', ...)` redirect working with
 * no call-site changes.
 */
class ToastTest extends TestCase
{
    public function test_the_toast_container_listens_for_the_shared_notify_event(): void
    {
        $this->blade("@include('numerosis::partials.toasts')")
            ->assertSee('x-on:notify.window', false);
    }

    public function test_the_toast_container_is_announced_to_assistive_tech(): void
    {
        $this->blade("@include('numerosis::partials.toasts')")
            ->assertSee('aria-live="polite"', false);
    }

    public function test_legacy_session_flash_keys_are_bridged_into_the_same_channel(): void
    {
        session(['success' => 'Saved successfully.']);

        $rendered = (string) $this->blade("@include('numerosis::partials.toasts')");

        $matched = preg_match("/x-init=\"JSON\.parse\('(.+?)'\)\.forEach/", $rendered, $matches);

        $this->assertSame(1, $matched, 'The legacy-flash bridge is missing from the toast container.');

        $bridged = json_decode(str_replace('\\u0022', '"', $matches[1]), true);

        $this->assertSame([['type' => 'success', 'message' => 'Saved successfully.']], $bridged);
    }

    public function test_a_page_with_no_flash_renders_an_empty_bridge(): void
    {
        // Session flash survives until the next request cycle ages it out
        // (Laravel keeps an "old"/"new" bag pair), so a prior test's
        // session(['success' => ...]) is still visible here within the same
        // process — flush explicitly rather than depend on method order.
        session()->flush();

        $this->blade("@include('numerosis::partials.toasts')")
            ->assertSee('x-init="[].forEach((toast) =>', false);
    }

    public function test_toast_renders_every_semantic_type(): void
    {
        $toast = $this->blade('<x-numerosis::ui.toast />');

        foreach (['success', 'warning', 'error', 'info'] as $type) {
            $toast->assertSee("{$type}:", false);
        }

        $toast->assertSee('bg-success-bg', false)
            ->assertSee('bg-warning-bg', false)
            ->assertSee('bg-danger-bg', false)
            ->assertSee('bg-info-bg', false);
    }

    public function test_toast_dismiss_button_carries_the_shared_focus_ring(): void
    {
        $this->blade('<x-numerosis::ui.toast />')
            ->assertSee('focus-ring', false);
    }
}
