@use(Nvade\Numerosis\Actions\Queries\GetCurrentImpersonation)

@php($impersonation = GetCurrentImpersonation::run())

@if ($impersonation)
    <div class="sticky top-0 z-(--z-toast) flex flex-wrap items-center justify-between gap-2 bg-warning-bg px-4 py-2 text-sm text-warning-text"
         role="status"
    >
        <span>
            {{ __('numerosis::impersonation.banner', [
                'staff' => $impersonation->staff?->email ?? $impersonation->staff_global_id,
                'target' => $impersonation->target_global_id,
            ]) }}
        </span>

        {{-- `url()->current()`-relative, never `route()`: the tenant group is
             prefixed `{tenant}` in path mode with no default registered. --}}
        <form method="POST" action="{{ $impersonation->tenant?->baseUrl() }}/impersonate/exit">
            @csrf
            <flux:button type="submit" size="sm" variant="primary">
                {{ __('numerosis::impersonation.exit') }}
            </flux:button>
        </form>
    </div>
@endif
