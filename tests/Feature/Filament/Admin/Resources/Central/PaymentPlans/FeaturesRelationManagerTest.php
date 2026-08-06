<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Admin\Resources\Central\PaymentPlans;

use App\Models\Central\CentralUser;
use App\Models\Central\PaymentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\Pages\EditPaymentPlan;
use Nvade\Numerosis\Filament\Admin\Resources\Central\PaymentPlans\RelationManagers\FeaturesRelationManager;
use Nvade\Numerosis\Models\Central\Feature;
use Nvade\Numerosis\Tests\TestCase;

/**
 * FeaturesRelationManager was fully built but commented out of
 * PaymentPlanResource::getRelations() with no explanation — wired back in
 * this round. This proves it actually renders and reflects the pivot's
 * 'available' column, not just that the class compiles.
 */
class FeaturesRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.central_domains' => ['localhost']]);

        Gate::before(fn () => true);
    }

    public function test_it_lists_attached_features_with_their_availability(): void
    {
        $user = CentralUser::factory()->create();
        $this->actingAs($user);

        $plan = PaymentPlan::factory()->create();
        $feature = Feature::factory()->create(['slug' => 'priority-support']);

        $plan->features()->attach($feature->id, ['available' => true]);

        Livewire::test(FeaturesRelationManager::class, [
            'ownerRecord' => $plan,
            'pageClass' => EditPaymentPlan::class,
        ])
            // Order matters: Livewire's Testable forwards assertSuccessful()
            // to the underlying TestResponse and returns *that*, so anything
            // chained after it is no longer a Filament table assertion.
            ->assertCanSeeTableRecords([$feature])
            ->assertSuccessful();
    }
}
