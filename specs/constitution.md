# Project Constitution

These rules apply to every spec, design decision, and line of code in this project. If a later document conflicts with this one, this document wins unless it is explicitly amended.

## 1. Stack (non-negotiable)

- Laravel 12 (pinned to `^12`, even though Laravel 13 is available)
- Filament 5
- MySQL 8.x, used for development **and** for the test suite. Locking and concurrency behaviour must be proven against a real MySQL database, not SQLite.
- PHP 8.3+ with typed properties, enums, and `readonly` where appropriate.

## 2. Forbidden

- Ready-made Round Robin or lead-distribution packages.
- Ready-made audit-log packages (for example `spatie/laravel-activitylog` or `owen-it/laravel-auditing`).
- Business logic inside Filament Resources, Pages, Actions, or closures. Filament only collects input, calls the application layer, and shows the result.

## 3. Architecture principles

1. **Application layer first.** Each business operation (create lead, assign lead, bulk-assign, change status, escalate) is a class that can be called from Filament, an API controller, an Artisan command, or a test.
2. **The database guarantees invariants.** Rules that must never break (no double assignment, no duplicate escalation, capacity limits) are enforced by transactions, row locks, atomic updates, and unique constraints, not only by `if` checks in PHP.
3. **Authorization is enforced on the server.** Every read path is scoped by query and every write path is checked by a policy. Hiding UI controls is never the only protection.
4. **Jobs are idempotent.** Every queued job re-reads the current state, assumes it may run more than once, and relies on a database constraint to stop duplicate side effects.
5. **Explicit over magic.** Use clear service and action classes, PHP enums, and named query scopes. Model events are used only where the behaviour is truly cross-cutting, such as the status audit.

## 4. OOP & SOLID rules

| Principle | How it is applied in this codebase |
|---|---|
| **S**ingle Responsibility | One class does one job: an Action runs one use case, a Strategy picks an agent, a Ledger tracks capacity, a Rule decides visibility. Controllers, Filament classes and jobs only translate input and output. |
| **O**pen/Closed | Behaviour that is likely to change sits behind an interface: assignment strategy (plain vs weighted Round Robin), escalation recipient resolver, per-role visibility rules, webhook event types. New behaviour = new class, not edits to existing ones. |
| **L**iskov Substitution | Every interface implementation keeps the same contract (inputs, outputs, exceptions), so tests can swap in fakes and production can swap strategies. |
| **I**nterface Segregation | Small, focused interfaces (`AssignmentStrategy`, `EscalationRecipientResolver`, `LeadVisibilityRule`, `WebhookSigner`) instead of one large "LeadService" contract. |
| **D**ependency Inversion | High-level use cases depend on interfaces bound in a service provider and are constructor-injected. No `new` for collaborators and no static facades inside domain classes when an injectable contract exists. |

Additional OOP rules:
- Use `final` classes by default and `readonly` DTOs/value objects for data passed between layers.
- Use backed enums for every closed set of values, with behaviour on the enum (for example `LeadStatus::isOpen()`).
- Use domain exceptions with clear names (for example `AgentCapacityExceeded`) instead of generic exceptions or boolean return codes.
- Prefer composition over inheritance. No "god" base classes.

## 5. Performance rules

- Every query on the leads table must use an index. Check with `EXPLAIN` when in doubt.
- Lazy loading is disabled outside production (`Model::preventLazyLoading()`), so an N+1 fails loudly in dev and in tests.
- Select only needed columns for relations (for example `assignedUser:id,name`).
- Never call `->get()` / `->all()` on an unbounded query. Use pagination, `chunkById()` or `lazyById()`.
- Avoid `COUNT(*)` on hot paths. Capacity uses a counter table, not a count over leads.
- Slow work (escalation, webhooks, notifications) runs on the queue, never in the request.
- Production configuration caching (config, routes, events, views, Filament components) and OPcache are documented in the README.

## 6. Quality gates

- Every acceptance criterion in `requirements.md` maps to at least one automated test.
- At least one test proves correctness under real concurrent execution, using parallel processes against MySQL.
- No N+1 queries on the Filament lead list. A test or measurement shows the query count.
- Every index has a written justification in the technical note.

## 7. Workflow (Spec-Driven Development)

1. `requirements.md`: what and why. Requires approval before step 2.
2. `design.md`: how (schema, classes, locking strategy, and so on). Requires approval before step 3.
3. `tasks.md`: ordered, small, testable implementation tasks, each linked to requirement IDs.
4. Implementation: one task at a time, with tests written alongside the code.
5. If the implementation shows that a spec is wrong, update the spec first, then the code.
