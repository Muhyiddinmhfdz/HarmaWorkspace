# HarmaWorkspace Project Guidelines

HarmaWorkspace is the internal work management system for HARMA DIGITAL (modular monolith: Laravel 13, MySQL, Livewire 4, Flux UI free, Fortify, Spatie Permission + Activitylog). The architecture blueprint is `docs/phase-0-blueprint.md`; the implemented foundation, conventions and ADRs are in `docs/phase-1-foundation.md` and `docs/phase-2-project-management.md`. Read them before building a module.

## Phase discipline
- Work only on the current phase in `docs/phase-0-blueprint.md` §21. Do not build Project/Task/Ticket/Workflow/Approval/Gantt features ahead of their phase.
- `config/navigation.php` lists only modules that exist; add a module's entry in its own phase. Never create placeholder pages just to make a link work.

## Architecture
- Business logic lives in `app/Domain/<Domain>/Actions` (and Queries/Data/Rules). Livewire components and controllers only validate, authorize, call an Action, and render.
- Validation rules for an entity are defined once in `app/Domain/<Domain>/Rules` and reused by Form Requests and Livewire Forms.
- Actions that write to more than one table run inside `DB::transaction()`.
- Eloquent models stay in `app/Models`, policies in `app/Policies`.
- Pages are Livewire single-file components in `resources/views/pages/**` (no ⚡ prefix), routed with `Route::livewire()`. Shared Livewire behaviour lives in `app/Livewire/Concerns`: list pages use `WithDataTable` (server-side search/sort/filter/pagination); every page with actions uses `WithFeedback`.
- `WithFeedback` sanitizes validated input (`App\Domain\Shared\InputSanitizer`), catches every exception, and reports outcomes as SweetAlert2 toasts. Report success with `$this->notify('success', '...')` (or `notifyAfterRedirect()` before a redirect); never use `Flux::toast`. Do not add try/catch blocks inside pages for user feedback — let exceptions reach the hook.
- Domain rule violations throw `App\Domain\Shared\Exceptions\BusinessRuleException` with an Indonesian, user-safe message.
- Cross-module side effects (notifications, progress recalculation) use events, not direct calls upward.

## Master-driven data (non-negotiable)
- Never hardcode business statuses, priorities, departments, types, or categories (no `status_id == 3`, no `department->name === 'Programmer'`, no `priority === 'High'`).
- Logic uses master `code`, `level`, or semantic flags (`is_initial`, `is_final`, `is_closed`, `counts_as_completed`).
- PHP enums are only for technical values listed in blueprint §9.3.
- Masters used by transactions are deactivated/archived, never hard-deleted. A master's `code` is read-only once it is referenced.
- `statuses` and `priorities` are scoped tables (`scope` = project/task/ticket); resolve them through `App\Domain\MasterData\ScopedMasterGuard` in every write path.
- New configurable masters are added as a `MasterDefinition` in `App\Domain\MasterData\MasterRegistry` (generic page `/master-data/{type}`), not as a new page.
- Document numbers come from `App\Domain\System\Numbering\NumberGenerator` (row-locked `number_sequences`), never `max(id) + 1`.

## Authorization
- Check permissions (`$user->can('ticket.assign')`) or policies — never role names. The only exception is the Super Admin `Gate::before` in `AppServiceProvider`, using `config('harma.super_admin_role')`.
- Permissions are defined in `App\Domain\Access\PermissionRegistry` and synced by the system seeder; naming is `<module>.<action>`, `.view-all` bypasses resource scope.
- Every model with restricted visibility has `scopeVisibleTo(User $user)`; its policy `view()` uses the same logic. Lists, search, exports, and APIs must use the scope.
- Re-authorize inside every Livewire action; mark ID properties `#[Locked]`.

## History & audit
- Assignment, schedule, status, transfer, and approval decision rows are append-only. Reassigning closes the old row (`unassigned_at`) and inserts a new one.
- Schedule columns (`planned_*`, `current_*`, `due_at`) change only through the `ChangeSchedule` action, which writes `schedule_changes` with a reason. They are not mass-assignable.
- Project state changes only through `app/Domain/Project/Actions` (status, completion, archive, schedule, progress, health, owner/PM, departments, members, milestones). Project Manager, Owner and department leads are contextual (columns/records), never Spatie roles; `ProjectPolicy` combines visibility with permission or contextual role.
- Project visibility has one source: `Project::scopeVisibleTo()` / `isVisibleTo()`. Lists, counts, search and policies must use it.
- Project visibility (`restricted` default / `department` / `company`) is independent from the client relation and only controls viewing. It changes only via `ChangeProjectVisibility`; `company` requires `project.manage-visibility`.
- Project status transitions are validated by `App\Domain\Project\Support\ProjectLifecycle` (by status code/flags, never names). Show available actions, not a free status dropdown. Completion = `CompleteProject`, archive = `ArchiveProject`.
- Contextual Project Manager rights never cover administrative actions (archive, company visibility, master data). Confirm sensitive actions with `hwConfirm()` (SweetAlert2), never the browser `confirm()` or `wire:confirm`.
- Important changes are recorded through `App\Domain\Audit\AuditLogger` with a semantic event code (`user.deactivated`, `role.updated`, ...). Never log passwords, tokens, or 2FA secrets.

## Database
- MySQL 8, InnoDB, utf8mb4. Foreign keys on transactional relations (`restrict` by default); log tables have no FKs.
- Polymorphic tables (comments, attachments, activity_logs, schedule_changes) use `Relation::enforceMorphMap()` aliases.
- Add indexes only for real query patterns (blueprint §7.7).
- Seeders: `database/seeders/System` (production-safe, idempotent) vs `database/seeders/Development` (demo data only). Never mix them.

## UI/UX
- Clean modern SaaS: neutral palette + one accent; color only for meaning (status, priority, danger).
- Reuse `resources/views/components/ui/*`; list pages follow the standard pattern (search, filters, sortable table, pagination via `#[Url]`, empty state with action).
- Simple masters use a modal/drawer form; complex entities (user, project, ticket) use full pages.
- Menu and domain labels are English; toasts, empty states, confirmations and validation messages are Bahasa Indonesia.
- Never show technical errors to users; log them.
- No fake statistics on dashboards; placeholder widgets explain what will appear.

## Testing
- Every feature ships with PHPUnit feature tests (`php artisan make:test --phpunit`) covering the happy path, validation, unauthorized access (guest + missing permission), and deactivated users where relevant.
