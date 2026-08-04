<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Modules;

use Nvade\Numerosis\Models\Tenant\Module;
use Illuminate\Support\Str;
use InterNACHI\Modular\Support\Facades\Modules;
use InterNACHI\Modular\Support\ModuleConfig;
use Lorisleiva\Actions\Concerns\AsAction;

class SynchronizeModules
{
    use AsAction;

    public function handle(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        Modules::modules()->each(function ($module): void {
            if (! $module instanceof ModuleConfig) {
                return;
            }

            Module::updateOrCreate(
                ['name' => Str::lower($module->name)],
                ['description' => $this->describe($module)],
            );
        });
    }

    protected function describe(ModuleConfig $module): ?string
    {
        $manifest = $module->path('composer.json');

        if (! is_file($manifest)) {
            return null;
        }

        $contents = file_get_contents($manifest);

        if ($contents === false) {
            return null;
        }

        /** @var array{description?: string}|null $config */
        $config = json_decode($contents, true);

        $description = $config['description'] ?? null;

        return $description === '' ? null : $description;
    }
}
