# Requirements: Lead Assignment & Follow-up System

> Status: **APPROVED** (v2: adds API, Swagger and webhooks on request)
> Source: *Backend Technical Assessment: Lead Assignment & Follow-up System* (PDF)
> Format: user stories with acceptance criteria written in EARS style (WHEN / IF / WHILE ... THE SYSTEM SHALL ...).

---

## 0. Glossary

| Term | Meaning |
|---|---|
| Lead | A potential customer record managed by the sales team. |
| Agent | A user with the `sales_agent` role. Only agents can receive leads. |
| Manager | A user with the `sales_manager` role who manages exactly one team. |
| Admin | A user with the `admin` role. Has full access. |
| Team | A group of agents led by one manager. |
| Eligible agent | An agent who is active **and** has not reached their daily capacity. |
| Daily capacity | The maximum number of leads an agent can receive in one calendar day. |
| Escalation | Notifying the responsible manager that a lead has stayed in `new` status too long. |

---

## 1. Assumptions (gaps in the source spec, decided by us)

| ID | Assumption | Rationale |
|---|---|---|
| A-1 | Only **active Sales Agents** take part in Round Robin. Admins and managers never receive leads automatically. | The source says "eligible Sales Agent". |
| A-2 | Round Robin rotates over **all eligible agents in the company** (one global rotation), ordered by a stable key (user id). | A lead has no team when it is created, so a per-team rotation has no input to work from. |
| A-3 | The daily capacity window is the **current calendar day in the business timezone** (`LEADS_BUSINESS_TIMEZONE`, default `Africa/Cairo`); timestamps themselves are stored in UTC. Usage is tracked in a per-agent, per-day counter. | Gives exact numbers without a nightly reset job. |
| A-4 | If **no agent is eligible**, the lead is still created and stays **unassigned** (`assigned_to = NULL`). It appears in the "Unassigned" filter. | Losing an incoming lead is worse than leaving it unassigned. |
| A-5 | The **responsible manager** is the manager of the assigned agent's team at escalation time. If the lead is unassigned or the agent has no team, the escalation goes to all active admins. | Matches the visibility model: managers own their team's leads. |
| A-6 | An escalation is a persisted `lead_escalations` record (**at most one per lead**) plus a database notification to the recipient(s). | The record is the idempotency anchor; the notification is the side effect that must not repeat. |
| A-7 | Bulk assignment is **all-or-nothing**: if the target user lacks capacity for the whole selection, or the actor is not allowed to assign any selected lead, nothing is assigned and a clear error is returned. | Avoids partial state that is hard to reason about. Partial assignment is noted as an alternative in the design notes. |
| A-8 | Manual or bulk assignment **counts against** the target agent's daily capacity, exactly like automatic assignment. | The source requires bulk assignment to respect capacity limits. |
| A-9 | A user belongs to at most one team. Visibility is evaluated from **current** membership: when an agent changes team, their leads become visible to the new manager and stop being visible to the old one. | Simplest consistent rule; discussed in the design notes (source §11). |
| A-10 | Each agent has an assignment **weight** (default 1). Version 1 uses plain Round Robin (all weights = 1). The design must allow weighted Round Robin without schema changes. | Prepares for the interview question about senior agents getting twice the volume. |
| A-11 | Daily capacity counts leads **received** today. Reassigning a lead away from an agent does **not** give that capacity back. | Capacity limits daily intake. Giving capacity back would let one agent be refilled over and over in a single day. |
| A-12 | If `next_follow_up_at` is not given on creation, it defaults to creation time + the escalation delay (30 min). | Every new lead then has a visible follow-up deadline in the table. |
| A-13 | The REST API (beyond the PDF) is a second delivery channel over the **same** application layer as Filament. It proves that business logic is reusable outside Filament (source §9). | Turns an architecture claim into something reviewers can run. |
| A-14 | Webhooks (beyond the PDF): **inbound** webhooks let external lead sources (website form, ad platforms) push leads in; **outbound** webhooks notify external systems about lead events. Delivery is at-least-once, with a unique id per delivery so receivers can de-duplicate. | Matches the `source` field and typical CRM integrations. At-least-once is the only guarantee an HTTP delivery can honestly make. |
| A-15 | API authentication uses Laravel Sanctum personal access tokens. | First-party, simple, and fits a stateless API. |

---

## 2. Requirements

### R1: Lead data model

**User story:** As the business, I want a well-structured lead record so data stays consistent and queryable at scale.

