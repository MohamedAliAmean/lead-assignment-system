# Design: Lead Assignment & Follow-up System

> Status: **APPROVED & IMPLEMENTED**. Deviations found during implementation are listed in §21.
> Implements: `requirements.md` v2. Follows: `../constitution.md`.
> Requirement IDs (R x.y) and assumption IDs (A-n) are referenced throughout.

---

## 1. Technology choices

| Concern | Choice | Version | Why |
|---|---|---|---|
| Framework | Laravel | `^12` (pinned) | Required. Laravel 13 is out, but the task says 12. |
| Admin panel | Filament | `^5.12` | Required. Supports `illuminate/* ^12`. |
| Database | MySQL (InnoDB) | 8.4 | Required. Row locks, `FOR UPDATE`, unique constraints, FULLTEXT. |
| Tests | Pest | `^4` + `pest-plugin-laravel ^4` | Readable tests, built-in architecture tests. (Pest 5 needs Symfony 8 components that Laravel 12 does not allow, see §21.) |
| API auth | Laravel Sanctum | `^4` | A-15. |
| API docs | `darkaonline/l5-swagger` (swagger-php attributes) | `^11` | Serves real **Swagger UI**, supports Laravel 12, OpenAPI 3. |
| Queue | `database` driver by default, `redis` supported | — | Reviewers can run it with no extra services. Redis is recommended in production (README). |

**Rejected:** `spatie/laravel-permission`. Three fixed roles fit a backed enum column; a permissions package would add tables and queries with no benefit here (YAGNI). `dedoc/scramble` generates docs automatically, but its default UI is not Swagger UI, which the user explicitly asked for.

---

## 2. Architecture overview

```
              ┌─────────────┐   ┌──────────────┐   ┌───────────────────┐   ┌──────────────┐
 Delivery     │  Filament   │   │ REST API v1  │   │ Inbound webhooks  │   │ Artisan/Sched│
 (thin)       │  Resources  │   │ Controllers  │   │ Controller        │   │  Commands    │
              └──────┬──────┘   └──────┬───────┘   └─────────┬─────────┘   └──────┬───────┘
                     │  DTOs           │  FormRequest → DTO  │                    │
                     ▼                 ▼                     ▼                    ▼
              ┌──────────────────────────────────────────────────────────────────────────┐
 Application  │ Actions: CreateLead · UpdateLead · ChangeLeadStatus · BulkAssignLeads ·   │
              │          EscalateLead                                                     │
              └──────┬─────────────────────┬───────────────────────┬─────────────────────┘
                     ▼                     ▼                       ▼
 Domain       LeadAssigner ──► AssignmentStrategy (RoundRobin | WeightedRoundRobin*)
 services     CapacityLedger   EscalationRecipientResolver   LeadVisibility ──► LeadVisibilityRule×3
                     │                     │                       │
                     ▼                     ▼                       ▼
 Persistence  Eloquent models + LeadBuilder (query scopes) + MySQL (locks, unique constraints)
                     │
 Side effects Domain events (after commit) ──► queued listeners ──► Webhook deliveries, Notifications
```
`*` designed for, not enabled (A-10).

**The rule:** delivery layers only (1) authenticate, (2) validate and build a DTO, (3) call one Action, (4) map the result or domain exception to the output. Arch tests enforce this (§13.5).

---

## 3. Directory layout

```
app/
├── Actions/Leads/                 CreateLead, UpdateLead, ChangeLeadStatus, BulkAssignLeads, EscalateLead
├── Data/                          CreateLeadData, UpdateLeadData, BulkAssignData   (final readonly DTOs)
├── Enums/                         LeadStatus, LeadPriority, LeadSource, UserRole, WebhookEvent, DeliveryStatus
├── Exceptions/Domain/             DomainException (base), AgentCapacityExceeded, AssigneeNotEligible,
│                                  LeadsNotAccessible, InvalidWebhookSignature
├── Assignment/
│   ├── Contracts/AssignmentStrategy.php
│   ├── RoundRobinStrategy.php
│   ├── LeadAssigner.php
│   └── CapacityLedger.php
├── Escalation/
│   ├── Contracts/EscalationRecipientResolver.php
│   └── TeamManagerRecipientResolver.php
├── Authorization/
│   ├── Contracts/LeadVisibilityRule.php
│   ├── Rules/AdminVisibility.php, ManagerVisibility.php, AgentVisibility.php
│   ├── LeadVisibility.php         (picks the rule by role)
│   └── Contracts/CurrentActor.php + AuthCurrentActor.php
├── Webhooks/
│   ├── Contracts/WebhookSigner.php + HmacSha256Signer.php
│   ├── Contracts/ProvidesWebhookPayload.php
│   ├── OutboundUrlGuard.php       (SSRF protection)
│   └── InboundSourceRegistry.php  (per-source secrets from config)
├── Events/                        LeadCreated, LeadAssigned, LeadStatusChanged, LeadEscalated
├── Listeners/QueueWebhookDeliveries.php
├── Jobs/                          EscalateStaleLead, DeliverWebhook
├── Notifications/LeadEscalatedNotification.php
├── Observers/LeadObserver.php     (status audit only)
├── Models/                        User, Team, Lead, LeadStatusLog, LeadEscalation, AgentDailyLoad,
│                                  AssignmentCursor, WebhookSubscription, WebhookDelivery
├── Models/Builders/LeadBuilder.php (named scopes shared by Filament + API)
├── Policies/                      LeadPolicy, WebhookSubscriptionPolicy
├── Http/
│   ├── Controllers/Api/V1/        AuthTokenController, LeadController, LeadStatusController,
│   │                              LeadStatusHistoryController, LeadBulkAssignmentController,
│   │                              WebhookSubscriptionController, InboundLeadWebhookController
│   ├── Requests/Api/V1/           one FormRequest per write endpoint (each has toDto())
│   ├── Resources/V1/              LeadResource, LeadStatusLogResource, UserSummaryResource, ...
│   └── Middleware/                EnsureUserIsActive, VerifyInboundWebhookSignature
├── OpenApi/                       OpenApiSpec.php (info, security), Schemas/*.php (attribute-only classes)
├── Console/Commands/              EscalateStaleLeadsCommand (sweeper)
├── Filament/Resources/Leads/      LeadResource + Pages + Schemas + Tables (thin)
├── Filament/Resources/WebhookSubscriptions/  (admin only)
└── Providers/                     AppServiceProvider (bindings, lazy-loading guard, slow-query log)
```

---

## 4. Data model

