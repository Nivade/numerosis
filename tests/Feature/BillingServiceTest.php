<?php

declare(strict_types=1);

use Nvade\Numerosis\Facades\Billing;

it('creates facade', function () {
    $root = Billing::getFacadeRoot();

    expect(is_object($root))->toBeTrue();
    assert(is_object($root));

    expect(app(get_class($root)))->toBeInstanceOf(get_class($root));
});