| ID | Acceptance criteria |
|---|---|
| R1.1 | THE SYSTEM SHALL store leads with at least: `name`, `phone`, `source`, `priority`, `status`, `assigned_to`, `next_follow_up_at`. |
| R1.2 | THE SYSTEM SHALL restrict `status`, `priority` and `source` to defined sets of values (backed PHP enums). |
| R1.3 | THE SYSTEM SHALL make `assigned_to` a nullable foreign key to `users`. |
| R1.4 | THE SYSTEM SHALL record when a lead was assigned (`assigned_at`) to support capacity counting. |
| R1.5 | THE SYSTEM SHALL define Eloquent relationships: Lead → assigned user, Lead → status logs, Lead → escalation, User → leads, User → team, Team → manager and members. |
| R1.6 | THE SYSTEM SHALL define indexes that support the list filters, authorization scopes and capacity counting (justified in the design). |

Statuses (initial set): `new`, `contacted`, `qualified`, `unqualified`, `won`, `lost`.
Priorities: `low`, `medium`, `high`.

### R2: Users, roles and teams

| ID | Acceptance criteria |
|---|---|
| R2.1 | THE SYSTEM SHALL support exactly three roles: `admin`, `sales_manager`, `sales_agent`. |
| R2.2 | THE SYSTEM SHALL store per user: `is_active` (boolean), `daily_capacity` (unsigned integer, agents only), `assignment_weight` (unsigned integer, default 1). |
| R2.3 | THE SYSTEM SHALL let each team have exactly one manager and many agents. |
| R2.4 | THE SYSTEM SHALL let an admin change a user's `daily_capacity` and `is_active` without a deploy (configurable capacity). |

### R3: Automatic Round Robin assignment

**User story:** As a sales manager, I want new leads distributed fairly and automatically so no lead waits for manual triage.

| ID | Acceptance criteria |
|---|---|
| R3.1 | WHEN a lead is created without an explicit assignee, THE SYSTEM SHALL assign it to the next eligible agent in Round Robin order. |
| R3.2 | THE SYSTEM SHALL NOT assign a lead to an inactive user. |
| R3.3 | THE SYSTEM SHALL NOT assign a lead to an agent who has reached their daily capacity. Such agents are skipped and the rotation continues with the next agent. |
| R3.4 | IF no agent is eligible, THE SYSTEM SHALL create the lead unassigned (A-4). |
| R3.5 | WHEN two or more leads are created concurrently, THE SYSTEM SHALL give each lead exactly one assignee, SHALL NOT give any agent more leads than their capacity, and SHALL keep the Round Robin order consistent (no two concurrent creations take the same "turn"). |
| R3.6 | THE SYSTEM SHALL perform assignment inside a database transaction, using row locking or atomic updates, so that the lead row and the rotation state are updated together or not at all. |
| R3.7 | THE SYSTEM SHALL expose assignment as an application service that can be called outside Filament. |

### R4: Status change audit log

**User story:** As a manager, I want a history of every status change so I can see who did what and when.

| ID | Acceptance criteria |
|---|---|
| R4.1 | WHEN a lead's status changes, THE SYSTEM SHALL store an audit entry with `lead_id`, `user_id`, `old_status`, `new_status`, `changed_at`. |
| R4.2 | THE SYSTEM SHALL write the audit entry in the same transaction as the status change. |
| R4.3 | IF the status is saved with an unchanged value, THE SYSTEM SHALL NOT write an audit entry. |
| R4.4 | WHEN the status is changed by a system process (for example a job) with no authenticated user, THE SYSTEM SHALL store `user_id = NULL`. |
| R4.5 | THE SYSTEM SHALL implement the audit without third-party audit packages. |
| R4.6 | WHERE the lead detail page is shown, THE SYSTEM SHALL display the lead's status history. |

### R5: Delayed follow-up escalation

**User story:** As a manager, I want to be alerted when a lead is ignored for 30 minutes so response times stay low.

| ID | Acceptance criteria |
|---|---|
| R5.1 | WHEN a lead is created with status `new`, THE SYSTEM SHALL dispatch a queued escalation job delayed by 30 minutes (the delay is configurable). |
| R5.2 | WHEN the job runs, THE SYSTEM SHALL reload the lead from the database and check its current state before acting. |
| R5.3 | IF the lead no longer exists or its status is no longer `new`, THE SYSTEM SHALL do nothing. |
| R5.4 | IF the lead is still `new`, THE SYSTEM SHALL create one escalation record and notify the responsible manager (A-5, A-6). |
| R5.5 | WHEN the job runs more than once for the same lead (retry, duplicate dispatch, or concurrent workers), THE SYSTEM SHALL create at most one escalation record and send at most one notification. |
| R5.6 | IF the job fails part-way, THE SYSTEM SHALL leave lead and escalation data consistent (no partial writes), and a retry SHALL complete the escalation or do nothing. |
| R5.7 | THE SYSTEM SHALL NOT change the lead's status or assignee as part of escalation. |

