---
name: filamentphp-development
description: Develops, reviews, and troubleshoots Filament v5 code for Laravel apps. Use when the task involves Filament Panels, Resources, Forms, Tables, Infolists, Schema Layouts, Notifications, Widgets, Actions, relation managers, nested resources, policies, theming, plugins, multi-tenancy, testing, or deployment.
---

# Developing FilamentPHP v5

Skill for **Filament v5** development in Laravel applications.

**Trigger when the task involves:**
- Filament Panels, Resources, Pages
- Forms, Tables, Infolists
- Schema Layouts (Section, Grid, Tabs, Wizard, Fieldset)
- Actions (modals, confirmation, import/export)
- Widgets (stats, charts, table widgets)
- Notifications (flash, database, broadcast)
- Relationships (relation managers, nested resources, repeaters)
- Authorization, theming, plugins, multi-tenancy
- Testing and deployment

**Do not use for:**
- Filament v3/v4 (unless explicit migration guidance is requested)
- Generic Laravel without Filament-specific components
- Frontend-only tasks unrelated to Filament or Livewire

---

## Reference files

Detailed API guidance, code examples, and conventions for each Filament domain:

- **Resources**: See [references/resources.md](references/resources.md) — CRUD interfaces, resource pages, soft deletes, authorization, Eloquent query customization
- **Tables**: See [references/tables.md](references/tables.md) — columns, filters, sorting, searching, bulk actions, pagination, grouping, summaries
- **Actions**: See [references/actions.md](references/actions.md) — modals, confirmation, modal forms, wizard steps, import/export, lifecycle hooks, rate limiting
- **Forms**: See [references/forms.md](references/forms.md) — field types, validation, reactivity, state management, dependent fields, repeaters, builders
- **Infolists**: See [references/infolists.md](references/infolists.md) — entry types, formatting, read-only display, layout, actions on entries
- **Schema Layouts**: See [references/schema-layouts.md](references/schema-layouts.md) — Grid, Section, Tabs, Wizard, Fieldset, Flex, Callout, container queries, responsive breakpoints
- **Notifications**: See [references/notifications.md](references/notifications.md) — flash notifications, database notifications, broadcast notifications, actions in notifications
- **Widgets**: See [references/widgets.md](references/widgets.md) — stats overview, chart widgets, table widgets, custom widgets, filtering, placement
- **Panel Configuration**: See [references/panel-configuration.md](references/panel-configuration.md) — auth, navigation, colors, theming, plugins, multi-tenancy, SPA mode, deployment

---

## Operating principles

- Assume **Filament v5** unless the user states otherwise.
- Prefer **idiomatic Filament APIs** over custom Blade or ad hoc Livewire code.
- Prefer **built-in Filament primitives** before suggesting custom abstractions.
- Do not invent APIs, methods, commands, or component names.
- If a detail is missing, mark it as **UNSPECIFIED** instead of guessing.
- For fragile tasks, reduce freedom and give exact steps.
- For design/architecture tasks, allow more flexibility but stay within Filament conventions.
- Keep outputs concise, practical, and implementation-ready.
- When reviewing code, prioritize correctness, authorization, maintainability, and framework fit.
- When generating code, match the project's apparent structure and naming patterns.

---

## Required workflow

### 1. Identify the Filament surface area

| Surface area        | When to use                                                    |
|---------------------|----------------------------------------------------------------|
| **Panel**           | Top-level admin/app shell, auth, navigation, theming, plugins  |
| **Resource**        | CRUD interface for an Eloquent model                           |
| **Custom Page**     | Settings, dashboards, tools, non-standard interfaces           |
| **Form**            | Input UI, reactive state, schema composition                   |
| **Table**           | List UI, filters, columns, record/bulk actions                 |
| **Infolist**        | Read-only presentation UI                                      |
| **Widget**          | Dashboard metrics, charts, stats, or custom cards              |
| **Action**          | Button-driven workflow, modal, form, side effect               |
| **Relation manager**| Manage related records from a parent page                      |
| **Schema Layout**   | Organize fields/entries into sections, grids, tabs             |
| **Notification**    | Flash messages, database notifications, broadcast              |

### 2. Gather minimum required context

Before writing code, determine:

- Filament version
- Laravel version if relevant
- Panel ID/path if panels are involved
- Model class names
- Model fields, casts, and relationships
- Whether soft deletes are used
- Whether import/export is required
- Whether multi-tenancy is involved
- Authorization expectations
- Desired output: scaffolding, full class, partial, review, tests, or deployment steps

If details are missing, state them as **UNSPECIFIED** and either ask targeted questions (max 5) or provide safe defaults with labeled assumptions.

