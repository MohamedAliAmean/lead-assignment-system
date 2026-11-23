# Tasks: Lead Assignment & Follow-up System

> Status: **DONE**: 119 tests / 564 assertions passing on MySQL 8.4. Implements `design.md`. Each task lists its requirement IDs.
> Order: PDF requirements first (T1–T9), extras after (T10–T12), deliverables last (T13).

| # | Task | Reqs | Done when | Status |
|---|---|---|---|---|
| T1 | Scaffold Laravel 12, Filament 5, Pest 4 (see design §21), Sanctum; MySQL 8.4 via docker-compose (port 3307); test DB; strict types; lazy-loading guard; slow-query log | Const. §1, §5 | `php artisan about` OK, the empty Pest suite passes on MySQL | ✅ |
| T2 | Enums, migrations (all tables + indexes + CHECK constraints), models, `LeadBuilder`, factories | R1, R2 | `migrate:fresh` OK; model/enum unit tests pass | ✅ |
| T3 | `CapacityLedger`, `AssignmentStrategy` + `RoundRobinStrategy`, `LeadAssigner`, `CreateLead`, `LeadObserver` audit, `CurrentActor` | R3, R4 | Tests: Round Robin order, inactive skipped, capacity skipped, none eligible → unassigned, audit rows | ✅ |
| T4 | Visibility rules + `LeadVisibility`, `LeadPolicy`, `EnsureUserIsActive`, `canAccessPanel` | R6 | Visibility tests per role | ✅ |
| T5 | `ChangeLeadStatus`, `UpdateLead`, `BulkAssignLeads`, domain exceptions | R4, R6.6, R7.5 | Bulk assign tests (auth + capacity + all-or-nothing) | ✅ |
| T6 | `EscalationRecipientResolver`, `EscalateLead`, `EscalateStaleLead` job, notification, sweeper command + schedule | R5 | Escalation tests (changed status, twice, sweeper) | ✅ |
| T7 | Filament panel, `LeadResource` (columns, filters, bulk action, status action, history), user and team management for admins | R7, R2.4 | Filament Livewire tests incl. direct URL denial | ✅ |
| T8 | Concurrency proof: test-only worker command, 8-process test, tight capacity, lock-timeout test, concurrent escalation | R9.1, R3.5 | Passes repeatedly (run 5×) | ✅ |
| T9 | Query-count test, arch tests | R8.1, R9.9, Const. §4 | Pass | ✅ |
| T10 | REST API v1: token, leads CRUD, status, history, bulk assign; Form Requests, Resources, exception mapping, cursor pagination, rate limiting | R11.1–R11.6 | API feature tests | ✅ |
| T11 | Swagger (l5-swagger attributes), schemas, `/api/documentation`, OpenAPI coverage test | R11.7, R11.8 | Coverage test passes; UI loads | ✅ |
| T12 | Webhooks: inbound (signature, replay, de-dup), outbound (events after commit, listener, deliveries, signed job, retries, SSRF guard), subscriptions API + Filament resource | R12 | Webhook tests | ✅ |
| T13 | Demo seeder, 500k seeder + `EXPLAIN` capture, README, `docs/TECHNICAL_NOTES.md` | R8.5, R10 | A fresh clone runs by following the README | ✅ |
