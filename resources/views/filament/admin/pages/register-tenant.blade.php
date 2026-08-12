{{--
    This wrapping <div> is load-bearing, not decorative. When this file was
    a bare `@livewire('tenant-registration')` (this Filament page's entire
    render output was that one directive, nothing else), Livewire collapsed
    the wizard's own component boundary into this page's — the rendered DOM
    carried only two wire:id roots (this page, and whichever step was
    current), never a third for `tenant-registration` itself. Confirmed live,
    2026-08-12: every `StepComponent::nextStep()`/`previousStep()`/`showStep()`
    call dispatches its event `->to($wizardClassName)`, and with no live
    component actually registered under that name, the event had nowhere to
    land — no exception, no validation error, the request round-tripped
    successfully, and the wizard silently never advanced past step one. See
    the matching fix note on Registration::getCurrentStepState() for the
    other half of this bug (wizardClassName itself was also wrong, pointing
    at the raw FQCN instead of the `tenant-registration` alias
    `RegistrationWizardFeature` actually registers it under) — both were
    required together; either alone still left the wizard stuck.
--}}
<div>
    @livewire('tenant-registration')
</div>
