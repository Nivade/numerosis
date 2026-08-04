<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Admin\Resources\Users\Pages;

use Nvade\Numerosis\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;
}
