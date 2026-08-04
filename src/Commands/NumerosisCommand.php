<?php

namespace Nvade\Numerosis\Commands;

use Illuminate\Console\Command;

class NumerosisCommand extends Command
{
    public $signature = 'numerosis';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
