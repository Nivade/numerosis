<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Tests\Feature\Database;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvade\Numerosis\Testing\CleansUpTenancyDatabases;
use Nvade\Numerosis\Tests\Support\TestTenant;
use Nvade\Numerosis\Tests\TestCase;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * A factory's attribute array is untyped, so a column it writes can be dropped
 * by a migration and nothing reports it until someone calls the factory —
 * years later, or never. `PaymentPlanFeatureFactory` wrote seven columns that
 * had not existed since 2026 and shipped that way.
 */
class FactoryDefinitionsTest extends TestCase
{
    use CleansUpTenancyDatabases;
    use RefreshDatabase;

    /**
     * @return list<class-string<Factory<Model>>>
     */
    public static function factories(): array
    {
        $root = dirname(__DIR__, 3).'/database/factories';

        $found = [];

        foreach ((new Finder)->files()->in($root)->name('*Factory.php') as $file) {
            $relative = str_replace([$root.'/', '.php'], '', $file->getPathname());
            /** @var class-string<Factory<Model>> $class */
            $class = 'Nvade\\Numerosis\\Database\\Factories\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) && ! new ReflectionClass($class)->isAbstract()) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    public function test_every_central_factory_creates_a_row(): void
    {
        $factories = array_filter(self::factories(), static fn (string $factory): bool => ! str_contains($factory, '\\Tenant\\'));

        $this->assertNotEmpty($factories, 'Scanned no factories — the path above is wrong, so this guard is measuring nothing.');

        foreach ($factories as $factory) {
            $model = $factory::new()->createOne();

            $this->assertTrue($model->exists, $factory.' created a model that does not exist.');
        }
    }

    /**
     * Inside a tenant, because a tenant model written from the central context
     * throws `ModelNotSyncMasterException` before it reaches a column.
     */
    public function test_every_tenant_factory_creates_a_row(): void
    {
        $factories = array_filter(self::factories(), static fn (string $factory): bool => str_contains($factory, '\\Tenant\\'));

        $this->assertNotEmpty($factories, 'Scanned no tenant factories — the filter above is wrong, so this guard is measuring nothing.');

        TestTenant::provisioned()->run(function () use ($factories): void {
            foreach ($factories as $factory) {
                $model = $factory::new()->createOne();

                $this->assertTrue($model->exists, $factory.' created a model that does not exist.');
            }
        });
    }
}
