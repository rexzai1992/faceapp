# Local patches applied to the face_auth service (VPS)

The face_auth service is an **external** dependency (github.com/mhdFitriM/face_auth),
deployed on the VPS at `/opt/apps/face_auth` as its own compose project
(`face_auth`). Two defects in the upstream code blocked a fresh deploy; both are
patched **locally on the VPS clone** and diverge from upstream. **Report these
upstream**, and re-apply after any `git pull` of face_auth until fixed there.

---

## Patch 1 — Migration ordering: `api_keys` altered before it's created

**Symptom:** backend crash-loops on a fresh database:
```
store init: migrate: ERROR: relation "api_keys" does not exist (SQLSTATE 42P01)
```

**Cause:** in `backend/internal/store.go` the single migration string runs
`ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS tenant_id` (~line 327) **before**
`CREATE TABLE IF NOT EXISTS api_keys` (~line 341). On a brand-new DB the ALTER
fails because the table doesn't exist yet.

**Fix applied (data, not code):** pre-create the table once so the idempotent
`IF NOT EXISTS` statements all no-op:
```sql
CREATE TABLE IF NOT EXISTS api_keys (
  id TEXT PRIMARY KEY, name TEXT DEFAULT '', key TEXT NOT NULL UNIQUE,
  last_used_at TIMESTAMPTZ, created_at TIMESTAMPTZ DEFAULT NOW(), tenant_id TEXT
);
```
Run against the `postgres` container's `hikpush` DB. Survives restarts (data in
the `pg_data` volume); **re-run if the volume is wiped**. A proper upstream fix
is to move the `CREATE TABLE api_keys` above the `ALTER`.

---

## Patch 2 — Public `/api/v1/*` shadowed by the session middleware

**Symptom:** every `/api/v1/*` call returns `{"error":"not authenticated"}`
even with a valid API key — the API-key auth never runs.

**Cause:** in `backend/internal/api.go`, the `/api` group's session middleware
is mounted at a prefix that also matches `/api/v1/*`, and it only exempts
`/api/auth/login` and `/api/healthz`. So `sessionAuth` intercepts the public
API before its intended `apiKeyAuth` (registered on the `/api/v1` group) runs.

**Fix applied (one line):** add `/api/v1` to the session-skip list so the public
surface is guarded by `apiKeyAuth` as designed:
```go
// backend/internal/api.go  (~line 92, inside api.Use(...))
- if p == "/api/auth/login" || p == "/api/healthz" {
+ if p == "/api/auth/login" || p == "/api/healthz" || strings.HasPrefix(p, "/api/v1") {
```
Then rebuild the backend: `docker compose up -d --build backend`.

**This does NOT weaken auth.** `/api/v1/*` remains authenticated — by API key
instead of session. Verified after the patch:

| Request | Result |
|---|---|
| `/api/v1/*` no key | `401 missing api key` |
| `/api/v1/*` wrong key | `401` |
| `/api/v1/*` valid key | `200` + data |
| `/api/auth/login` good / bad | `200` / `401` |
| `/api/*` admin route w/o session | `401` |

---

## Operational notes

- **API key:** minted via the seeded HQ admin (`hq@faceauth.local` / `changeme`
  — change this) → `POST /api/api-keys`. New keys get `tenant_id` assigned on the
  next backend restart (or set it directly). The key used by Laravel lives only
  in `/opt/apps/faceapp/.env` (`FACE_AUTH_API_KEY`) and
  `/opt/apps/face_auth/.laravel_api_key` — never in git.
- **Networking:** Laravel's `api` container joins face_auth's `face_auth_default`
  network via `infra/face_auth/docker-compose.faceapp-override.yml`; it reaches
  the backend as `http://backend:8080` (`FACE_AUTH_BASE_URL`). face_auth's API
  port is published on host `18080` (host `8080` was taken by the control panel).
- **Change the HQ admin password** (`changeme`) and consider stronger
  Postgres/MinIO credentials before real production use.
