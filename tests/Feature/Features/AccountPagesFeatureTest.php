<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Features;

use Illuminate\Support\Facades\Route;
use Nvade\Numerosis\Tests\TestCase;

/**
 * The account UI (settings, workspace list, billing portal) folded into core
 * in Phase 3 of `.claude/plans/humming-nibbling-flame.md` with no feature
 * flag of its own — `AccountPagesFeature` and `Support\Ui\AccountPages` were
 * deleted, and these routes register unconditionally now. `settings.appearance`
 * is gone with them: `Settings\Appearance` was a starter-kit nicety, deleted
 * rather than moved.
 */
class AccountPagesFeatureTest extends TestCase
{
    public function test_it_registers_the_account_routes(): void
    {
        $this->assertTrue(Route::has('settings.profile'));
        $this->assertTrue(Route::has('tenants.mine'));
        $this->assertTrue(Route::has('billing-portal'));
    }
}
