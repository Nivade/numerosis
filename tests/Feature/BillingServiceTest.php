<?php

declare(strict_types=1);

use Nvade\Numerosis\Facades\Billing;

it('creates facade', function () {
    $root = Billing::getFacadeRoot();

    expect(is_object($root))->toBeTrue();
    assert(is_object($root));

    expect(resolve($root::class))->toBeInstanceOf($root::class);
});
