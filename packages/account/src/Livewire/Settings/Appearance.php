<?php

declare(strict_types=1);

namespace Nvade\NumerosisAccount\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;

class Appearance extends Component
{
    public function render(): View
    {
        return view('numerosis::livewire.settings.appearance');
    }
}
