# Agent Workspace (Angular 22)

A single-page app for sales users, built **only** on the Lead CRM REST API (`/api/v1`). It is served by Laravel at `/workspace`.

## Develop

```bash
npm ci
npm start        # http://localhost:4200/workspace/ (proxies /api to http://127.0.0.1:8000)
```

Run `php artisan serve` in the project root at the same time.

## Build

```bash
npm run build    # writes ../public/workspace-assets (committed, so reviewers need no Node)
```

## Structure

```
src/app/
├── core/       API client, models, auth store (signals), interceptors, guards, theme, toasts
├── shared/     status/priority badges, relative-time pipe, icons, toaster
└── features/   login · shell (layout) · dashboard · leads (list, drawer, bulk assign)
```

- **Auth:** `POST /auth/tokens` → bearer token in `sessionStorage`. `authInterceptor` adds it; `apiErrorInterceptor` turns the API's error envelope into a typed `ApiError` and signs out on 401.
- **Authorization:** never decided here. Controls are hidden only for convenience; the API enforces everything.