### R6: Authorization and data visibility

**User story:** As the business, I want each user to see only the leads they are responsible for.

| ID | Acceptance criteria |
|---|---|
| R6.1 | THE SYSTEM SHALL let an admin view and manage all leads. |
| R6.2 | THE SYSTEM SHALL let a sales manager view and manage only leads assigned to members of their team (A-9). |
| R6.3 | THE SYSTEM SHALL let a sales agent view and manage only leads assigned to them. |
| R6.4 | THE SYSTEM SHALL enforce R6.1–R6.3 at query level (list, search, filters, counts) **and** at record level (view, edit, delete, status change, assign). |
| R6.5 | WHEN a user opens a URL for a lead they are not allowed to access, THE SYSTEM SHALL deny access (403 or 404). |
| R6.6 | THE SYSTEM SHALL allow only admins and managers to assign or reassign leads; a manager may only assign to active agents in their own team. |
| R6.7 | THE SYSTEM SHALL NOT allow inactive users to sign in to the admin panel. |

### R7: Filament lead management

**User story:** As a sales user, I want a practical lead screen to work my queue efficiently.

| ID | Acceptance criteria |
|---|---|
| R7.1 | THE SYSTEM SHALL list leads with the columns: Name, Phone, Source, Status, Assigned User, Time Until Follow-up. |
| R7.2 | THE SYSTEM SHALL show "Time Until Follow-up" as a human-readable relative time and clearly mark overdue values. |
| R7.3 | THE SYSTEM SHALL provide the filters: **My Leads** (assigned to me), **Unassigned** (`assigned_to` is null), **Overdue** (`next_follow_up_at` < now and status not closed), **High Priority** (`priority = high`). |
| R7.4 | THE SYSTEM SHALL provide a bulk action "Assign to user" that calls the application-layer bulk assignment (no business logic in Filament). |
| R7.5 | WHEN a bulk assignment would break authorization or the target's daily capacity, THE SYSTEM SHALL reject it in full (A-7) and show a clear error message. |
| R7.6 | THE SYSTEM SHALL let users with access create, view, edit and change the status of leads through the resource. |

### R8: Performance at scale (~500,000 leads)

| ID | Acceptance criteria |
|---|---|
| R8.1 | THE SYSTEM SHALL render the lead list with a fixed number of queries, independent of page size (no N+1). |
| R8.2 | THE SYSTEM SHALL eager-load only the relationships shown in the table (the assigned user). |
| R8.3 | THE SYSTEM SHALL paginate the list and SHALL NOT load the full table into memory in any request, job or bulk action. |
| R8.4 | THE SYSTEM SHALL back every default filter and authorization scope with an index, verified with `EXPLAIN`. |
| R8.5 | THE SYSTEM SHALL provide a seeder that can generate about 500k leads for performance checks. |

### R11: REST API and Swagger documentation

**User story:** As an integrator or a reviewer, I want a documented API so I can use and inspect the system without the admin panel.

| ID | Acceptance criteria |
|---|---|
| R11.1 | THE SYSTEM SHALL expose a versioned JSON API under `/api/v1` that uses the same Actions, policies and visibility scopes as Filament (no duplicated business logic). |
| R11.2 | THE SYSTEM SHALL provide endpoints to: issue a token, list leads (with the same filters as Filament), show, create, update a lead, change its status, read its status history, and bulk-assign leads. |
| R11.3 | THE SYSTEM SHALL authenticate API requests with Sanctum bearer tokens and apply the same authorization as R6. Unauthorized record access returns 403/404. |
| R11.4 | THE SYSTEM SHALL validate input with Form Requests and return consistent JSON errors (422 validation, 403/404 authorization, 409 conflict for capacity or state conflicts). |
| R11.5 | THE SYSTEM SHALL paginate list endpoints with cursor pagination, so deep pages stay fast on a large table. |
| R11.6 | THE SYSTEM SHALL rate-limit API requests (per token / per IP for the token endpoint). |
| R11.7 | THE SYSTEM SHALL publish an OpenAPI 3 specification and serve it in **Swagger UI** at `/api/documentation`, covering every endpoint, schema, auth scheme and error response. |
| R11.8 | A test SHALL fail if any `/api/v1` route is missing from the OpenAPI document, so the docs cannot silently drift from the code. |