### 4.1 ER diagram

```
teams 1 ── 1 users (manager)          teams.manager_id  → users.id   (UNIQUE)
teams 1 ── * users (members)          users.team_id     → teams.id
users 1 ── * leads                    leads.assigned_to → users.id
leads 1 ── * lead_status_logs
leads 1 ── 0..1 lead_escalations      UNIQUE(lead_id)
users 1 ── * agent_daily_loads        PK(user_id, load_date)
assignment_cursors (one row per pool; v1 uses pool = 'global')
webhook_subscriptions 1 ── * webhook_deliveries   UNIQUE(subscription_id, event_id)
```

### 4.2 Tables

**`teams`**
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| name | VARCHAR(100) UNIQUE | |
| manager_id | BIGINT UNSIGNED NULL UNIQUE FK→users(id) ON DELETE SET NULL | One manager per team (R2.3). Added in a later migration because of the circular FK. |

**`users`** (extends the default Laravel table)
| Column | Type | Notes |
|---|---|---|
| role | VARCHAR(20) + CHECK | `UserRole` enum (R2.1). |
| team_id | BIGINT UNSIGNED NULL FK→teams ON DELETE SET NULL | A-9. |
| is_active | BOOLEAN DEFAULT 1 | R2.2, R3.2. |
| daily_capacity | SMALLINT UNSIGNED DEFAULT 20 | R2.2. |
| assignment_weight | TINYINT UNSIGNED DEFAULT 1 | A-10. |
| **Index** `idx_users_role_active (role, is_active, id)` | | Covers the eligible-agent query. |

**`leads`**
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | Default sort key (newest first). |
| name | VARCHAR(150) | |
| phone | VARCHAR(32) | Stored normalized (digits and a leading `+`). |
| source | VARCHAR(30) + CHECK | `LeadSource` enum: website, facebook, google_ads, referral, walk_in, api, other. |
| external_ref | VARCHAR(100) NULL | Id in the source system (inbound webhooks). |
| priority | TINYINT UNSIGNED + CHECK | `LeadPriority` int enum: 1 low, 2 medium, 3 high. Integer, so `ORDER BY priority` is meaningful. |
| status | VARCHAR(20) + CHECK | `LeadStatus` enum. |
| assigned_to | BIGINT UNSIGNED NULL FK→users ON DELETE RESTRICT | Users are deactivated, never deleted. |
| assigned_at | TIMESTAMP NULL | R1.4. |
| next_follow_up_at | TIMESTAMP NULL | A-12. |
| created_at / updated_at | TIMESTAMP | |

*Why VARCHAR + CHECK instead of MySQL `ENUM`?* Adding a value to a MySQL `ENUM` needs an `ALTER TABLE` that can rebuild a 500k-row table, and the values are hard to read in a dump. VARCHAR with a CHECK constraint keeps the database guarantee, while the PHP backed enum stays the single source of truth for the application. A migration helper builds the CHECK from `Enum::values()`.

**`lead_status_logs`** (R4)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| lead_id | FK→leads ON DELETE CASCADE | |
| user_id | NULL FK→users ON DELETE SET NULL | NULL = system (R4.4). |
| old_status | VARCHAR(20) NULL | NULL for the initial "created" entry. |
| new_status | VARCHAR(20) | |
| changed_at | TIMESTAMP(3) | Millisecond precision keeps fast successive changes in order. |
| **Index** `(lead_id, changed_at)` | | History for one lead, already ordered. |

Decision: the initial status on creation is also logged (`old_status = NULL`), so the history is complete from the start.

**`lead_escalations`** (R5)
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| lead_id | FK→leads CASCADE, **UNIQUE** | The idempotency anchor (R5.5). |
| manager_id | NULL FK→users | NULL means it fell back to admins (A-5). |
| escalated_at | TIMESTAMP | |

**`agent_daily_loads`** (capacity ledger, R3.3, A-3, A-11)
| Column | Type | Notes |
|---|---|---|
| user_id | FK→users CASCADE | |
| load_date | DATE | In the app timezone. |
| assigned_count | SMALLINT UNSIGNED DEFAULT 0 | |
| **PK** `(user_id, load_date)` | | One row per agent per day; this is the row that gets locked. |

*Why a counter table instead of `COUNT(*)` over leads?* (a) Speed: one primary-key lookup instead of counting over 500k rows. (b) Correctness: a conditional `UPDATE` on one row is atomic, while "count, then insert" is a race.

**`assignment_cursors`** (Round Robin state)
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| pool | VARCHAR(50) UNIQUE | `global` in v1 (A-2). Per-team pools later need no schema change. |
| last_user_id | NULL FK→users SET NULL | Last agent that received a lead. |
| updated_at | TIMESTAMP | |

Seeded with one `global` row by a migration, so the row we lock always exists.

**`webhook_subscriptions`**: id, name, url (VARCHAR 2048), secret (TEXT, `encrypted` cast), events (JSON array of `WebhookEvent` values), is_active, timestamps.

**`webhook_deliveries`**: id (ULID PK, also sent as `X-Webhook-Id`), subscription_id FK CASCADE, event VARCHAR(50), event_id (UUID), payload JSON, status (`pending|delivered|failed`), attempts TINYINT, response_status SMALLINT NULL, last_error TEXT NULL, delivered_at NULL, timestamps.
**UNIQUE** `(subscription_id, event_id)` (R12.8). **Index** `(status, created_at)` for the admin view.

Framework tables: `jobs`, `job_batches`, `failed_jobs`, `notifications`, `personal_access_tokens`, `cache`, `sessions`.

### 4.3 Leads indexes (R1.6, R8.4)

| Index | Serves | Justification |
|---|---|---|
| `PRIMARY (id)` | default sort `id DESC`, cursor pagination | Newest-first listing reads the clustered index backwards; no filesort. |
| `idx_leads_assignee_status (assigned_to, status)` | Agent scope, Manager scope (`assigned_to IN (...)`), "My Leads", "Unassigned" (`IS NULL`), status filter within a scope, FK | The most selective column comes first, and every non-admin query starts with it. In InnoDB a secondary index also stores the PK, so it effectively becomes `(assigned_to, status, id)`. |
| `idx_leads_follow_up (next_follow_up_at)` | "Overdue" filter, sorting by follow-up time | A range scan on `< NOW()`. The open-status condition is applied to the rows the range returns. |
| `idx_leads_status_created (status, created_at)` | Escalation sweeper (`status='new' AND created_at <= ?`), admin status filter | An equality-then-range lookup, exactly the shape of the sweeper query. |
| `idx_leads_priority (priority)` | "High Priority" filter | Effectively `(priority, id)`, so `WHERE priority=3 ORDER BY id DESC LIMIT 25` reads in index order with no filesort. |
| `uq_leads_source_external (source, external_ref)` UNIQUE | Inbound webhook de-duplication (R12.3) | A database-level guarantee. MySQL allows many NULLs, so manually created leads are not affected. |
| `idx_leads_phone (phone)` | Phone search | Search is a **prefix** match (`LIKE '0100%'`), which can use the B-tree. `%x%` cannot. |
| `ft_leads_name FULLTEXT (name)` | Name search | `MATCH ... AGAINST` in boolean mode instead of `LIKE '%x%'`, which would be a full scan of 500k rows. |

