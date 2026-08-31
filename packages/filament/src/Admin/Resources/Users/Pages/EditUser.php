<?php

declare(strict_types=1);

namespace Nvade\NumerosisFilament\Admin\Resources\Users\Pages;

use Filament\Resources\Pages\EditRecord;
use Nvade\NumerosisFilament\Admin\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