### 3. Choose the correct Filament primitive

- CRUD for an Eloquent model → **Resource** (see [resources.md](references/resources.md))
- Read-only record detail → **View page + Infolist** (see [infolists.md](references/infolists.md))
- Editable workflow in modal/button → **Action** (see [actions.md](references/actions.md))
- Data list with filters/sorting/bulk → **Table** (see [tables.md](references/tables.md))
- Dashboard metric or chart → **Widget** (see [widgets.md](references/widgets.md))
- Manage related records from parent → **Relation manager**
- Full CRUD for related model → **Nested resource**
- Inline related-record editing → **Repeater** (see [forms.md](references/forms.md))
- App shell changes → **Panel configuration** (see [panel-configuration.md](references/panel-configuration.md))
- Access control → **Laravel Policy** first, then Filament visibility helpers

Do not default to custom Livewire if a Filament component already solves the problem.

### 4. Produce output

**Standard build format:**
1. Summary (what, assumptions, UNSPECIFIED details)
2. Scaffolding commands
3. Implementation (classes, methods, focused code)
4. Authorization (policy implications)
5. Testing (3–6 concrete tests)
6. Pitfalls (short list)

**Standard review format:**
1. What the code does → 2. What is incorrect/risky → 3. What is unidiomatic → 4. Suggested rewrite → 5. Why

**Standard debugging format:**
1. Likely cause → 2. How to verify → 3. Minimal fix → 4. Long-term fix → 5. Regression tests

---

## Key v5 conventions

### Namespace changes in v5

| v5 namespace                           | Purpose                          |
|----------------------------------------|----------------------------------|
| `Filament\Schemas\Components\Section`  | Layout section                   |
| `Filament\Schemas\Components\Grid`     | Grid layout                      |
| `Filament\Schemas\Components\Tabs`     | Tab layout                       |
| `Filament\Schemas\Components\Flex`     | Flex layout (new)                |
| `Filament\Schemas\Components\Callout`  | Callout component (new)          |
| `Filament\Schemas\Components\Utilities\Get` | State getter utility        |
| `Filament\Schemas\Schema`             | Schema class                      |
| `Filament\Actions\*`                  | All action classes (unified)      |

### Table API changes in v5

- `->recordActions([])` replaces `->actions([])`
- `->toolbarActions([])` for header/bulk actions
- `->headerActions([])` for table header actions
- Bulk actions go inside `BulkActionGroup::make([])` within `toolbarActions`

### General conventions

- Prefer declarative PHP configuration.
- Use Laravel policies for authorization. Visibility helpers are UX-only.
- Use `live()` sparingly — only when reactivity is needed.
- Hidden navigation ≠ denied access. Hidden buttons ≠ policy checks.
- Import/export requires queue batches table + notifications table.

---

## Commands

### Filament commands

```
php artisan filament:install --panels
php artisan make:filament-user
php artisan make:filament-panel
php artisan make:filament-resource
php artisan make:filament-page
php artisan make:filament-widget
php artisan make:filament-relation-manager
php artisan make:filament-theme
php artisan make:filament-importer
php artisan make:filament-exporter
php artisan filament:optimize
php artisan filament:optimize-clear
```

### Related Laravel commands

```
php artisan make:policy
php artisan make:queue-batches-table
php artisan make:notifications-table
php artisan migrate
```

Only suggest commands relevant to the request.

---

## Error-handling rules

- **Never guess APIs** — say UNSPECIFIED, avoid fake method names
- **Never treat UI hiding as security** — recommend policy/access control for actual protection
- **Never over-engineer** — no custom service layers, traits, or macros unless clearly justified
- **Never ignore import/export prerequisites** — queue batch + notification table migrations required
- **Never ignore tenancy risk** — require deliberate tenant access rules, no hand-waving data isolation

---

## Response quality rules

- Prefer precise class and method names over generic prose.
- Prefer short explanations followed by usable code.
- Prefer complete minimal examples over disconnected fragments.
- Do not restate obvious Laravel/PHP basics unless directly relevant.
- Architecture advice: compare 2–3 valid options and recommend one.
- Code generation: keep namespaces, imports, and file boundaries clear.
- Debugging: prioritize the most likely root cause first.
- Refactoring: preserve behavior unless the user explicitly wants redesign.

---

## When this Skill is active

Behave like a **Filament v5 specialist**: choose the correct primitive, generate idiomatic code, protect authorization boundaries, keep solutions maintainable, avoid speculation, and provide implementation-ready output with tests and pitfalls for substantial tasks.