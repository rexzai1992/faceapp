<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FaceAuthAttempt;
use App\Services\FaceAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Browser-facing proxy in front of the external face_auth service.
 *
 * The frontend NEVER calls face_auth directly — it calls these endpoints, and
 * FaceAuthClient (which holds FACE_AUTH_API_KEY) makes the upstream call. This
 * keeps the API key server-side and gives us one place to authorise + audit.
 *
 * NOTE ON AUTHZ: this app currently has no end-user authentication, so the
 * "is this user allowed to verify this personId?" check in assertAllowedToVerify()
 * can only be a stub today. The hook is wired in the right place — fill it in
 * the moment you add user auth. Every attempt is logged regardless.
 */
class FaceAuthController extends Controller
{
    public function __construct(private readonly FaceAuthClient $faceAuth) {}

    /** GET /api/face-auth/devices */
    public function devices(): JsonResponse
    {
        return $this->proxy(fn () => $this->faceAuth->listFaceAuthDevices());
    }

    /** GET /api/face-auth/persons */
    public function persons(): JsonResponse
    {
        return $this->proxy(fn () => $this->faceAuth->listFaceAuthPersons());
    }

    /** POST /api/face-auth/start */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id'   => ['nullable', 'string'],
            'person_id'   => ['nullable', 'string'],
            'employee_no' => ['nullable', 'string'],
            'qr_token'    => ['nullable', 'string'],
        ]);

        // --- authorisation hook (see class note) ---------------------------
        if (! $this->assertAllowedToVerify($request, $validated)) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'You are not allowed to verify this person.',
            ], 403);
        }

        $attempt = FaceAuthAttempt::create([
            'requested_by'        => (string) ($request->user()?->getAuthIdentifier() ?? ''),
            'caller_ip'           => $request->ip(),
            'face_auth_device_id' => $validated['device_id'] ?? config('faceauth.default_device_id'),
            'person_id'           => $validated['person_id'] ?? null,
            'employee_no'         => $validated['employee_no'] ?? null,
            'status'              => 'started',
        ]);

        try {
            $session = $this->faceAuth->startFaceAuth([
                'deviceId'   => $validated['device_id'] ?? null,
                'personId'   => $validated['person_id'] ?? null,
                'employeeNo' => $validated['employee_no'] ?? null,
                'qrToken'    => $validated['qr_token'] ?? null,
            ]);

            $sessionId = $this->extractSessionId($session);

            $attempt->update([
                'session_id' => $sessionId,
                'status'     => (string) ($session['status'] ?? 'pending'),
            ]);

            return response()->json([
                'ok'         => true,
                'session_id' => $sessionId,
                'status'     => $session['status'] ?? 'pending',
                'poll'       => config('faceauth.poll'),
                'session'    => $session,
            ]);
        } catch (Throwable $e) {
            return $this->fail($attempt, $e);
        }
    }

    /** GET /api/face-auth/session/{session} */
    public function show(string $session): JsonResponse
    {
        $attempt = FaceAuthAttempt::where('session_id', $session)->latest('id')->first();

        try {
            $payload = $this->faceAuth->getFaceAuthSession($session);
            $status  = (string) ($payload['status'] ?? 'pending');

            $attempt?->update([
                'status'       => $status,
                'completed_at' => $this->isTerminal($status) ? now() : null,
            ]);

            return response()->json([
                'ok'       => true,
                'status'   => $status,
                'session'  => $payload,
            ]);
        } catch (Throwable $e) {
            return $this->fail($attempt, $e);
        }
    }

    /** POST /api/face-auth/session/{session}/cancel */
    public function cancel(string $session): JsonResponse
    {
        $attempt = FaceAuthAttempt::where('session_id', $session)->latest('id')->first();

        try {
            $payload = $this->faceAuth->cancelFaceAuthSession($session);

            $attempt?->update(['status' => 'cancelled', 'completed_at' => now()]);

            return response()->json([
                'ok'      => true,
                'status'  => 'cancelled',
                'session' => $payload,
            ]);
        } catch (Throwable $e) {
            return $this->fail($attempt, $e);
        }
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Per-user authorisation gate. Returns true (allow) today because there is
     * no user auth yet. Once you add auth, enforce here, e.g.:
     *
     *   $user = $request->user();
     *   return $user?->canVerify($validated['person_id'] ?? $validated['employee_no']);
     */
    protected function assertAllowedToVerify(Request $request, array $validated): bool
    {
        return true;
    }

    protected function proxy(callable $call): JsonResponse
    {
        try {
            return response()->json(['ok' => true, 'data' => $call()]);
        } catch (Throwable $e) {
            Log::warning('face_auth proxy call failed', ['message' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'error' => 'face_auth_unavailable',
                'message' => $e->getMessage(),
            ], $this->statusFor($e));
        }
    }

    protected function fail(?FaceAuthAttempt $attempt, Throwable $e): JsonResponse
    {
        $attempt?->update(['status' => 'error', 'error_message' => $e->getMessage(), 'completed_at' => now()]);

        Log::warning('face_auth call failed', [
            'attempt_id' => $attempt?->id,
            'session_id' => $attempt?->session_id,
            'message'    => $e->getMessage(),
        ]);

        return response()->json([
            'ok' => false,
            'error' => 'face_auth_failed',
            'message' => $e->getMessage(),
        ], $this->statusFor($e));
    }

    /** Map the upstream status carried in the exception code to a sane HTTP code. */
    protected function statusFor(Throwable $e): int
    {
        $code = $e->getCode();

        return match (true) {
            $code === 401 || $code === 403 => 502, // our key is bad — that's our problem, not the caller's
            $code === 404 => 404,
            $code >= 400 && $code < 600 => 502,
            default => 502,
        };
    }

    protected function extractSessionId(array $session): ?string
    {
        foreach (['sessionId', 'session_id', 'id'] as $key) {
            if (filled($session[$key] ?? null)) {
                return (string) $session[$key];
            }
        }

        return null;
    }

    protected function isTerminal(string $status): bool
    {
        // 'face_matched' / 'timed_out' are the live face_auth terminal statuses;
        // the rest cover forward-compat / aliased builds.
        return in_array(strtolower($status), [
            'face_matched', 'timed_out', 'success', 'failed', 'cancelled', 'timeout', 'error',
        ], true);
    }
}
