<?php

return [
    /*
    |--------------------------------------------------------------------------
    | face_auth external service (github.com/mhdFitriM/face_auth)
    |--------------------------------------------------------------------------
    |
    | face_auth is run as a separate microservice (Go + Fiber, backed by its
    | own Postgres/Redis/MinIO). FaceApp talks to it server-to-server only —
    | the API key MUST NOT reach the browser. All values resolve via env() here
    | so they survive `php artisan config:cache`.
    |
    */

    // Base URL of the face_auth backend, WITHOUT a trailing slash and WITHOUT
    // the /api/v1 suffix (the client appends it). On the shared Docker network
    // this is the compose service name, e.g. http://face_auth:8080
    'base_url' => rtrim((string) env('FACE_AUTH_BASE_URL', ''), '/'),

    // Public API key issued by face_auth. Sent as `Authorization: Bearer <key>`.
    'api_key' => env('FACE_AUTH_API_KEY'),

    // Device the "Verify with Face" flow targets when the caller does not name
    // one. Matches a face_auth device id (GET /api/v1/devices).
    'default_device_id' => env('FACE_AUTH_DEFAULT_DEVICE_ID'),

    'timeout_seconds' => (int) env('FACE_AUTH_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Gate mode — who physically opens the turnstile after a face verifies
    |--------------------------------------------------------------------------
    |
    |  app_turnstile      face_auth ONLY verifies the face. The existing app
    |                     opens the turnstile via the Java gateway (output()).
    |                     This is the default and the safe choice.
    |
    |  face_auth_open_door  The Hikvision terminal relay is wired straight to
    |                       the turnstile, so face_auth opens it directly and
    |                       the app MUST NOT call the gateway output(). Only use
    |                       this if the hardware is physically wired that way.
    |
    | These are mutually exclusive — exactly one path opens the gate, never both.
    */
    'gate_mode' => env('FACE_AUTH_GATE_MODE', 'app_turnstile'),

    /*
    |--------------------------------------------------------------------------
    | Require a matched identity before opening the gate (fail-closed)
    |--------------------------------------------------------------------------
    |
    | true  (default, PRODUCTION): the gate opens only when face_auth returns a
    |        strong identity field that matches the member we started for. If
    |        face_auth returns no identity, the check-in is blocked.
    |
    | false (LOCAL INTEGRATION TESTING ONLY): when face_auth returns no identity
    |        we fall back to the session↔member binding from start(). This is
    |        never silent — it logs a warning and stamps identity_fallback_used.
    |        A mismatched identity is ALWAYS blocked regardless of this flag.
    */
    'require_matched_identity' => filter_var(
        env('FACE_AUTH_REQUIRE_MATCHED_IDENTITY', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    // How long the frontend should keep polling a session before giving up.
    // Exposed to the controller so the timeout is configured in one place.
    'poll' => [
        'max_seconds' => (int) env('FACE_AUTH_POLL_MAX_SECONDS', 60),
        'interval_ms' => (int) env('FACE_AUTH_POLL_INTERVAL_MS', 1500),
    ],
];
