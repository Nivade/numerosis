---
paths:
  - 'src/Contracts/**'
  - 'src/Services/**'
  - 'src/Actions/**'
  - 'src/Data/**'
---
# Architecture Conventions

Recorded by `/infer-conventions`. Describes the house pattern, not a
recommendation — match it, don't improve on it.

- **Data-access and cross-cutting capabilities go behind a `Contracts`
  interface, bound to a `Services` implementation in
  `NumerosisServiceProvider::register()`.** Both live under the same
  `<Domain>/` subfolder (`src/Contracts/Billing/*`, `src/Services/Billing/*`).
  Interfaces are named by capability (`Resolves*`, `Creates*`, `Notifies*`,
  `*Repository`, `*Gateway`, `*Policy`); concrete classes are named by
  mechanism (`Eloquent*`, `Config*`, `Local*`, `Stancl*`, `*Directly`).
  Consumers type-hint the interface and resolve through the container —
  never reference the concrete class directly. Add new capabilities the same
  way: interface in `Contracts/<Domain>`, implementation in
  `Services/<Domain>`, bind in `NumerosisServiceProvider`.

- **Business logic goes in `AsAction` classes under `src/Actions/**`,
  invoked via `handle()`.** No `__invoke()`/`execute()` alternates. This
  matches `lorisleiva/laravel-actions`'s own default, so it's mostly a
  reminder not to introduce a second style.

- **Cross-boundary data (config-to-service, action input/output) is a
  `spatie/laravel-data` `Data` object under `src/Data/**`, not a plain
  readonly class or associative array.** Also mostly the installed
  package's own default — noted so a plain readonly DTO doesn't get
  introduced as a second style.
