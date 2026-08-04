<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Contracts;

interface Feature
{
    public function bootstrap(): void;
}
