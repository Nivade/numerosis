<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Actions\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsAction;
use Nvade\Numerosis\Concerns\TenancyAwareUserModel;
use Nvade\Numerosis\Contracts\Auth\ResolvesLoginCandidate;

/**
 * @method static ?Authenticatable run(string $email)
 */
class FindLoginCandidate implements ResolvesLoginCandidate
{
    use AsAction;
    use TenancyAwareUserModel;

    public function handle(string $email): ?Authenticatable
    {
        return $this->find($email);
    }

    public function find(string $email): ?Authenticatable
    {
        return $this->userModel()::firstWhere('email', $email);
    }
}