**Indexes we chose not to add:** `(assigned_to, next_follow_up_at)`, because one agent's leads are a few thousand rows at most and filtering them is cheap. Each extra index slows every insert and update, and leads are written often.

The technical note will include real `EXPLAIN` output for each list/filter query against the 500k seed (R8.4, R10.5).

---

## 5. Domain model & enums

```php
enum LeadStatus: string {
    case New = 'new'; case Contacted = 'contacted'; case Qualified = 'qualified';
    case Unqualified = 'unqualified'; case Won = 'won'; case Lost = 'lost';
    public function isOpen(): bool          // New, Contacted, Qualified
    public static function openValues(): array
    public function label(): string; public function color(): string   // used by Filament
}
enum LeadPriority: int { case Low = 1; case Medium = 2; case High = 3; }
enum UserRole: string  { case Admin = 'admin'; case SalesManager = 'sales_manager'; case SalesAgent = 'sales_agent'; }
```

`Lead` uses `#[UseEloquentBuilder(LeadBuilder::class)]` (Laravel 12). `LeadBuilder` holds named, reusable scopes:

```php
visibleTo(User $user)   // delegates to LeadVisibility (§8)
assignedTo(User|int $user)
unassigned()
overdue(?CarbonInterface $now = null)   // next_follow_up_at < now AND status IN open
highPriority()
withStatus(LeadStatus ...$statuses)
staleNew(CarbonInterface $before)       // escalation sweeper
searchName(string $term), searchPhonePrefix(string $prefix)
```
Filament filters and API query parameters both call these, so filtering logic exists exactly once.

---

## 6. Automatic assignment & concurrency (R3), the core

### 6.1 Components

```php
interface AssignmentStrategy {
    /** Eligible agents in the order they should be tried, starting after $lastUserId. */
    public function candidates(?int $lastUserId, CarbonImmutable $day): iterable; // iterable<User>
}

final class RoundRobinStrategy implements AssignmentStrategy { /* §6.2 query */ }

final class CapacityLedger {
    /** Atomically reserve $n slots for $agent on $day. Returns false if it would exceed capacity or the agent is inactive. */
    public function tryReserve(int $agentId, CarbonImmutable $day, int $n = 1): bool;
    public function remaining(User $agent, CarbonImmutable $day): int;
}

final class LeadAssigner {
    public function __construct(AssignmentStrategy $strategy, CapacityLedger $ledger, \Psr\Clock\ClockInterface $clock) {}
    /** Must be called inside a transaction. Returns the agent, or null if no one is eligible (A-4). */
    public function assignAutomatically(Lead $lead): ?User;
}
```

### 6.2 Algorithm

All of this runs inside `CreateLead`'s transaction (§7.1):

```
1. SELECT * FROM assignment_cursors WHERE pool='global' FOR UPDATE;      -- serializes rotation
2. candidates = eligible agents, ordered so that ids > last_user_id come first, then wrap around:
     SELECT u.id FROM users u
     LEFT JOIN agent_daily_loads l ON l.user_id = u.id AND l.load_date = :today
     WHERE u.role='sales_agent' AND u.is_active = 1
       AND COALESCE(l.assigned_count, 0) < u.daily_capacity
     ORDER BY (u.id <= :last) ASC, u.id ASC
     LIMIT 50
3. for each candidate:
     if ledger.tryReserve(candidate, today, 1):         -- atomic, see 6.3
         UPDATE leads SET assigned_to = candidate, assigned_at = NOW() WHERE id = :lead
         UPDATE assignment_cursors SET last_user_id = candidate WHERE pool = 'global'
         return candidate
     -- false means the candidate filled up or was deactivated since step 2 (for example by a
     -- concurrent bulk assignment that does not take the cursor lock); try the next one
4. return null      -- the lead stays unassigned (A-4)
```

### 6.3 `CapacityLedger::tryReserve`, the atomic part

```sql
INSERT INTO agent_daily_loads (user_id, load_date, assigned_count) VALUES (?, ?, 0)
    ON DUPLICATE KEY UPDATE assigned_count = assigned_count;          -- make sure the row exists

UPDATE agent_daily_loads l
JOIN users u ON u.id = l.user_id
SET l.assigned_count = l.assigned_count + :n
WHERE l.user_id = :agent AND l.load_date = :day
  AND u.is_active = 1 AND u.role = 'sales_agent'
  AND l.assigned_count + :n <= u.daily_capacity;                     -- affected rows: 1 = reserved, 0 = rejected
```

### 6.4 Why this is safe (the interview answer)

