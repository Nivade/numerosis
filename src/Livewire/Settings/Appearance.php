<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\View\View;
use Livewire\Component;

class Appearance extends Component
{
    /**
     * Livewire's default view guess rebuilds the view name from this
     * class's own namespace segments, resolved against the host's
     * `resources/views/livewire/*` — wrong once the class ships from the
     * package. See `.claude/plans/package-extraction.md` step 2.
     */
    public function render(): View
    {
        return view('numerosis::livewire.settings.appearance');
    }
}
