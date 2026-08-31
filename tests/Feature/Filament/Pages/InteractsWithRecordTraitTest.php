<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Filament\Pages;

use App\Models\Central\CentralUser;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Tests\TestCase;
use Nvade\NumerosisFilament\Concerns\InteractsWithRecord;

/**
 * Exercises the Filament trait, not tenancy, so the record is a CentralUser:
 * saving a Tenant\User outside tenant context fires SyncedResourceSaved and
 * dies with ModelNotSyncMasterException, which says nothing about the trait.
 */
class InteractsWithRecordTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_set_and_get_record(): void
    {
        $user = CentralUser::factory()->create();

        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $page->record = $user;

        $this->assertTrue($page->hasRecord());
        $this->assertEquals($user->id, $page->getRecord()->id);
    }

    public function test_can_get_model_from_record(): void
    {
        $user = CentralUser::factory()->create();

        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $page->record = $user;

        $this->assertEquals(CentralUser::class, $page->getModel());
    }

    public function test_can_set_custom_model_label(): void
    {
        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $page->model(CentralUser::class)
            ->modelLabel('team member')
            ->pluralModelLabel('team members');

        $this->assertEquals('team member', $page->getModelLabel());
        $this->assertEquals('team members', $page->getPluralModelLabel());
        $this->assertEquals('Team Member', $page->getTitleCaseModelLabel());
        $this->assertEquals('Team Members', $page->getTitleCasePluralModelLabel());
    }

    public function test_can_set_custom_record_title_attribute(): void
    {
        $user = CentralUser::factory()->create([
            'name' => 'John Doe',
        ]);

        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $page->record = $user;
        $page->recordTitleAttribute('name');

        $this->assertEquals('John Doe', $page->getCustomRecordTitle());
        $this->assertTrue($page->hasCustomRecordTitleAttribute());
    }

    public function test_can_set_custom_record_title_closure(): void
    {
        $user = CentralUser::factory()->create([
            'name' => 'Jane Smith',
        ]);

        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $page->record = $user;
        $page->recordTitle(fn (CentralUser $record): string => "User: {$record->name}");

        $this->assertEquals('User: Jane Smith', $page->getCustomRecordTitle());
        $this->assertTrue($page->hasCustomRecordTitle());
    }

    public function test_can_resolve_record_using_closure(): void
    {
        $user = CentralUser::factory()->create();

        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';

            public function mount(): void
            {
                $this->resolveRecordUsing(fn (int|string $key): CentralUser => CentralUser::findOrFail($key));

                // $record is declared Model|int|string|null, but resolveRecord()
                // only accepts the key half of that union.
                if (is_int($this->record) || is_string($this->record)) {
                    $this->record = $this->resolveRecord($this->record);
                }
            }
        };

        $page->record = $user->id;
        $page->mount();

        $this->assertTrue($page->hasRecord());
        $this->assertInstanceOf(CentralUser::class, $page->getRecord());
        $this->assertEquals($user->id, $page->getRecord()->id);
    }

    public function test_widget_data_includes_record(): void
    {
        $user = CentralUser::factory()->create();

        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $page->record = $user;

        $widgetData = $page->getWidgetData();

        $this->assertArrayHasKey('record', $widgetData);
        $this->assertInstanceOf(CentralUser::class, $widgetData['record']);
        $this->assertEquals($user->id, $widgetData['record']->id);
    }

    public function test_returns_null_when_no_record_set(): void
    {
        $page = new class extends Page
        {
            use InteractsWithRecord;

            protected string $view = 'filament.pages.test';
        };

        $this->assertFalse($page->hasRecord());
        $this->assertNull($page->getDefaultActionRecord(action: new Action('test')));
    }
}
