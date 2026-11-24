# Technical Note

This note covers the assignment strategy, concurrency protection, authorization, queue idempotency and performance, then the assumptions and answers to the discussion questions. Full reasoning is in [`specs/lead-management/design.md`](../specs/lead-management/design.md). Requirement IDs (R x.y) refer to [`requirements.md`](../specs/lead-management/requirements.md).

## 1. Architecture in one picture

```
Filament / REST API / Inbound webhooks / Artisan   ← thin: validate → DTO → Action → output
                    │
Actions: CreateLead · ChangeLeadStatus · UpdateLead · BulkAssignLeads · EscalateLead
                    │
LeadAssigner → AssignmentStrategy (interface) · CapacityLedger · LeadVisibility → rules per role
EscalationRecipientResolver (interface) · WebhookSigner (interface) · OutboundUrlGuard
                    │
Eloquent + LeadBuilder scopes + MySQL (row locks, atomic UPDATEs, UNIQUE constraints)
                    │
Domain events (after commit) → queued listener → signed webhook deliveries
```

- **Business logic never lives in Filament or controllers.** The admin panel, the API and the inbound webhook all call the same Actions. Pest `arch()` tests fail the build if Filament or a controller uses the `DB` facade, or if the domain depends on a delivery layer.
- **Architecture style: pragmatic Clean Architecture (ports & adapters).** The dependency rule is enforced by tests: use cases and domain services never use queues, notifications, Facades, HTTP or Filament. They announce domain events and talk to output ports (`EscalationNotifier`, `AssignmentStrategy`, `LeadVisibilityRule`, `WebhookSigner`, `ClockInterface`), while the adapters live in infrastructure (`DatabaseEscalationNotifier`, the `ScheduleLeadEscalation` listener, `RoundRobinStrategy`). Inner layers get settings as a value object (`LeadSettings`) and never call `app()` / `config()`; a token-scanning test fails on any service-locator call. **Deliberate, documented trade-offs:** entities are Eloquent models (Active Record) and use cases rely on the database for locking, because correctness here *is* a database property (row locks, atomic updates, unique constraints). `User` implements `FilamentUser` because Filament requires it for panel access in production. Wrapping these in repositories and mappers would add a lot of code without making the concurrency guarantees any stronger.
- **SOLID, concretely.** Interfaces at every point that is likely to change (strategy, visibility rule, recipient resolver, signer, current actor). They are bound in `AppServiceProvider` and constructor-injected. Actions and services are `final`; DTOs are `final readonly`. See design §16.

## 2. Assignment strategy and concurrency protection (R3)

**Strategy:** Round Robin over active sales agents ordered by id. The rotation state is one row, `assignment_cursors(pool='global', last_user_id)`. Daily usage is a counter table, `agent_daily_loads(user_id, load_date, assigned_count)`, keyed by the business day in `Africa/Cairo`.

