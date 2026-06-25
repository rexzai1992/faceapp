# face_auth integration — testing checklist

Run face_auth and FaceApp, then work top to bottom. `$KEY` is your
`FACE_AUTH_API_KEY`; `$BASE` is the published face_auth URL (e.g.
`http://localhost:8080`); `$API` is FaceApp's API origin.

## A. Direct face_auth checks (bypass FaceApp)

```bash
# 1. Liveness
curl -s $BASE/api/v1/ping

# 2. API-key auth — should 401 without the key, 200 with it
curl -s -o /dev/null -w "%{http_code}\n" $BASE/api/v1/devices
curl -s -H "Authorization: Bearer $KEY" $BASE/api/v1/devices
curl -s -H "X-API-Key: $KEY" $BASE/api/v1/devices    # alternate header

# 3. Persons + pick a device id
curl -s -H "Authorization: Bearer $KEY" $BASE/api/v1/persons
```

## B. Through FaceApp (what the browser actually uses)

```bash
# 4. Proxy passes through (no key needed from the caller — FaceApp adds it)
curl -s $API/api/face-auth/devices
curl -s $API/api/face-auth/persons

# 5. Start a session
curl -s -X POST $API/api/face-auth/start \
  -H 'Content-Type: application/json' \
  -d '{"person_id":"<id>"}'
# → { ok:true, session_id, status:"pending", poll:{...} }

# 6. Poll until terminal
curl -s $API/api/face-auth/session/<session_id>

# 7. Cancel
curl -s -X POST $API/api/face-auth/session/<session_id>/cancel
```

## C. Case matrix

