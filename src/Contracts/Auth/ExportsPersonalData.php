<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts\Auth;

/**
 * Everything one person's subject access request has to hand back: their
 * central identity rows plus, for every tenant they belong to, the tenant-side
 * rows naming them. Point `numerosis.tenancy.implementations` at your own
 * class to add the tables a host owns.
 */
interface ExportsPersonalData
{
    /**
     * @param  string  $globalUserId  The subject's global identifier instead of a model: a contract
     *                                typed on this package's own Eloquent classes nails every
     *                                implementer to them.
     * @return string The archive's path on the disk.
     */
    public function export(string $globalUserId, ?string $disk = null): string;
}
