# Integrating the external `face_auth` service

FaceApp consumes [`face_auth`](https://github.com/mhdFitriM/face_auth) as a
**separate microservice**. None of its code is merged into FaceApp; the Laravel
API talks to its public `/api/v1` surface server-to-server, holding the API key
so the browser never sees it.

> ⚠️ **Overlap warning.** FaceApp already does face enrolment + door control via
> the Java gateway and Hikvision/ISAPI devices. `face_auth` covers similar
> ground with its own Postgres/Redis/MinIO. Run both only if `face_auth` gives
> you something the gateway can't (e.g. its session/QR verification model).

## Architecture

```
Browser (React kiosk)
  │  fetch /api/face-auth/*           (no secrets in the browser)
  ▼
Laravel API  ── FaceAuthClient ──►  face_auth  /api/v1   (Authorization: Bearer <key>)
  │  (holds FACE_AUTH_API_KEY)          │
  │                                     ├── Postgres / Redis / MinIO
  └── logs every attempt               └── Hikvision/ISAPI devices
      (face_auth_attempts table)
```

Both stacks share a Docker network so the Laravel `api` container can reach
`http://face_auth:8080`. They keep separate databases and lifecycles.

## 1. Run face_auth

Clone the repo next to `faceapp-1`, then start its stack as its own project:

```bash
git clone https://github.com/mhdFitriM/face_auth.git ../face_auth
docker compose -p face_auth -f infra/face_auth/docker-compose.face_auth.yml up -d
docker network ls | grep face_auth      # confirm the network name
```

The compose project name (`-p face_auth`) yields a network named
`face_auth_default`. FaceApp joins it via the **opt-in override**
`docker-compose.faceapp-override.yml` — the base stack is never modified, so
`docker compose up` keeps working unchanged when you're not using face_auth.

## 2. Get an API key

Issue a public API key from face_auth (its admin UI or seeding/CLI — see its
README). Note a device id from `GET /api/v1/devices`.

## 3. Configure FaceApp

In the root `.env`:

```env
FACE_AUTH_BASE_URL=http://face_auth:8080     # service name on the shared network
FACE_AUTH_API_KEY=<the key you issued>
FACE_AUTH_DEFAULT_DEVICE_ID=<a device id from face_auth>
```

Then bring FaceApp up **with the override** so the api container can reach
face_auth:

```bash
docker compose \
  -f docker-compose.yml \
  -f infra/face_auth/docker-compose.faceapp-override.yml \
  up -d --build api
docker compose exec api php artisan migrate      # creates face_auth_attempts
docker compose exec api php artisan config:cache # if you cache config
```

## 4. Reverse proxy

No Caddy change is required — the browser only calls FaceApp's own
`/api/face-auth/*` routes, which are already served under `FACEAPP_API_DOMAIN`.
Do **not** expose face_auth through Caddy; keep it on the internal network.

## 5. Use it in the frontend

```jsx
import FaceVerify from './FaceVerify'

<FaceVerify
  personId={user.faceAuthPersonId}   // or employeeNo=...
  onResult={({ status }) => {
    if (status === 'success') unlockTheThing()
  }}
/>
```

## Endpoint mapping

| Browser → FaceApp                         | FaceApp → face_auth                          |
|-------------------------------------------|----------------------------------------------|
| `POST /api/face-auth/start`               | `POST /api/v1/auth/face/start`               |
| `GET  /api/face-auth/session/{id}`        | `GET  /api/v1/auth/face/{id}`                |
| `POST /api/face-auth/session/{id}/cancel` | `POST /api/v1/auth/face/{id}/cancel`         |
| `GET  /api/face-auth/devices`             | `GET  /api/v1/devices`                       |
| `GET  /api/face-auth/persons`             | `GET  /api/v1/persons`                       |

See `TESTING.md` for the verification checklist.