| Case             | How to trigger                                  | Expected (FaceApp)                              |
|------------------|-------------------------------------------------|-------------------------------------------------|
| **Success**      | Present an enrolled face at the device          | session status → `success`; `face_auth_attempts.status=success` |
| **Failure**      | Present an unknown face                          | status → `failed`, with reason                  |
| **Timeout**      | Start, then don't present a face for > max secs  | frontend stops at `timeout`, calls cancel       |
| **QR-required**  | Start a flow needing QR (no `qr_token`)          | status → `qr_required`; UI prompts for QR       |
| **Cancellation** | Click Cancel mid-flow                             | status → `cancelled`; upstream session cancelled |
| **Device offline** | Stop the device / use a bad `device_id`        | `502 face_auth_failed` with upstream message    |
| **Bad API key**  | Set a wrong `FACE_AUTH_API_KEY`, restart api     | `502` (our key is wrong — not the caller's fault) |
| **face_auth down** | Stop the face_auth stack                        | `502 face_auth_unavailable`                     |

## D. Kiosk check-in flow (face verify → turnstile)

### Real face_auth session payload (verified from source)

`GET /api/v1/auth/face/{id}` returns the `QRSession` struct
(`backend/internal/qr_auth.go`). A **successful person-scoped** check-in looks
like:

```json
{
  "id": "b1c2…",
  "personId": "42",
  "employeeNo": "0042",
  "name": "Jane Doe",
  "deviceId": "hik-lobby-01",
  "openedAt": "2026-06-24T09:15:00Z",
  "expiresAt": "2026-06-24T09:16:00Z",
  "mode": "face-only",
  "status": "face_matched",
  "matchedEmployeeNo": "0042",
  "source": "api"
}
```

**Status vocabulary (the only values that occur):**

| status         | meaning            | kiosk handling            |
|----------------|--------------------|---------------------------|
| `open`         | in progress        | keep polling              |
| `face_matched` | **success**        | → complete → open gate    |
| `timed_out`    | window expired     | blocked, "Session expired"|
| `cancelled`    | aborted            | blocked                   |

`qr_required` is **not** a session status — it's a `409 {"error":"qr_required"}`
returned by `POST /auth/face/start`. The kiosk maps that to its QR message.

**Field mapping used by Laravel** (`KioskCheckinController::resolveMatchedIdentity`):

| face_auth field      | role                                            |
|----------------------|-------------------------------------------------|
| `matchedEmployeeNo`  | device-confirmed employee no (authoritative)    |
| `employeeNo`         | employee no the session was scoped to           |
| `personId`           | person id the session was scoped to             |

On `face_matched`, face_auth only sets the status when the device-reported
employee equals the scoped person, and `matchedEmployeeNo` carries that
device-confirmed id — so it is always present on success and matches the
member. No face_auth backend patch was required; the strong identity fields
already exist.


`$API` is FaceApp's origin. The browser uses these two endpoints; it polls the
read-only `/api/face-auth/session/{id}` in between.

```bash
# Start a check-in for a member (kiosk_id + member_id required, plus a face_auth identity)
curl -s -X POST $API/api/kiosk/face-checkin/start \
  -H 'Content-Type: application/json' \
  -d '{"kiosk_id":"kiosk-1","member_id":"42","employee_no":"0042"}'
# → { ok:true, session_id, poll_url:"/api/face-auth/session/<id>", poll:{...} }

# Poll until verified
curl -s $API/api/face-auth/session/<session_id>

# Complete — server re-checks status, verifies identity, opens the gate ONCE
curl -s -X POST $API/api/kiosk/face-checkin/<session_id>/complete
# → { ok:true, status:"granted", gate_opened:true, gate_mode:"app_turnstile" }
```

### Identity verification is fail-closed

> **Production must keep `FACE_AUTH_REQUIRE_MATCHED_IDENTITY=true`.** With it on,
> the gate opens only when face_auth returns a strong identity field
> (`personId`, `person_id`, `employeeNo`, `employee_no`, `fpid`/`FPID`,
> `userId`/`user_id`, `matchedPersonId`/`matched_person_id`,
> `matchedEmployeeNo`/`matched_employee_no`) that matches the member from the
> local audit row. A missing identity is **blocked**, not trusted.
>
> `FACE_AUTH_REQUIRE_MATCHED_IDENTITY=false` is **for local integration testing
> only.** It re-enables the old fallback for the *missing-identity* case (a
> *mismatched* identity is always blocked). The fallback is never silent: it
> logs a warning and stamps `identity_fallback_used=true` on the audit row.

### Kiosk case matrix

| Case                                 | How to trigger                                                   | Expected                                                                 |
|--------------------------------------|------------------------------------------------------------------|--------------------------------------------------------------------------|
| **Success — matching employeeNo**    | Enrolled member; face_auth returns `employee_no` = member's       | complete → `granted`, gate opens, `gate_opened=true`                     |
| **Success — matching personId**      | Enrolled member; face_auth returns `personId` = member's          | complete → `granted`, gate opens                                         |
| **Missing identity → blocked**       | face_auth returns verified but **no** strong identity field       | complete → `409 identity_missing`; gate **not** opened; status=`identity_missing` |
| **Wrong identity → blocked**         | Start for member A; face_auth matches person B                    | complete → `409 identity_mismatch`; gate **not** opened; status=`identity_mismatch` |
| **Failed face_auth status → blocked**| Present an unknown face (status failed)                           | "Face verification failed"; gate **not** opened                          |
| **Duplicate complete after grant**   | Call `/complete` twice on a granted session                       | first → `granted gate_opened:true`; second → `already_open:true`, **no second open** |
| **Device offline**                   | Stop the terminal / bad `device_id` → start returns 503           | "Face terminal unavailable"; gate not opened                            |
| **Timeout**                          | Start, present nothing past `FACE_AUTH_POLL_MAX_SECONDS`          | "Session expired"; upstream session cancelled; gate not opened          |
| **Duplicate polling**                | Two browser tabs poll the same session                            | read-only GET is safe; neither opens the gate (only complete does)      |
| **QR-required**                      | Start a flow needing QR (no qr_token)                             | "QR verification required before face scan"; gate not opened            |
| **Fallback (testing only)**          | Set `FACE_AUTH_REQUIRE_MATCHED_IDENTITY=false`, missing identity   | gate opens; `identity_fallback_used=true`; warning logged               |
| **Gate mode = open_door**            | Set `FACE_AUTH_GATE_MODE=face_auth_open_door`, verify             | face_auth opens the relay; app does **not** call gateway output()       |

Quick checks for the two blocked cases:

```bash
# Missing identity → 409 identity_missing
curl -s -o /dev/null -w "%{http_code}\n" -X POST $API/api/kiosk/face-checkin/<sid>/complete
# expect 409; body: {"ok":false,"error":"identity_missing","message":"Face verification completed but no matched identity was returned."}

# Wrong identity → 409 identity_mismatch
# body: {"ok":false,"error":"identity_mismatch","message":"Face verification identity does not match the selected member."}
```

### Verify the gate opens via exactly one path

```bash
# app_turnstile (default): expect a gateway /device/output call in the logs,
# and NO face_auth /open-door call.
docker compose logs api | grep -i "kiosk face-checkin granted"

# face_auth_open_door: expect a face_auth /devices/<id>/open-door call and
# NO gateway output(). Flip FACE_AUTH_GATE_MODE, restart api, re-test.
```

## E. Audit log

```bash
docker compose exec api php artisan tinker --execute \
  "App\Models\FaceAuthAttempt::latest()->take(5)->get(['id','kiosk_id','member_id','employee_no','person_id','session_id','status','gate_opened','identity_fallback_used','gate_opened_at','error_message'])->each(fn(\$a)=>print_r(\$a->toArray()));"
```

Every check-in must leave one row carrying: kiosk_id, member_id,
employee_no/person_id sent to face_auth, face_auth session_id, final status,
whether the turnstile opened (`gate_opened`), and any error reason.
