<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server-to-server client for the external face_auth service
 * (github.com/mhdFitriM/face_auth), public API under /api/v1.
 *
 * This is the ONLY place the FACE_AUTH_API_KEY is used. The browser never
 * sees it — FaceAuthController proxies the calls the frontend needs.
 *
 * Every method returns the decoded JSON body as an array, or throws a
 * RuntimeException (carrying the upstream message + status) on failure.
 * Mirrors the error-handling shape of GatewaySdkClient so callers can
 * `try/catch (Throwable)` uniformly.
 */
class FaceAuthClient
{
    /**
     * GET /api/v1/ping — liveness + credential probe.
     */
    public function ping(): array
    {
        return $this->get('/ping');
    }

    /**
     * GET /api/v1/devices — face_auth devices available for verification.
     */
    public function listFaceAuthDevices(): array
    {
        return $this->get('/devices');
    }

    /**
     * GET /api/v1/persons — enrolled persons known to face_auth.
     */
    public function listFaceAuthPersons(): array
    {
        return $this->get('/persons');
    }

    /**
     * POST /api/v1/auth/face/start — begin a face-verification session.
     *
     * @param  array{deviceId?:string|int|null, personId?:string|int|null, employeeNo?:string|null, qrToken?:string|null}  $params
     * @return array The session payload (expects at least a session id).
     */
    public function startFaceAuth(array $params): array
    {
        $deviceId = $params['deviceId'] ?? config('faceauth.default_device_id');

        if (blank($deviceId)) {
            throw new RuntimeException('No face_auth device id supplied and FACE_AUTH_DEFAULT_DEVICE_ID is not set.');
        }

        $payload = array_filter([
            'deviceId'   => $deviceId,
            'personId'   => $params['personId'] ?? null,
            'employeeNo' => $params['employeeNo'] ?? null,
            'qrToken'    => $params['qrToken'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->post('/auth/face/start', $payload);
    }

    /**
     * GET /api/v1/auth/face/{sessionId} — current state of a session.
     * Typical statuses: pending, success, failed, cancelled, qr_required, timeout.
     */
    public function getFaceAuthSession(string $sessionId): array
    {
        return $this->get('/auth/face/'.rawurlencode($sessionId));
    }

    /**
     * POST /api/v1/auth/face/{sessionId}/cancel — abort a pending session.
     */
    public function cancelFaceAuthSession(string $sessionId): array
    {
        return $this->post('/auth/face/'.rawurlencode($sessionId).'/cancel');
    }

    /**
     * POST /api/v1/devices/{deviceId}/open-door — make face_auth open the
     * device relay directly. Only used in FACE_AUTH_GATE_MODE=face_auth_open_door.
     */
    public function openDoor(string $deviceId): array
    {
        return $this->post('/devices/'.rawurlencode($deviceId).'/open-door');
    }

    // --- transport ---------------------------------------------------------

    protected function get(string $path): array
    {
        return $this->send('get', $path);
    }

    protected function post(string $path, array $payload = []): array
    {
        return $this->send('post', $path, $payload);
    }

    protected function send(string $method, string $path, array $payload = []): array
    {
        try {
            $request = $this->http();

            $response = $method === 'post'
                ? $request->post($this->endpoint($path), $payload)->throw()
                : $request->get($this->endpoint($path))->throw();
        } catch (RequestException $exception) {
            $status  = $exception->response?->status();
            $message = $exception->response?->json('message')
                ?? $exception->response?->json('error')
                ?? $exception->response?->body()
                ?? $exception->getMessage();

            // Re-throw with a code so the controller can map upstream
            // statuses (e.g. 401 bad key, 404 unknown session) to its own.
            throw new RuntimeException(
                'face_auth request failed'.($status ? " [{$status}]" : '').': '.$message,
                (int) ($status ?? 0),
                $exception,
            );
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : ['raw' => $response->body()];
    }

    protected function http(): PendingRequest
    {
        $apiKey = config('faceauth.api_key');

        if (blank(config('faceauth.base_url'))) {
            throw new RuntimeException('FACE_AUTH_BASE_URL is not configured.');
        }

        if (blank($apiKey)) {
            throw new RuntimeException('FACE_AUTH_API_KEY is not configured.');
        }

        return Http::asJson()
            ->acceptJson()
            ->timeout((int) config('faceauth.timeout_seconds', 10))
            // face_auth accepts either Bearer or X-API-Key; we send both so a
            // misconfigured proxy that strips one still authenticates.
            ->withToken($apiKey)
            ->withHeaders(['X-API-Key' => $apiKey]);
    }

    protected function endpoint(string $path): string
    {
        return rtrim((string) config('faceauth.base_url'), '/').'/api/v1/'.ltrim($path, '/');
    }
}
