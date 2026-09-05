<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;
use Pest\Browser\Playwright\Playwright;

uses(TestCase::class, RefreshDatabase::class);

/**
 * The login screen is Fortify's route rendering `numerosis::auth.login`, a
 * plain Blade form since Phase 4 — no Livewire on a guest auth screen. Over
 * real HTTP is the only place the `@csrf` that replaced Livewire's own token
 * is observable.
 */
it('serves the central login page over real HTTP', function (): void {
    Playwright::setHost('central.numerosistest.test');

    $page = visit('/login');

    $page->assertSee('Log in');
    $page->assertPresent('input[name="_token"]');
});