1. **Two leads created at the same moment cannot take the same turn.** Step 1 takes an exclusive row lock on the single cursor row. The second transaction blocks on `FOR UPDATE` until the first commits, and then it reads the **updated** `last_user_id`. Round Robin order is therefore a strict sequence.
2. **Capacity can never be exceeded, even by paths that do not take the cursor lock** (bulk or manual assignment). The guard is inside the `UPDATE ... WHERE assigned_count + n <= capacity`. In InnoDB an `UPDATE` reads the **latest committed** row version and locks it (a "current read", not the transaction's snapshot), so the check and the increment are a single atomic step. Of two competing reservations, the second one re-checks the condition after the first commits.
3. **Deactivation races.** `is_active` is checked inside the same locking `UPDATE`, so an agent deactivated a millisecond earlier is rejected.
4. **A lead can never be assigned twice or end up half-assigned.** The lead row, the ledger increment and the cursor move are all in **one transaction**. Either everything commits or nothing does. A new lead is not visible to anyone else until commit.
5. **Deadlocks.** Locks are always taken in the same order: cursor → ledger row(s) → lead row(s), and lead rows in ascending id order. `DB::transaction($cb, attempts: 3)` retries automatically if MySQL still picks a deadlock victim.
6. **Cost.** The cursor lock is held for about 4 short indexed statements (a few ms), which allows hundreds of assignments per second. That is far more than the lead intake rate of any sales team. If it ever became a bottleneck, the `pool` column allows one cursor per team or region with no schema change.

### 6.5 Alternatives considered

| Option | Verdict |
|---|---|
| Redis lock (`Cache::lock`) | Rejected. The lock is not tied to the DB transaction: it can expire mid-transaction, or be released before commit. It adds infrastructure and a second source of truth. |
| MySQL `GET_LOCK()` named lock | Works, but it is scoped to the session, not the transaction: it must be released manually and behaves badly with persistent connections or Octane. |
| `SELECT ... FOR UPDATE` on all agent rows | Locks many rows per assignment, causes more deadlocks, and blocks unrelated user updates. |
| Optimistic locking (version column + retry) | Fine at low contention, but under a burst (a marketing campaign) it turns into a retry storm. Pessimistic locking on a single hot row is cheaper and predictable. |
| "Least loaded today" instead of a cursor | Fair, but it is not Round Robin, which the spec asks for. It remains a valid strategy implementation (OCP). |

### 6.6 Weighted Round Robin (interview point, A-10)

`WeightedRoundRobinStrategy implements AssignmentStrategy`, enabled with a config switch. No changes to `LeadAssigner`, the ledger or the schema (OCP + DIP).
Algorithm: **smooth weighted round robin** (the nginx algorithm) over the eligible agents. A senior agent with weight 2 gets 2 out of every 3 leads compared with a weight-1 junior, and the turns are interleaved rather than given in bursts. Each agent's running "current weight" lives in a small `assignment_weights_state` table, updated under the same cursor lock. Daily capacity still applies through the ledger.

---

## 7. Use cases (Actions)

All Actions are `final`, constructor-injected, and expose one `handle()` method. Each one owns its transaction.

### 7.1 `CreateLead::handle(CreateLeadData $data, ?User $actor): Lead` (R3.1, R5.1, R12.1)

```
DB::transaction(attempts: 3):
    lead = Lead::create([... status: New, next_follow_up_at: data or now()+delay])   // A-12
         // inbound webhooks use createOrFirst on (source, external_ref) → a duplicate returns the existing lead (R12.3)
    observer writes the initial status log (old_status NULL)
    agent = assigner->assignAutomatically(lead)
    event LeadCreated, and LeadAssigned if agent      // ShouldDispatchAfterCommit
EscalateStaleLead::dispatch(lead.id)->delay(config delay)->afterCommit()
return lead
```

### 7.2 `ChangeLeadStatus::handle(Lead $lead, LeadStatus $to, User $actor): Lead` (R4)

```
authorize(actor, 'changeStatus', lead)
DB::transaction:
    lead = Lead::lockForUpdate()->findOrFail(id)       // serializes with EscalateLead (§9.3)
    if lead.status === to: return lead                 // R4.3, no audit row
    lead.status = to; lead.save()                      // observer writes the audit row in this transaction
    event LeadStatusChanged(old, new, actor)
```

### 7.3 `UpdateLead`
Edits contact fields and follow-up date. If `status` is present, it calls `ChangeLeadStatus` internally, so there is one code path for status changes.

### 7.4 `BulkAssignLeads::handle(BulkAssignData $data, User $actor): int` (R6.6, R7.4, R7.5, A-7, A-8)

```
target = User::findOrFail(data.userId)
policy: actor may assign to target?
    admin → any active sales agent; manager → active agent in own team; agent → never
    otherwise throw AssigneeNotEligible / AuthorizationException
guard: count(ids) ≤ 500                                  // bounded; never unbounded memory
DB::transaction(attempts: 3):
    leads = Lead::visibleTo(actor)->whereKey(ids)->orderBy('id')->lockForUpdate()->get(['id','assigned_to'])
    if leads.count !== unique(ids).count → throw LeadsNotAccessible       // all-or-nothing (A-7)
    toMove = leads where assigned_to !== target.id      // re-assigning to the same agent costs nothing
    if toMove is empty → return 0
    if !ledger.tryReserve(target.id, today, toMove.count)
        → throw AgentCapacityExceeded(remaining: ledger.remaining(target))
    Lead::whereKey(toMove ids)->update([assigned_to, assigned_at])   // no status change → audit not bypassed
    event LeadAssigned for each moved lead (after commit)
return toMove.count
```

*Lock order:* leads → ledger. The automatic path locks cursor → ledger → **new** lead (not visible to anyone), so there is no cycle between the two paths. Bulk-vs-bulk locks leads in ascending id order, so there is no cycle there either.

### 7.5 `EscalateLead`: see §9.

### 7.6 Domain exceptions → responses

| Exception | API | Filament |
|---|---|---|
| `AuthorizationException` / `LeadsNotAccessible` | 403 | Danger notification, action halted |
| `ModelNotFoundException` (scoped out) | 404 | 404 page |
| `AgentCapacityExceeded`, `AssigneeNotEligible` | 409 + `{message, remaining}` | Danger notification with the remaining capacity |
| `ValidationException` | 422 | Form errors |

Mapped once in `bootstrap/app.php` (`withExceptions`). Filament actions catch the `DomainException` base class.

---

## 8. Authorization & visibility (R6)

### 8.1 Two layers, both on the server

1. **Query scoping:** `Lead::visibleTo($user)` is applied to **every** read: Filament `LeadResource::getEloquentQuery()`, API index, bulk action, global search (disabled for leads, see §11).
   A direct URL to someone else's lead → the record is not in the scoped query → **404**. This also hides whether the record exists.
2. **Policies:** `LeadPolicy` (`viewAny, view, create, update, changeStatus, assign, delete`) is checked for every record-level operation, in Filament (automatically, by resource convention), in API controllers (`$this->authorize`), and inside Actions (defence in depth: a future caller cannot skip it).

### 8.2 Visibility rules (OCP / ISP)

```php
interface LeadVisibilityRule {
    public function apply(LeadBuilder $query, User $user): LeadBuilder;   // set-level
    public function allows(User $user, Lead $lead): bool;                 // record-level
}
```
| Rule | `apply` | `allows` |
|---|---|---|
| `AdminVisibility` | no constraint | true |
| `ManagerVisibility` | `assigned_to IN (SELECT id FROM users WHERE team_id = :managedTeamId)` | `lead.assignee.team_id === managedTeamId` |
| `AgentVisibility` | `assigned_to = :id` | `lead.assigned_to === id` |

`LeadVisibility` maps `UserRole → rule` (bound in the service provider). An inactive user, or a manager without a team, gets an empty result (`whereRaw('0 = 1')`): **fail closed**. The policy's `view()` uses `allows()` from the **same** rule, so the list scope and the record check can never disagree.

**Manager subquery performance:** MySQL turns `IN (subquery)` into a semi-join that drives off `idx_users_team` and then probes `idx_leads_assignee_status`. Verified with `EXPLAIN` in the technical note.

### 8.3 Policy matrix

| Ability | Admin | Manager | Agent |
|---|---|---|---|
| viewAny | ✓ | ✓ (scoped) | ✓ (scoped) |
| view / update / changeStatus | ✓ | team leads | own leads |
| create | ✓ | ✓ | ✗ (leads come from managers, the API or webhooks) |
| assign / bulk assign | ✓ any active agent | team leads → own team's active agents | ✗ |
| delete | ✓ | ✗ | ✗ |
| manage webhook subscriptions | ✓ | ✗ | ✗ |

### 8.4 Other guards
- `User::canAccessPanel()` returns `is_active` (R6.7).
- `EnsureUserIsActive` middleware on the API: a deactivated user's existing tokens stop working immediately.
- **Team change (A-9):** visibility is computed from current `team_id` at query time, so moving an agent moves visibility of their leads with them, with no data migration. Trade-off to discuss: the old manager loses history visibility. The alternative is snapshotting `team_id` on the lead at assignment time, which keeps history with the old team but needs a reassignment rule when someone moves.

---

## 9. Escalation (R5)

### 9.1 Flow

```
CreateLead ──(afterCommit, delay 30m)──► EscalateStaleLead(leadId)  [queue: escalations]
                                               │
Scheduler every minute ──► leads:escalate-stale (sweeper) ──► dispatches the same job for missed leads
                                               ▼
                                     EscalateLead::handle(leadId)
```

### 9.2 `EscalateLead::handle(int $leadId): void`

```
DB::transaction:
    lead = Lead::lockForUpdate()->find(leadId)
    if !lead → return                                          // deleted
    if lead.status !== New → return                            // R5.3: status changed, do nothing
    if lead.created_at > now() - delay → return                // dispatched too early; the sweeper will retry
    recipients = resolver->resolve(lead)                       // EscalationRecipientResolver (A-5)
    inserted = LeadEscalation::insertOrIgnore([lead_id, manager_id, escalated_at])
    if inserted === 0 → return                                 // R5.5: already escalated
    Notification::send(recipients, new LeadEscalatedNotification(lead))   // database channel, same transaction
    event LeadEscalated                                        // webhooks, after commit
```

### 9.3 Why it is idempotent and retry-safe

- **Verify before acting (R5.2, R5.3):** the job carries only the id and reloads the lead **under a row lock**. `ChangeLeadStatus` takes the same lock, so a status change and an escalation can never interleave. Either the status change commits first (the job sees non-`new` and stops), or the escalation commits first (correct, because the lead really was `new` after 30 minutes).
- **At most once (R5.5):** `UNIQUE(lead_id)` on `lead_escalations` is the real guarantee. `insertOrIgnore` returns 0 for a duplicate, and the job stops **before** any side effect. This holds for retries, double dispatch (job + sweeper) and two workers at the same instant. `ShouldBeUnique` on the job is only an optimisation to avoid enqueuing duplicates.
- **Side effect inside the transaction:** the database notification is written in the same transaction as the escalation row. Both commit or both roll back, so there is never "notified but not recorded" or the reverse.
- **Failure and retry (R5.6):** any exception rolls back everything; the job then retries (`tries = 5`, backoff `[10, 30, 60, 120]` s). The job never changes the lead (R5.7), so a failure cannot corrupt lead state. `failed()` logs the error. The sweeper keeps retrying later, because no escalation row exists yet.
- **Why a sweeper exists at all:** delayed jobs can be lost (Redis flushed, queue reset, job exhausted its tries). Some drivers also cap delays: **Amazon SQS allows at most 15 minutes**, so a 30-minute delay is impossible there. The sweeper (`staleNew()` + `whereDoesntHave('escalation')` + `chunkById(500)`) makes escalation reliable regardless of the driver. It is safe because the job is idempotent.

### 9.4 Recipient resolver (OCP / DIP)

```php
interface EscalationRecipientResolver { /** @return Collection<User> */ public function resolve(Lead $lead): Collection; }
```
`TeamManagerRecipientResolver`: the active manager of the assignee's team. Fallback: all active admins (A-5). Swappable, for example for an "on-call manager" rotation.

---

## 10. Status audit (R4)

- `LeadObserver::created` writes the initial log; `LeadObserver::updated` writes a log **only if** `$lead->wasChanged('status')`, with `old = getOriginal('status')` and `new = status`.
- **Why an observer here** (constitution §3.5): it is the one hook that **every** Eloquent save goes through: Filament, API, jobs, tinker, future code. An audit that relies on each caller remembering to log will eventually miss one.
- **Atomicity (R4.2):** every Action wraps its save in a transaction, and the observer runs on the same connection inside it, so the log row commits or rolls back together with the status change.
- **Known limit, documented:** query-builder mass updates (`Lead::query()->update(['status' => ...])`) skip model events. Rule: status is only changed via `ChangeLeadStatus`. An arch test bans `->update(` with `status` in non-Action code. Bulk assignment uses a mass update but never touches `status`.
- **Actor (R4.4):** the observer depends on the `CurrentActor` interface (DIP) instead of calling `auth()` directly. `AuthCurrentActor` returns the authenticated user or `null` (jobs, scheduler). Tests can bind a fake.

---

## 11. Filament (R7)

### 11.1 Lead resource

| Element | Implementation |
|---|---|
| Query | `getEloquentQuery()` → `parent::...->visibleTo(auth()->user())->with('assignedUser:id,name')->select([...needed columns])` |
| Columns | name (searchable: FULLTEXT), phone (searchable: prefix), source (badge), status (badge + color from enum), assignedUser.name ("Unassigned" placeholder), **Time Until Follow-up** = `next_follow_up_at` shown as relative time ("in 2h" / "overdue 3h", red when overdue). Computed in PHP from the loaded column: **0 extra queries**. |
| Default sort | `id DESC` (clustered index). Sortable: created (id), priority, next follow-up. All indexed. |
| Filters | My Leads → `assignedTo(auth user)` · Unassigned → `unassigned()` · Overdue → `overdue()` · High Priority → `highPriority()`. All are `LeadBuilder` scopes (§5). "Unassigned" is shown only to admins: for managers and agents it would always be empty, because their scopes exclude unassigned leads. |
| Bulk action | "Assign to user": a modal with an agent select (options limited to agents the actor may assign to). The action calls `BulkAssignLeads` with **record keys only** (no full models hydrated). Domain exceptions → danger notification. Visible only if `can('assign')`, but enforced again inside the Action. |
| Record actions | Change status (a modal → `ChangeLeadStatus`), edit (→ `UpdateLead`), view (infolist + status history relation manager, paginated). |
| Create | `CreateLeadPage::handleRecordCreation()` → `CreateLead` Action (auto-assignment happens there). |
| Pagination | 25 per page by default, 10/25/50 options, no "all". |
| Global search, nav badge | Disabled for leads (each would run unscoped `LIKE`/`COUNT` queries on every page load). |

**Points to verify against the installed Filament 5 API during implementation** (no guessing): (a) how to make a bulk action receive keys without hydrating models; (b) a simple/cursor pagination mode for the admin's unscoped view, which avoids `COUNT(*)` over 500k rows. We will measure the count query first and only switch if it is actually slow.

### 11.2 Webhook subscription resource (admin only)
CRUD for subscriptions (URL validated by `OutboundUrlGuard`) and a read-only deliveries relation manager (status, attempts, last response). No business logic: saving goes through the model plus a FormRequest-equivalent validation rule.

---

## 12. REST API & Swagger (R11)

### 12.1 Endpoints (`/api/v1`, JSON, `auth:sanctum` + `active` + `throttle:api` unless noted)

| Method | Path | Controller → Action | Notes |
|---|---|---|---|
| POST | `/auth/tokens` | AuthTokenController@store | Email + password → token. `throttle:5,1` per IP. |
| DELETE | `/auth/tokens/current` | AuthTokenController@destroy | Revoke. |
| GET | `/leads` | LeadController@index | `filter[mine]`, `filter[unassigned]`, `filter[overdue]`, `filter[priority]`, `filter[status]`, `search`; cursor pagination (R11.5). |
| POST | `/leads` | LeadController@store → CreateLead | 201. |
| GET | `/leads/{lead}` | LeadController@show | Scoped binding → 404 if not visible. |
| PATCH | `/leads/{lead}` | LeadController@update → UpdateLead | |
| PATCH | `/leads/{lead}/status` | LeadStatusController@update → ChangeLeadStatus | |
| GET | `/leads/{lead}/status-history` | LeadStatusHistoryController@index | Paginated. |
| POST | `/leads/bulk-assign` | LeadBulkAssignmentController@store → BulkAssignLeads | 200 `{assigned: n}` / 409. |
| GET/POST/DELETE | `/webhook-subscriptions[/{id}]` | WebhookSubscriptionController | Admin only (policy). |
| POST | `/webhooks/leads` | InboundLeadWebhookController → CreateLead | **No Sanctum.** `VerifyInboundWebhookSignature` + `throttle` per source. 201 new / 200 duplicate. |

Route model binding for `{lead}` resolves through `Lead::visibleTo(request user)`, so an inaccessible lead returns 404 before the controller runs. The policy check is a second layer.

### 12.2 Conventions
- **Form Requests** validate and expose `toDto()`. Controllers never pass `$request->all()` on.
- **API Resources** shape output (`LeadResource` with `assigned_user` via `whenLoaded`, ISO-8601 dates, enum values plus labels).
- **Errors:** one JSON envelope `{message, errors?, code}`, with codes such as `capacity_exceeded`, `lead_not_found`, `forbidden`.
- **Versioning:** URI prefix `v1`, with controllers under `Api\V1`.

### 12.3 Swagger
- `l5-swagger` with swagger-php **PHP 8 attributes**: `#[OA\Get(...)]` on controller methods, and reusable schemas (`Lead`, `LeadStatusLog`, `Error`, `ValidationError`, `CapacityExceeded`, `WebhookSubscription`) in `app/OpenApi/Schemas`.
- The security schemes `bearerAuth` (Sanctum) and `webhookSignature` (headers) are documented, with example requests and responses for every endpoint.
- The UI is at **`/api/documentation`**, the raw spec at `/docs?api-docs.json`. `L5_SWAGGER_GENERATE_ALWAYS=true` locally; generated in CI/setup (`php artisan l5-swagger:generate`).
- **Anti-drift test (R11.8):** generate the spec, then assert that every route under `api/v1` (method + normalized path) exists in `paths`.

---

## 13. Webhooks (R12)

### 13.1 Inbound
- Headers: `X-Webhook-Source: facebook`, `X-Webhook-Timestamp: <unix>`, `X-Webhook-Signature: sha256=<hex>`, where the signature is `HMAC_SHA256(secret_for_source, "{timestamp}.{raw_body}")`.
- `VerifyInboundWebhookSignature`: unknown source → 401. Timestamp more than 300 s away from now → 401 (replay). Comparison uses `hash_equals` (constant time).
- Secrets live in `config/webhooks.php` from env (`WEBHOOK_SECRET_FACEBOOK=...`) via `InboundSourceRegistry`.
- De-duplication: `createOrFirst` on `(source, external_ref)`. When two identical deliveries race, one insert wins, the other hits the unique index and reads the existing row.

### 13.2 Outbound

```
Action ──event (ShouldDispatchAfterCommit, carries event_id UUID)──► QueueWebhookDeliveries (queued listener)
    │ for each active subscription with this event:
    │     insertOrIgnore webhook_deliveries (subscription_id, event_id)   ← UNIQUE: no duplicates on listener retry
    │     DeliverWebhook::dispatch(delivery_id) for newly inserted rows
    ▼
DeliverWebhook (tries 6, backoff 10s, 30s, 2m, 10m, 30m; timeout 15s)
    if delivery.status === delivered → return               // idempotent
    OutboundUrlGuard re-checks the URL at send time         // DNS-rebinding defence
    POST body = payload JSON; headers: X-Webhook-Id (delivery ULID), X-Webhook-Event,
           X-Webhook-Timestamp, X-Webhook-Signature (HmacSha256Signer, shared with inbound)
    2xx  → UPDATE ... SET status=delivered WHERE id=? AND status<>'delivered'
    else → attempts++, store response code/error, throw → retry
    failed() → status = failed
```

- **Semantics: at-least-once.** If the receiver got the request but our process died before we marked it delivered, the job sends it again. That is unavoidable over HTTP, so receivers de-duplicate on `X-Webhook-Id`. Documented in the API docs.
- **After commit (R12.6):** events implement `ShouldDispatchAfterCommit`, so a rolled-back transaction never produces a webhook about something that did not happen.
- **SSRF (R12.9):** `OutboundUrlGuard` requires `https` and resolves the host, rejecting private, loopback, link-local and metadata ranges. It is checked on save and again on send. `WEBHOOKS_ALLOW_PRIVATE_URLS=true` in local only.
- **Interfaces:** `WebhookSigner` (sign/verify) is used by both directions. Events implement `ProvidesWebhookPayload { name(); payload(); eventId(); }`, so the listener depends only on that interface (DIP), and a new event type is a new class (OCP).

---

## 14. Performance (R8)

| Technique | Where |
|---|---|
| Indexes per §4.3, verified with `EXPLAIN` on 500k rows | migrations + technical note |
| Eager-load only `assignedUser:id,name` on the list; status history only on the view page, paginated | Filament / API index |
| `Model::preventLazyLoading(!app()->isProduction())`: an N+1 throws in dev and tests | AppServiceProvider |
| `select()` only the columns the table shows | `getEloquentQuery()` |
| Capacity = a primary-key lookup on `agent_daily_loads`, never `COUNT(*)` over leads | CapacityLedger |
| Cursor pagination on the API (no `OFFSET` scans) | LeadController@index |
| Bulk action bounded (≤ 500 ids), works on keys | BulkAssignLeads |
| Sweeper uses `chunkById(500)` | EscalateStaleLeadsCommand |
| Search: FULLTEXT on name, prefix match on phone | LeadBuilder |
| Global search and nav badge disabled for leads | LeadResource |
| Slow work on the queue: escalation, webhooks | Jobs |
| Slow-query logging: `DB::whenQueryingForLongerThan(500, ...)` logs offenders | AppServiceProvider |
| Production: `php artisan optimize`, `filament:optimize`, OPcache, Redis queue/cache; Octane optional | README |

**How it is verified (R8, R9.9):**
1. **Query-count test:** render the Filament list (and call the API index) with 5 rows and with 50 rows, and assert the **same** query count (`DB::enableQueryLog`).
2. **`EXPLAIN` on the 500k seed** (`php artisan db:seed --class=LargeLeadDatasetSeeder`, chunked raw inserts of 5,000 rows, a few seconds), with the plans pasted into the technical note.
3. **Dev tooling:** Laravel Debugbar (dev dependency only) for per-request query counts, plus the MySQL slow query log (`long_query_time = 0.2`) for anything we missed.

---

## 15. Testing strategy (R9)

### 15.1 Setup
- MySQL test database `lead_crm_testing` (constitution §1). `RefreshDatabase` for normal tests.
- Concurrency tests use **`DatabaseTruncation`**: their worker processes need to see committed data, which the transaction wrapper of `RefreshDatabase` would hide.

### 15.2 Concurrency proof (R9.1), the required one
`tests/Concurrency/ConcurrentAssignmentTest.php`:
1. Seed 5 active agents (capacity 40 each), 1 inactive agent, and the cursor.
2. Use `Process::pool` to start **8 separate PHP processes**, each running a test-only command `testing:create-leads --count=25` that calls the real `CreateLead` Action. Every worker waits on a shared start timestamp, so they all start inside the same millisecond window and contention is as high as possible.
3. Assertions on 200 leads:
   - every lead has exactly one assignee, and none is assigned to the inactive agent;
   - each agent has **exactly 40** (perfect Round Robin: max − min = 0);
   - `agent_daily_loads.assigned_count` equals the real `COUNT(*)` per agent (the ledger never drifts).
4. **Tight-capacity variant:** total capacity 150 for 200 leads → exactly 150 assigned, 50 unassigned, and every agent exactly at capacity (never over).
5. **Lock-level proof:** connection A opens a transaction and locks the cursor; connection B (with `innodb_lock_wait_timeout = 1`) tries to assign and gets a lock-wait timeout. This shows the serialization point exists.
6. **Concurrent escalation:** 4 processes run the escalation job for the same lead at the same time → exactly 1 escalation row and 1 notification.

The test-only command is registered only when `app()->environment('testing')`.

### 15.3 Requirement → test map

| Req | Test file (Pest) |
|---|---|
| R9.1 | `Concurrency/ConcurrentAssignmentTest` |
| R9.2 | `Feature/Assignment/InactiveAgentsTest` |
| R9.3 | `Feature/Assignment/DailyCapacityTest` (incl. capacity resets the next day, using `travelTo`) |
| R9.4 | `Feature/Authorization/LeadVisibilityTest`: Filament list, Filament view/edit URLs, API show → 404/403; manager sees team only |
| R9.5 | `Feature/Escalation/EscalationSkipsChangedLeadsTest` |
| R9.6 | `Feature/Escalation/EscalationIdempotencyTest` (run twice, plus job + sweeper) |
| R9.7 | `Feature/Assignment/BulkAssignTest`: Filament bulk action + API, over-capacity → nothing assigned, a manager assigning outside the team is rejected, an agent cannot bulk-assign |
| R9.8 | `Feature/Audit/StatusAuditTest` |
| R9.9 | `Feature/Performance/LeadListQueryCountTest` |
| R9.10 | `Feature/Api/*` |
| R9.11 | `Feature/Webhooks/InboundLeadWebhookTest` |
| R9.12 | `Feature/Webhooks/OutboundWebhookTest` (`Http::fake`, rollback → no event, retry → one delivery row) |
| R9.13 | `Feature/Api/OpenApiCoverageTest` |
| Units | `Unit/RoundRobinStrategyTest`, `Unit/HmacSha256SignerTest`, `Unit/OutboundUrlGuardTest`, `Unit/Enums/*` |

### 15.4 Architecture tests (Pest `arch()`): SOLID enforced automatically
```php
arch()->expect('App')->toUseStrictTypes();
arch()->expect('App\Actions')->toBeFinal()->toHaveMethod('handle');
arch()->expect('App\Filament')->not->toUse(['Illuminate\Support\Facades\DB']);       // no logic in Filament
arch()->expect('App\Http\Controllers')->not->toUse(['Illuminate\Support\Facades\DB']);
arch()->expect('App\Assignment')->not->toUse('App\Filament');                        // domain never depends on UI
arch()->expect('App\Data')->toBeReadonly()->toBeFinal();
arch()->preset()->php();  arch()->preset()->security();
```

---

## 16. SOLID map (where each principle is visible)

| Principle | Concrete evidence |
|---|---|
| SRP | `CapacityLedger` (capacity only), `RoundRobinStrategy` (ordering only), `LeadAssigner` (orchestration only), `LeadObserver` (audit only), thin controllers and Filament classes |
| OCP | New assignment strategy, visibility rule, escalation recipient or webhook event = **new class**, no edits to existing ones |
| LSP | `WeightedRoundRobinStrategy` and the fakes used in tests honour the `AssignmentStrategy` contract; `LeadAssigner` cannot tell them apart |
| ISP | `AssignmentStrategy`, `LeadVisibilityRule`, `EscalationRecipientResolver`, `WebhookSigner`, `CurrentActor`, `ProvidesWebhookPayload`: each 1–2 methods |
| DIP | Actions and services depend on interfaces bound in `AppServiceProvider`; time comes from an injected clock (testable), not scattered `now()` calls |

---

## 17. Configuration

`config/leads.php`: `escalation_delay_minutes` (30), `default_daily_capacity` (20), `assignment_strategy` (`round_robin`), `bulk_assign_max` (500).
`config/webhooks.php`: inbound source secrets, timestamp tolerance (300), `allow_private_urls` (false), delivery tries and backoff.

## 18. Seed data (R10.2)

`DatabaseSeeder`: 1 admin, 2 teams each with 1 manager and 3 agents (one inactive, different capacities), about 60 demo leads created **through `CreateLead`** (so Round Robin, audit and escalation are real), and 1 demo webhook subscription. `LargeLeadDatasetSeeder` (opt-in): 500k leads via chunked raw inserts, for performance checks. Demo credentials go in the README; the API token endpoint uses the same accounts.

## 19. Interview discussion: prepared answers (source §11)

| Question | Short answer (expanded in the technical note) |
|---|---|
| How are race conditions prevented? | §6.4: a serialized cursor row lock plus an atomic conditional ledger update, all in one transaction, with fixed lock order and deadlock retry. |
| Two requests at the same time? | The second blocks on `FOR UPDATE` until the first commits, then reads the moved cursor. Proven by the 8-process test and the lock-timeout test. |
| A decision with several valid options? | Pessimistic cursor lock vs Redis lock vs optimistic retry (§6.5), and the observer audit vs explicit logging (§10). |
| Senior agents get twice the volume? | `WeightedRoundRobinStrategy` with `assignment_weight`: one new class plus a config value (§6.6). |
| Agent moves team? | Visibility follows current `team_id` (A-9, §8.4), with the snapshot trade-off explained. |

## 20. Risks & open items

| Risk | Mitigation |
|---|---|
| Local PHP is **8.5**; some packages may not declare support yet | Check during task 1. Fall back to Sail with PHP 8.4 if needed. |
| Filament 5 API details (bulk keys, pagination mode) | Verify against the installed source and docs before writing those parts (§11.1). |
| Process-based concurrency tests can be flaky on slow CI machines | The start barrier, generous timeouts, and assertions on final state only (never on timing). |
| Scope (API + webhooks go beyond the PDF) | Tasks are ordered so the PDF's requirements are finished and tested **first**; the extras come after. |

---

## 21. Implementation notes (deviations from this design)

| Topic | Design said | Implemented | Why |
|---|---|---|---|
| Test framework | Pest 5 | **Pest 4.7** | Pest 5 requires `symfony/process` 8, which conflicts with Laravel 12. |
| Business day (A-3) | App timezone | Timestamps stored in **UTC**; the capacity day uses `LEADS_BUSINESS_TIMEZONE` (default `Africa/Cairo`) via `BusinessClock` | Keeps timestamps unambiguous while "today" follows the business calendar. Covered by a test that crosses Cairo midnight. |
| `LeadAssigner` API | `assignAutomatically(Lead)` after inserting the lead | `claimNextAgent(): ?int` before the insert; `CreateLead` inserts the lead with `assigned_to` already set | One write instead of insert + update; lock order is still cursor → ledger → lead. |
| `AgentDailyLoad` model | Listed | Not created | The ledger is only touched by `CapacityLedger` with explicit SQL; a model added nothing. |
| `AssignmentStrategy::candidates()` | Returns users | Returns `list<int>` ids, takes a `$limit` | Avoids hydrating models inside the lock. |
| Status change audit | Observer | Observer + an `ActorContext` (`CurrentActor` interface) that Actions set explicitly with `runAs()` | The audit records the right user from the API, CLI and jobs, not only from the web guard. |
| FULLTEXT test | Feature test | Moved to `tests/Integration` (committed data) | InnoDB adds rows to FULLTEXT indexes only on commit, so it cannot be tested inside a rolled-back transaction. |
| Index `(status, next_follow_up_at)` | Not planned | Benchmarked on 500k rows and **rejected** | The Overdue COUNT only went from 157 ms to 116 ms; not worth the write cost (technical note §5). |
| Extra command | — | `leads:benchmark` | Prints per-role/filter timings and EXPLAIN plans on demand. |
| Webhook subscription creation | Model save | `CreateWebhookSubscription` Action (authorize + SSRF check + secret generation) | The API and Filament share one code path. |
| Escalation clock (found by edge-case scenario S17) | 30 minutes from `created_at` | 30 minutes from when the lead **last entered** New (latest `new` entry in the audit log) | R5 says "remains in New for 30 minutes": a lead moved New → Contacted → New restarts its clock. The sweeper re-checks every minute. |
| Timestamps with offsets (found by scenario S30) | — | DTOs normalize parsed dates to the app timezone (UTC) | Eloquent stores a datetime's wall-clock time as-is, so `10:00+02:00` was stored as 10:00 UTC instead of 08:00. Covered by an API test. |
| Clean Architecture hardening | Actions dispatched the escalation job and called the Notification facade; LeadBuilder used `app()`; DTOs read `config()` and the Lead model | Escalation is scheduled by the `ScheduleLeadEscalation` listener on `LeadCreated`; notification goes through the `EscalationNotifier` port (`DatabaseEscalationNotifier` adapter); events are published through the injected dispatcher; settings come from the `LeadSettings` value object; visibility is applied by the injected `LeadVisibility`; `UpdateLeadData` carries only provided fields | Dependency rule: inner layers know nothing about queues, notifications, Facades or the container. Enforced by arch tests plus a token-scanning test for `app()` / `config()` / `resolve()`. Eloquent entities and `FilamentUser` stay as documented trade-offs. |