### R12: Webhooks

**User story:** As the business, I want leads from external sources to flow in automatically, and other systems to be told when something important happens to a lead.

**Inbound (lead capture)**

| ID | Acceptance criteria |
|---|---|
| R12.1 | THE SYSTEM SHALL accept inbound leads at `POST /api/v1/webhooks/leads` and create them through the same CreateLead action (so auto-assignment, audit and escalation all apply). |
| R12.2 | THE SYSTEM SHALL verify an HMAC-SHA256 signature over the timestamp and the raw body using a per-source secret, and SHALL reject requests that are unsigned, wrongly signed, or older than 5 minutes (replay protection). |
| R12.3 | WHEN the same external lead is delivered more than once (same source + external id), THE SYSTEM SHALL return the existing lead and SHALL NOT create a duplicate (database unique constraint). |

**Outbound (event notifications)**

| ID | Acceptance criteria |
|---|---|
| R12.4 | THE SYSTEM SHALL let an admin manage webhook subscriptions (URL, secret, subscribed events, active flag). |
| R12.5 | THE SYSTEM SHALL emit these events: `lead.created`, `lead.assigned`, `lead.status_changed`, `lead.escalated`. |
| R12.6 | THE SYSTEM SHALL send webhooks from queued jobs, and only after the database transaction that caused the event has committed. |
| R12.7 | THE SYSTEM SHALL sign each delivery (HMAC-SHA256) and include a unique delivery id, event name and timestamp in the headers. |
| R12.8 | THE SYSTEM SHALL retry failed deliveries with exponential backoff, record each delivery's status, attempts and last response, and SHALL NOT create two delivery records for the same event and subscription. |
| R12.9 | THE SYSTEM SHALL only allow `https` subscription URLs that do not resolve to private or loopback addresses (SSRF protection). Local development may relax this with a config flag. |

### R9: Testing

| ID | Acceptance criteria |
|---|---|
| R9.1 | Test: concurrent lead creation keeps Round Robin correct (real parallel processes on MySQL). |
| R9.2 | Test: inactive users never receive leads. |
| R9.3 | Test: agents at capacity do not receive new leads. |
| R9.4 | Test: an agent cannot access another agent's lead (list scope and direct URL). |
| R9.5 | Test: the escalation job does nothing if the status changed before it runs. |
| R9.6 | Test: running the escalation job twice creates no duplicate escalation or notification. |
| R9.7 | Test: bulk assignment respects authorization and daily capacity. |
| R9.8 | Test: a status change writes exactly one audit row with the correct values. |
| R9.9 | Test: the lead list query count does not grow with the number of rows. |
| R9.10 | Tests: API endpoints apply the same authorization, validation and capacity rules as Filament. |
| R9.11 | Tests: inbound webhook signature check, replay rejection, and de-duplication. |
| R9.12 | Tests: outbound webhooks are dispatched after commit, are signed, retried, and never duplicated per event + subscription. |
| R9.13 | Test: every `/api/v1` route is documented in the OpenAPI spec (R11.8). |

### R10: Deliverables

| ID | Acceptance criteria |
|---|---|
| R10.1 | Complete source code in a git repository. |
| R10.2 | Migrations, factories and seeders (demo users and teams, plus an optional large dataset). |
| R10.3 | An automated test suite runnable with a single command. |
| R10.4 | A README with setup, run, queue-worker and test commands, demo credentials, and the Swagger UI link. |
| R10.5 | A technical note covering assignment strategy, concurrency protection, authorization approach, queue idempotency, performance decisions (indexes, eager loading, query inspection), assumptions, and answers to the discussion points in §11 of the source spec. |

---

## 3. Out of scope

- UI polish and custom theming.
- Real-time notifications (database notifications are enough).
- Email/SMS channels for escalation (the recipient resolver and notification class make them a small addition).
- Weighted Round Robin at runtime (designed for, not enabled; see A-10).

## 4. Traceability

| Source section | Requirements |
|---|---|
| §1 Core domain | R1, R2 |
| §2 Auto assignment | R3 |
| §3 Audit log | R4 |
| §4 Escalation | R5 |
| §5 Filament | R7 |
| §6 Roles | R6 |
| §7 Tests | R9 |
| §8 Performance | R8 |
| §9 Architecture | Constitution §2–3, R3.7 |
| §10 Deliverables | R10 |
| §11 Discussion | A-9, A-10, R10.5 |
| Beyond the PDF (requested) | R11 API + Swagger, R12 Webhooks, Constitution §4 SOLID, §5 Performance |
