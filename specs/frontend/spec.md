# Spec: Frontend (Filament dashboard + Agent Workspace SPA)

> Status: **APPROVED**, with the SPA in **Angular** (user's choice, so they can explain and modify it in the interview).
> Follows `../constitution.md`. Builds on `../lead-management/` (R-numbers refer to that requirements file).
> Two parts: **A**, an upgraded Filament admin experience; **B**, a standalone SPA on the REST API.

---

## 1. Goals

1. Give reviewers something polished to look at immediately, without hurting the backend focus of the assessment.
2. **Part A** shows *Filament customization*, which the PDF's objective explicitly mentions.
3. **Part B** proves that the business logic really is reusable outside Filament (PDF §9): a second, independent UI built only on the public API.
4. Reviewers must not need Node.js: the SPA build output is committed.

## 2. Requirements

### Part A: Filament

| ID | Requirement |
|---|---|
| FA1 | Branding: app name, logo/favicon (SVG), brand color palette, Inter font, dark mode, SPA navigation mode, collapsible sidebar. |
| FA2 | **Role-aware dashboard**. Every number is scoped with `visibleTo()`: admins see everything, managers their team, agents their own leads. |
| FA3 | Stats cards: open leads, overdue, new today, escalated today, unassigned (admins only). |
| FA4 | Chart: leads created per day, last 14 days. |
| FA5 | Chart: lead status distribution. |
| FA6 | "Capacity today" widget: each assignable agent with used / capacity and a progress indicator (admins: all agents, managers: their team, agents: hidden). |
| FA7 | "Needs attention" table: the 10 most overdue open leads, with a link to each lead. |
| FA8 | **Database notifications bell**. Escalations already write database notifications (R5), so managers and admins see them in the panel, polled every 30 s. |
| FA9 | Performance: each widget runs **one** scoped query, cached for 60 s per scope (cache key = role + user/team). Widgets lazy-load. Verified on the 500k dataset. |

### Part B: Agent Workspace SPA

| ID | Requirement |
|---|---|
| FB1 | Served at **`/workspace`** by Laravel (same origin, so there is no CORS). The source lives in `frontend/`, and the build output is committed to `public/workspace/`. |
| FB2 | Login with the demo accounts through `POST /api/v1/auth/tokens`. The token is kept in `sessionStorage`. Logout revokes the token. Any 401 sends the user back to login. |
| FB3 | Dashboard: the same stats as FA3 for the current user. |
| FB4 | Leads list: search, filter chips (My Leads, Unassigned for admins, Overdue, High Priority), a status filter, cursor-based "Load more", status/priority badges, time until follow-up with overdue highlighting. |
| FB5 | Lead detail drawer: lead info, a status history timeline, and a "Change status" control. |
| FB6 | Bulk assign (admins and managers): select rows, pick an agent from a list showing remaining capacity, submit. The API's 409/403 errors are shown clearly (e.g. "only 1 slot left today"). |
| FB7 | Responsive layout (mobile to desktop), light/dark, accessible (labels, focus states, keyboard). |
| FB8 | The UI never makes authorization decisions on its own: it hides controls for convenience, and the API remains the authority. |

### New API endpoints (needed by Part B; reuse the existing domain code)

| ID | Endpoint | Source of truth |
|---|---|---|
| FC1 | `GET /api/v1/me`: user, role, team, today's capacity used/remaining | `CapacityLedger`, `BusinessClock` |
| FC2 | `GET /api/v1/assignable-agents`: agents the caller may assign to, with remaining capacity | `AssigneeEligibility::assignableAgentsWithRemaining()` |
| FC3 | `GET /api/v1/dashboard/stats`: the same numbers as the Filament stats cards | the new `LeadDashboardStats` query class (shared with Part A) |

All three get Swagger docs, Pest tests, and coverage by the existing OpenAPI drift test.

## 3. Design

### 3.1 Shared stats (one implementation, two UIs)

`App\Queries\LeadDashboardStats` (final, injectable) exposes `summary(User)`, `createdPerDay(User, days)`, `statusBreakdown(User)`, `needsAttention(User, limit)`, and `capacityToday(User)`. Each method:
- starts from `Lead::query()->visibleTo($user)` (or `AssigneeEligibility` for capacity), so the authorization rules are the same everywhere;
- runs a single aggregate query (`COUNT … CASE WHEN`, `GROUP BY`), never loading rows into PHP;
- is wrapped in `Cache::remember("dash:{scope}:{metric}", 60s)`, where scope = `admin`, `team:{id}` or `agent:{id}`.

Filament widgets and `DashboardStatsController` are thin adapters over this class. An arch test keeps widgets free of the `DB` facade.

**Index check:** "created per day, last 14 days" for an admin scans `created_at` in a range. It will be measured on 500k rows. If it is slow, the options are an index on `created_at` or deriving the range from the primary key (ids are monotonic with time); the measurement decides, and the decision goes in the technical note.

### 3.2 Filament

- Panel: `->brandName()`, `->brandLogo()`, `->favicon()`, `->colors([... indigo primary ...])`, `->font('Inter')`, `->darkMode()`, `->spa()`, `->sidebarCollapsibleOnDesktop()`, `->databaseNotifications()->databaseNotificationsPolling('30s')`.
- Widgets in `app/Filament/Widgets`: `LeadStatsOverview`, `LeadsCreatedChart` (line), `LeadStatusChart` (doughnut), `AgentCapacityTable`, `NeedsAttentionTable`. Each one's `canView()` controls role visibility.
- The default `FilamentInfoWidget` is removed.

### 3.3 SPA stack

| Concern | Choice | Why |
|---|---|---|
| Framework | **Angular 22**: standalone components, **signals**, new control flow (`@if`/`@for`), zoneless change detection | The user knows Angular, and modern Angular needs no NgModules |
| Build | Angular CLI (esbuild): `outputPath ../public/workspace`, `baseHref /workspace/` | Output is committed, so reviewers need no Node |
| Styling | Tailwind CSS 4 | Utility classes; no component library to learn or ship |
| HTTP | `HttpClient` + **functional interceptors**: one adds the bearer token, one maps errors to a typed `ApiError` and logs out on 401 | Auth and error handling live in one place |
| State | Injectable services exposing signals (`AuthStore`, `LeadsStore`) | Simple, typed, no extra state library |
| Routing | Angular Router with `authGuard` / `guestGuard` (functional guards), lazy-loaded pages | `/workspace/login`, `/workspace`, `/workspace/leads` |
| Forms | Reactive Forms (login, status change, bulk assign) | Typed validation |

Structure: `frontend/src/app/{core (api, auth, interceptors, guards, models), shared (badges, time-until, ui), features (login, dashboard, leads)}`. API types are hand-written to mirror the OpenAPI schemas.

Laravel: `Route::get('/workspace/{path?}', ...)->where('path', '.*')` returns `public/workspace/index.html`. A Pest test asserts the shell loads.

### 3.4 Security notes

- Keeping the token in `sessionStorage` (not `localStorage`) limits its lifetime to the tab. The XSS trade-off versus httpOnly cookies (Sanctum SPA mode) is documented. Angular sanitizes bound values by default, and no `bypassSecurityTrust*` is used.
- No secrets in the bundle. Same origin, so no CORS configuration is needed.

## 4. Tasks

| # | Task | Done when | Status |
|---|---|---|---|
| F1 | `LeadDashboardStats` query class + unit/feature tests (scoping per role, cache) | Tests pass; each method = 1 query | ✅ |
| F2 | API: `/me`, `/assignable-agents`, `/dashboard/stats` + Swagger + tests | OpenAPI drift test passes | ✅ |
| F3 | Filament branding + notifications bell | Panel loads with new theme; escalation notification visible to manager | ✅ |
| F4 | Filament widgets (stats, 2 charts, capacity, needs attention) + role tests | Livewire tests per role; dashboard ≤ 1 query per widget | ✅ |
| F5 | Measure the dashboard on 500k rows; add or reject the `created_at` index with numbers | Numbers in the technical note | ✅ |
| F6 | Angular scaffold (standalone, signals, Tailwind), interceptors, guards, auth flow, layout | `npm run build` passes (strict TS) | ✅ |
| F7 | SPA pages: dashboard, leads list + filters + load more, detail drawer + status change, bulk assign | Checked manually in the browser for each role | ✅ |
| F8 | Laravel route `/workspace`, committed build, README + technical note updates | Fresh clone shows `/workspace` without Node | ✅ |

## 5. Implementation notes

| Topic | Spec said | Implemented | Why |
|---|---|---|---|
| Build output | `public/workspace/` | `public/workspace-assets/` (Angular `deployUrl`), shell named `app.html`, URL stays `/workspace` | PHP's built-in server maps a URL that matches a real directory with an index file to that file + PATH_INFO, which breaks deep links. Different names avoid it on every server. |
| Created-per-day index | To be measured | `idx_leads_created (created_at)` added | 149 ms → 16 ms on 500k rows (technical note §5). |
| Summary query | One aggregate | Separate indexed COUNTs | 386 ms → 234 ms cold for an admin on 500k rows. |
| Escalation notification payload | — | Built with Filament's notification builder + domain keys | Renders in the bell and stays useful for API consumers. |
| Guards | — | `inject()` resolved before the first `await` | `inject()` is only valid synchronously in the injection context (caught as NG0203 during browser verification). |
