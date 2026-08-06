<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Tenants\Pages;

use Exception;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nvade\Numerosis\Filament\Admin\Resources\Tenants\TenantResource;
use Nvade\Numerosis\Models\Central\Tenant;
use RuntimeException;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @param  array{domain: string}  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $domain = $data['domain'];

        throw_unless(preg_match('/^[a-z0-9]+$/', $domain), InvalidArgumentException::class, 'Subdomain must contain only lowercase letters and numbers');

        return DB::transaction(function () use ($data, $domain) {

            /** @var Tenant $record */
            $record = ($this->getModel())::create($data);

            $record->save();

            try {
                $record->domains()->create([
                    'domain' => $domain.'.'.Config::string('numerosis.domains.apex'),
                ]);
            } catch (Exception $e) {
                throw new RuntimeException('Failed to create domain for tenant: '.$e->getMessage(), $e->getCode(), $e);
            }

            return $record;
        });
    }
}
