<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Livewire\Settings;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Nvade\Numerosis\Actions\Auth\RequestPersonalDataExport;
use Nvade\Numerosis\Concerns\Auth\RequiresAuthenticatedUser;
use Nvade\Numerosis\Exceptions\ShowsMessageToUser;
use Nvade\Numerosis\Models\Central\CentralUser;
use Nvade\Numerosis\Models\Central\DataExportRequest;
use Nvade\Numerosis\Numerosis;

/**
 * Asking for a copy of your data, and the record of having asked. The archive
 * itself never reaches this screen: it arrives as a signed, single-use link by
 * mail, so a shared browser session cannot spend it.
 */
#[Layout('numerosis-layouts::app')]
class Data extends Component
{
    use RequiresAuthenticatedUser;
    use WithPagination;

    public string $status = '';

    public function requestExport(): void
    {
        $this->status = '';

        $user = $this->centralUser();

        try {
            RequestPersonalDataExport::run($user);
        } catch (ShowsMessageToUser $refusal) {
            $this->status = $refusal->getMessage();

            return;
        }

        $this->status = __('We are preparing your data. You will get a download link by email when it is ready.');
    }

    public function render(): View
    {
        return view('numerosis::livewire.settings.data', [
            'requests' => $this->requests(),
        ]);
    }

    /**
     * The screen is central-only, and the action reads relations only a
     * `CentralUser` has.
     */
    private function centralUser(): CentralUser
    {
        $user = $this->authenticatedUser();

        abort_unless($user instanceof CentralUser, 403);

        return $user;
    }

    /**
     * @return LengthAwarePaginator<int, DataExportRequest>
     */
    private function requests(): LengthAwarePaginator
    {
        return Numerosis::model(DataExportRequest::class)::query()
            ->forSubject((string) $this->centralUser()->global_id)
            ->orderByDesc('id')
            ->paginate(10);
    }
}
