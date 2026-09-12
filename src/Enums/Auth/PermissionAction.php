<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Enums\Auth;

enum PermissionAction: string
{
    case ViewAny = 'viewAny';
    case View = 'view';
    case Create = 'create';
    case UpdateAny = 'updateAny';
    case Update = 'update';
    case DeleteAny = 'deleteAny';
    case Delete = 'delete';
    case Restore = 'restore';
    case ForceDelete = 'forceDelete';
}