**Algorithm** (inside `CreateLead`'s transaction):

1. `SELECT … FROM assignment_cursors WHERE pool='global' FOR UPDATE`. This serializes every automatic assignment.
2. Read eligible agents (active, `assigned_count < daily_capacity`), ordered so that ids after the cursor come first, then wrap around.
3. For each candidate, call `CapacityLedger::tryReserve()`:
   ```sql
   UPDATE agent_daily_loads l JOIN users u ON u.id = l.user_id
   SET l.assigned_count = l.assigned_count + 1
   WHERE l.user_id = ? AND l.load_date = ? AND u.is_active = 1 AND u.role = 'sales_agent'
     AND l.assigned_count + 1 <= u.daily_capacity
   ```
   One affected row means the slot is reserved. Zero means the agent is full or inactive, so try the next candidate.
4. Insert the lead with `assigned_to`, then move the cursor. Commit.

**Why it is safe:**

- **Same turn twice is impossible.** A second request blocks on `FOR UPDATE` until the first commits, then reads the cursor the first one moved.
- **Capacity can never be exceeded**, even by bulk assignment, which does not take the cursor lock. The check and the increment are one `UPDATE`, and InnoDB evaluates its `WHERE` against the latest committed row under an exclusive row lock (a current read, not a snapshot).
- **No half-assigned lead.** The reservation, the lead row and the cursor move commit or roll back together. If an inbound webhook duplicate hits the `UNIQUE(source, external_ref)` constraint, the rollback also returns the capacity slot.
- **No deadlocks by design.** The lock order is always cursor → ledger → lead (bulk: leads in id order → ledger). `DB::transaction(..., attempts: 3)` retries if MySQL still picks a deadlock victim.
- **Throughput.** The cursor lock is held for about 4 short indexed statements (a few ms). The `pool` column allows per-team cursors if it ever becomes a bottleneck.

**Proof (tests/Concurrency, real parallel PHP processes on MySQL):**

| Test | Result |
|---|---|
| 8 processes create 200 leads at once, 5 agents with capacity 40 | Each agent gets **exactly 40**. In id order the assignees form a **perfect repeating cycle**. The ledger equals the real counts. The inactive agent gets 0. |
| Same, total capacity 150 | **Exactly 150 assigned, 50 unassigned**, every agent exactly at capacity |
| 4 auto-creators + 2 bulk-assigners racing on one agent (capacity 20, demand around 60) | The agent ends with **exactly 20** |
| Connection A holds the cursor lock | Connection B gets `ER_LOCK_WAIT_TIMEOUT (1205)` |
| 4 processes run the escalation for the same lead 3× each | **1** escalation row, **1** notification |

**Negative control.** To show the tests really detect races, the protection was removed temporarily. Without `FOR UPDATE` on the cursor, the Round Robin test failed: the cycle broke. With the capacity check rewritten as read-then-write, the race test failed with **22 leads for a capacity of 20**. Both were restored. The suite passed 5 times in a row.

**Alternatives rejected:** a Redis lock (not tied to the DB transaction, can expire mid-transaction), MySQL `GET_LOCK()` (session-scoped, must be released manually), `FOR UPDATE` on all agent rows (many locks, deadlock-prone), and optimistic locking (retry storms under bursts).

## 3. Authorization approach (R6)

Two server-side layers that share one rule per role:

1. **Query scope:** `Lead::query()->visibleTo($user)` is applied to the Filament resource (`getEloquentQuery()`), API listing, API route binding for `{lead}`, and bulk assignment. A direct URL to someone else's lead is a **404** in both the admin panel and the API, which also hides whether the record exists.
2. **Policies:** `LeadPolicy` (view/update/changeStatus/assign/delete) is checked by Filament, by controllers, **and again inside the Actions**, so a future caller cannot skip it.

`LeadVisibilityRule` implementations (Admin / Manager / Agent) each provide `apply()` (set level) and `allows()` (record level) in the same class, so the list and the policy can never disagree. The resolver **fails closed**: inactive users and managers without a team see nothing. Manual assignment uses `AssigneeEligibility`: admins can assign to any active agent; managers only to active agents in their own team, and only leads they can see. The same query feeds the assignee dropdown, so the UI and the rule cannot drift. Inactive users cannot sign in (`canAccessPanel`), and their existing API tokens are rejected (`EnsureUserIsActive`).

## 4. Queue idempotency strategy (R5)

`EscalateStaleLead` is dispatched with a 30-minute delay, after commit. It carries only the lead id. `EscalateLead`:

- reloads the lead **under `FOR UPDATE`**. `ChangeLeadStatus` takes the same lock, so a status change and an escalation cannot interleave;
- does nothing if the lead is gone, not `new` any more, or not yet due. "Due" means 30 minutes after the lead **last entered** New (read from the audit log), so New → Contacted → New restarts the clock;
- `insertOrIgnore` into `lead_escalations`, which has **`UNIQUE(lead_id)`**. If it inserted 0 rows, the lead was already escalated, so it stops **before** any side effect;
- writes the database notification **in the same transaction**, so "escalated" and "notified" commit together. Any failure rolls both back, and the retry starts clean;
- never modifies the lead.

`ShouldBeUnique` only avoids enqueueing duplicates; the database constraint is the real guarantee. The unique lock is keyed **per origin**: the scheduled copy holds a long lock (it must outlive the 30-minute delay), while sweeper copies use their own 5-minute lock. Otherwise the lock of a *lost* delayed job would silently block the sweeper's replacement for up to an hour, which is exactly the case the sweeper exists for. This was found by the end-to-end scenarios and is covered by a regression test. A scheduled **sweeper** (`leads:escalate-stale`, every minute) re-dispatches due, un-escalated leads. Delayed jobs can be lost (queue flushed, tries exhausted), and some drivers cap delays (**Amazon SQS: 15 minutes maximum**). The sweeper is safe because the job is idempotent.

Outbound webhooks follow the same rules: events dispatch **after commit**; `UNIQUE(subscription_id, event_id)` means a retried listener never creates a second delivery; a delivered row is never re-sent; failures retry with backoff 10 s → 30 min. Delivery is at-least-once, so receivers de-duplicate on `X-Webhook-Id`.

## 5. Performance decisions (R8)

### Indexes on `leads`

| Index | Why |
|---|---|
| `PRIMARY (id)` | Default sort `id DESC` reads the clustered index backwards; cursor pagination in the API |
| `(assigned_to, status)` | Agent/manager scopes, My Leads, Unassigned (`IS NULL`), status within a scope; backs the FK |
| `(next_follow_up_at)` | Overdue filter; sorting by follow-up |
| `(status, created_at)` | Escalation sweeper; admin status filter |
| `(created_at)` | Dashboard date ranges. Added after measuring: "new today" 48 ms → **0.6 ms**, 14-day series 149 ms → **16 ms** |
| `(priority)` | High Priority filter (InnoDB appends the PK, so `ORDER BY id` needs no filesort) |
| `UNIQUE (source, external_ref)` | Inbound webhook de-duplication |
| `(phone)` | Phone search as a **prefix** match |
| `FULLTEXT (name)` | Name search without a `LIKE '%…%'` full scan |

Capacity never counts leads: it is a primary-key lookup on `agent_daily_loads`.

**Tried and rejected, with numbers:** `(status, next_follow_up_at)` made the admin "Overdue" `COUNT(*)` index-only, but only went from 157 ms to 116 ms, because about 250k rows match in the synthetic data. That is not worth an extra index on a write-heavy table. If total counts ever become a problem, Filament's `PaginationMode::Simple` removes the `COUNT(*)` entirely.

### Eager loading and memory

- Lead list: `select()` of the displayed columns plus `with('assignedUser:id,name')` → **3 queries per page** regardless of page size. "Time until follow-up" is computed in PHP from a loaded column.
- Status history: loaded only on the lead's view page, paginated, with `user:id,name`.
- The bulk action receives **record keys only** (`fetchSelectedRecords(false)`), capped at 500.
- The sweeper uses `chunkById(500)`. The API uses cursor pagination (no deep `OFFSET`).
- Global search and navigation count badges are disabled for leads (they would query on every page load).

### How it was verified

1. **Query-count tests** (`LeadListQueryCountTest`, API test): rendering 5 rows and 50 rows must run the **same** number of queries. `Model::preventLazyLoading()` is on outside production, so any N+1 throws in tests.
2. **500,060 leads** (`LargeLeadDatasetSeeder`, 28 s) + `php artisan leads:benchmark`, MySQL 8.4 on a laptop, median of 7 runs:

| Query | Page of 25 | COUNT(*) | EXPLAIN |
|---|---|---|---|
| Admin: latest 25 | 0.2 ms | 27.8 ms | PRIMARY, 25 rows |
| Admin: Unassigned | 0.3 ms | 3.4 ms | PRIMARY |
| Admin: Overdue | 0.7 ms | 159 ms | PRIMARY (≈250k matching rows) |
| Admin: High Priority | 0.7 ms | 10.7 ms | `idx_leads_priority` |
| Admin: search phone prefix | 1.6 ms | 0.3 ms | range on `idx_leads_phone` |
| Admin: search name | 188 ms | 76 ms | `ft_leads_name` (only 8 distinct names in the seed → about 62k matches; real data is far more selective) |
| Manager: team leads | 0.4 ms | 19.1 ms | semi-join, `teams_manager_id_unique` → PRIMARY |
| Agent: My Leads | 0.7 ms | 3.1 ms | PRIMARY |
| Sweeper: due new leads | 0.5 ms | 26.4 ms | PRIMARY + `lead_escalations` unique |

   The Filament lead page for an admin at 500k rows runs **3 queries: COUNT 22.6 ms, page 0.4 ms, assignees 0.3 ms**.
3. **Inspecting slow or excessive queries:** each query ≥ 200 ms is logged (`DB::listen`), as is any request whose total query time exceeds 1 s (`DB::whenQueryingForLongerThan`). docker-compose enables the MySQL slow query log (`long_query_time = 0.2`). `EXPLAIN` / `EXPLAIN ANALYZE` (as in `leads:benchmark`) checks the plans. Laravel Debugbar or Telescope can be added in development for per-request query lists.

### Dashboards

`LeadDashboardStats` feeds both the Filament widgets and `GET /api/v1/dashboard/stats`. Every metric starts from `Lead::visibleTo($user)` (the same rules as the lists) and is one indexed aggregate query. Results are cached for 60 s per visibility scope (`admin`, `sales_manager:{id}`, `sales_agent:{id}`), so users with different visibility never share an entry. The KPI summary deliberately runs **separate COUNTs** that each use their own index instead of one `CASE WHEN` aggregate scanning the table: 386 ms → 234 ms cold for an admin on 500k rows. Most of the remaining time is the "overdue" count, because about 250k synthetic leads are overdue. The "needs attention" table is a single `LIMIT 10` query without pagination, so Filament runs no `COUNT(*)` for it.

## 6. Assumptions (gaps in the brief)

Full list in requirements.md §1. The main ones:

- **Only active sales agents** receive leads automatically, in one company-wide rotation.
- Daily capacity counts leads **received** that business day, including manual or bulk assignment. Reassigning a lead away does not refund the capacity.
- With no eligible agent, the lead is created **unassigned** (visible to admins through the "Unassigned" filter). It is never lost.
- The responsible manager is the manager of the assigned agent's team. If there is none, the escalation goes to all active admins.
- Bulk assignment is **all-or-nothing** with a clear error that includes the remaining capacity.

## 7. Discussion points

- **Two requests reach the assignment service at the same time?** The second waits on the cursor row lock, then continues from the moved cursor; capacity is re-checked atomically. Proven by the 8-process test and the lock-timeout test.
- **A decision with several valid approaches:** (a) pessimistic single-row lock vs Redis lock vs optimistic retries (§2). (b) Status audit in a model observer, so no write path can forget it, vs explicit logging in each Action. I chose the observer and made it atomic by having every Action own the transaction. The known limit (query-builder mass updates skip events) is handled by having only `ChangeLeadStatus` change status.
- **Senior agents get twice the volume?** Add `WeightedRoundRobinStrategy implements AssignmentStrategy` (smooth weighted round robin over `users.assignment_weight`, which already exists) and switch `LEADS_ASSIGNMENT_STRATEGY`. `LeadAssigner`, the ledger and the locking do not change (Open/Closed).
- **An agent moves to another team?** Visibility is computed from **current** `team_id`, so their leads become visible to the new manager immediately and stop being visible to the old one, with no data migration. The trade-off is that the old manager loses history visibility. If that matters, snapshot `team_id` on the lead at assignment time and scope managers by it.

## 8. Frontend

- **Filament:** branded panel, SPA navigation, role-aware dashboard widgets, and a database-notification bell. Escalations are stored in Filament's notification format (built with Filament's own builder) plus plain domain keys for API consumers.
- **Angular 22 Agent Workspace** at `/workspace`: standalone components, signals, zoneless change detection, functional interceptors (bearer token + one typed `ApiError` from the server's error envelope, logout on 401) and functional guards. Lazy-loaded pages, 88 KB initial transfer. The token is kept in `sessionStorage`. Angular sanitizes bindings by default, and no `bypassSecurityTrust*` is used.
- **Serving:** the URL `/workspace/*` is always handled by Laravel, which returns the shell, while the built files live in `public/workspace-assets` (Angular `deployUrl`). Keeping the URL path and the directory name different avoids a PHP built-in server quirk: a URL that matches a real directory with an index file gets mapped to that file + PATH_INFO, which breaks deep links under `php artisan serve`.
- **Verified** in headless Chrome as admin, manager and agent (light/dark): login, dashboard, filters, lead drawer + status change, and bulk assign including the server's 409 capacity error, with no console errors and no failed requests.

## 9. Beyond the brief

- **REST API v1** with Swagger UI (`/api/documentation`) on the same Actions and policies. A test fails if any `/api/v1` route is undocumented.
- **Inbound webhooks:** HMAC-SHA256 over `timestamp.body`, a 5-minute replay window, constant-time compare, idempotent on `(source, external_id)`.
- **Outbound webhooks:** signed, after-commit, retried, de-duplicated, with SSRF protection (https only, public IPs only, re-checked at send time).
- **Test suite:** 177 tests on MySQL, including architecture rules and 30 edge-case scenarios (`tests/Feature/Scenarios`). The scenarios found and fixed two real bugs: the escalation clock and timezone-offset storage.
