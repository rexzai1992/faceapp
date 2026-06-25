<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FaceAuthAttempt;
use App\Services\FaceAuthClient;
use App\Services\GatewaySdkClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kiosk check-in flow: face_auth verifies the face, then the gate opens via
 * whichever mechanism FACE_AUTH_GATE_MODE selects — never both.
 *
 *   start()    → identify member, open a face_auth session, audit it.
 *   (kiosk polls GET /api/face-auth/session/{id} — read-only proxy)
 *   complete() → re-fetch the AUTHORITATIVE status from face_auth, confirm it
 *                belongs to the expected member, then open the gate ONCE.
 *
 * The gate decision is made server-side from face_auth's own status, never
 * from a "success" the browser claims. Gate opens are idempotent per session.
 */
class KioskCheckinController extends Controller
{
    // Real face_auth (QRSession) statuses: open | face_matched | timed_out |
    // cancelled. 'success'/'verified'/'passed' are kept for forward-compat, but
    // 'face_matched' is what the live service actually emits on a face match.
    private const VERIFIED = ['face_matched', 'success', 'verified', 'passed'];
    private const FAILED   = ['failed', 'error', 'denied', 'rejected'];

    public function __construct(
        private readonly FaceAuthClient $faceAuth,
        private readonly GatewaySdkClient $gateway,
    ) {}

    /**
     * POST /api/kiosk/face-checkin/start
     *
     * Body: kiosk_id (required), member_id (required), and at least one of
     * person_id / employee_no. Optional: device_id (face_auth terminal),
     * app_gate_device_id (managed turnstile Device for app_turnstile mode).
     */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kiosk_id'           => ['required', 'string', 'max:120'],
            'member_id'          => ['required', 'string', 'max:120'],
            'person_id'          => ['nullable', 'string', 'max:120'],
            'employee_no'        => ['nullable', 'string', 'max:120'],
            'device_id'          => ['nullable', 'string', 'max:120'],
            'app_gate_device_id' => ['nullable', 'integer'],
        ]);

        if (blank($data['person_id'] ?? null) && blank($data['employee_no'] ?? null)) {
            return $this->error('member_unmapped', 'This member is not mapped to a face_auth identity.', 422);
        }

        try {
            $session = $this->faceAuth->startFaceAuth([
                'deviceId'   => $data['device_id'] ?? null,
                'personId'   => $data['person_id'] ?? null,
                'employeeNo' => $data['employee_no'] ?? null,
            ]);
        } catch (Throwable $e) {
            // face_auth returns 409 {"error":"qr_required"} from /auth/face/start
            // when the device requires a QR scan first. Surface it as-is.
            if ($e->getCode() === 409) {
                return $this->error('qr_required', 'QR verification required before face scan.', 409);
            }

            // 5xx (or a transport failure → code 0) typically means the terminal
            // is offline / unreachable.
            $offline = $e->getCode() >= 500 || $e->getCode() === 0;

            return $this->error(
                $offline ? 'device_offline' : 'start_failed',
                $e->getMessage(),
                $offline ? 503 : 502,
            );
        }

        $sessionId = $this->extractSessionId($session);

        if (! $sessionId) {
            return $this->error('no_session', 'face_auth did not return a session id.', 502);
        }

        $attempt = FaceAuthAttempt::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'kiosk_id'            => $data['kiosk_id'],
                'member_id'           => $data['member_id'],
                'caller_ip'           => $request->ip(),
                'face_auth_device_id' => $data['device_id'] ?? config('faceauth.default_device_id'),
                'person_id'           => $data['person_id'] ?? null,
                'employee_no'         => $data['employee_no'] ?? null,
                'gate_mode'           => config('faceauth.gate_mode'),
                'app_gate_device_id'  => $data['app_gate_device_id'] ?? null,
                'status'              => (string) ($session['status'] ?? 'pending'),
            ],
        );

        Log::info('kiosk face-checkin started', $this->audit($attempt));

        return response()->json([
            'ok'         => true,
            'session_id' => $sessionId,
            'poll_url'   => "/api/face-auth/session/{$sessionId}",
            'poll'       => config('faceauth.poll'),
            'status'     => $session['status'] ?? 'pending',
        ]);
    }

    /**
     * POST /api/kiosk/face-checkin/{session}/complete
     *
     * Server re-checks the face_auth status, confirms the matched identity is
     * the member we started for, and opens the gate once. Safe to call more
     * than once — a second call returns the same result without re-opening.
     */
    public function complete(Request $request, string $session): JsonResponse
    {
        // Authoritative status straight from face_auth (not from the browser).
        try {
            $payload = $this->faceAuth->getFaceAuthSession($session);
        } catch (Throwable $e) {
            $offline = $e->getCode() >= 500 || $e->getCode() === 0;

            return $this->error(
                $offline ? 'device_offline' : 'status_failed',
                $e->getMessage(),
                $offline ? 503 : 502,
            );
        }

        $status = strtolower((string) ($payload['status'] ?? 'pending'));

        // Everything that mutates the attempt + opens the gate is serialised so
        // two concurrent completes can't both open the turnstile.
        return DB::transaction(function () use ($session, $payload, $status, $request) {
            $attempt = FaceAuthAttempt::where('session_id', $session)->lockForUpdate()->first();

            if (! $attempt) {
                return $this->error('unknown_session', 'No check-in was started for this session.', 404);
            }

            // Idempotent: already opened → report success without re-opening.
            if ($attempt->gate_opened) {
                return response()->json([
                    'ok'           => true,
                    'status'       => 'granted',
                    'gate_opened'  => true,
                    'already_open' => true,
                ]);
            }

            if (in_array($status, self::VERIFIED, true)) {
                // Fail-closed: the gate opens only when face_auth returns a strong
                // identity that matches the member we started for.
                $expected = array_values(array_filter([
                    (string) $attempt->person_id,
                    (string) $attempt->employee_no,
                ], fn ($v) => $v !== ''));
                $actual = $this->resolveMatchedIdentity($payload);
                $requireIdentity = (bool) config('faceauth.require_matched_identity', true);

                if (empty($actual)) {
                    // No matched identity returned by face_auth.
                    if ($requireIdentity) {
                        $attempt->update([
                            'status'        => 'identity_missing',
                            'gate_opened'   => false,
                            'error_message' => 'face_auth session did not include a matched identity',
                            'completed_at'  => now(),
                        ]);
                        Log::warning('kiosk face-checkin identity missing', $this->audit($attempt, $payload));

                        return $this->error(
                            'identity_missing',
                            'Face verification completed but no matched identity was returned.',
                            409,
                        );
                    }

                    // Fallback (FACE_AUTH_REQUIRE_MATCHED_IDENTITY=false): trust the
                    // session↔member binding, but never silently — warn + stamp it.
                    Log::warning('kiosk face-checkin opening gate via identity fallback (require_matched_identity=false)', $this->audit($attempt, $payload));

                    return $this->openGate($attempt, $payload, identityFallbackUsed: true);
                }

                // Identity present but does not match → always blocked.
                if (! array_intersect($expected, $actual)) {
                    $attempt->update([
                        'status'        => 'identity_mismatch',
                        'gate_opened'   => false,
                        'error_message' => sprintf(
                            'identity mismatch: expected [%s], face_auth matched [%s]',
                            implode(', ', $expected) ?: '(none)',
                            implode(', ', $actual),
                        ),
                        'completed_at'  => now(),
                    ]);
                    Log::warning('kiosk face-checkin identity mismatch', $this->audit($attempt, $payload));

                    return $this->error(
                        'identity_mismatch',
                        'Face verification identity does not match the selected member.',
                        409,
                    );
                }

                // Verified + identity present + matches + not yet opened.
                return $this->openGate($attempt, $payload);
            }

            if (in_array($status, self::FAILED, true)) {
                $attempt->update(['status' => $status, 'completed_at' => now(), 'error_message' => $payload['reason'] ?? null]);
                Log::info('kiosk face-checkin failed', $this->audit($attempt, $payload));

                return response()->json(['ok' => true, 'status' => 'failed', 'gate_opened' => false]);
            }

            if ($status === 'qr_required') {
                $attempt->update(['status' => 'qr_required']);

                return response()->json(['ok' => true, 'status' => 'qr_required', 'gate_opened' => false]);
            }

            if (in_array($status, ['cancelled', 'timed_out', 'timeout', 'expired'], true)) {
                $attempt->update(['status' => $status, 'completed_at' => now()]);

                return response()->json(['ok' => true, 'status' => $status, 'gate_opened' => false]);
            }

            // Still pending — caller completed too early; tell it to keep polling.
            return response()->json(['ok' => true, 'status' => 'pending', 'gate_opened' => false]);
        });
    }

    // --- gate opening ------------------------------------------------------

    private function openGate(FaceAuthAttempt $attempt, array $payload, bool $identityFallbackUsed = false): JsonResponse
    {
        $mode = $attempt->gate_mode ?: config('faceauth.gate_mode');

        try {
            if ($mode === 'face_auth_open_door') {
                // The terminal relay drives the turnstile; the app stays out of it.
                $deviceId = $attempt->face_auth_device_id ?: config('faceauth.default_device_id');
                $this->faceAuth->openDoor((string) $deviceId);
            } else {
                // app_turnstile (default): the existing gateway mechanism opens it.
                if ($attempt->app_gate_device_id) {
                    $device = Device::query()->where('is_managed', true)->find($attempt->app_gate_device_id);
                    if (! $device) {
                        throw new \RuntimeException("Unknown managed turnstile device id={$attempt->app_gate_device_id}.");
                    }
                    $this->gateway->forDevice($device)->output(type: 1);
                } else {
                    $this->gateway->output(type: 1);
                }
            }
        } catch (Throwable $e) {
            // Gate stays "not opened" so a retry is possible (txn rolls back our
            // flag write because we never set it before this succeeded).
            $attempt->update(['status' => 'gate_failed', 'error_message' => $e->getMessage()]);
            Log::error('kiosk face-checkin gate open failed', $this->audit($attempt, $payload));

            return $this->error('gate_failed', 'Face verified but the turnstile could not be opened.', 502);
        }

        $attempt->update([
            'status'                 => 'success',
            'gate_opened'            => true,
            'identity_fallback_used' => $identityFallbackUsed,
            'gate_opened_at'         => now(),
            'completed_at'           => now(),
        ]);

        Log::info('kiosk face-checkin granted', $this->audit($attempt, $payload));

        return response()->json([
            'ok'          => true,
            'status'      => 'granted',
            'gate_opened' => true,
            'gate_mode'   => $mode,
        ]);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Strong identity fields face_auth may use to name the matched person.
     * Only these count as a verifiable identity — presence of one is required
     * to open the gate when require_matched_identity is true.
     */
    private const STRONG_IDENTITY_FIELDS = [
        'personId', 'person_id',
        'employeeNo', 'employee_no',
        'fpid', 'FPID',
        'userId', 'user_id',
        'matchedPersonId', 'matched_person_id',
        'matchedEmployeeNo', 'matched_employee_no',
    ];

    /**
     * Collect every strong identity value face_auth returned for the matched
     * person. face_auth's response shape varies, so each field is probed at the
     * top level and under a few common nesting paths. Returns [] when none are
     * present — which, under fail-closed, blocks the gate.
     *
     * @return list<string>
     */
    private function resolveMatchedIdentity(array $payload): array
    {
        $found = [];

        foreach (self::STRONG_IDENTITY_FIELDS as $key) {
            foreach ([$key, "person.$key", "result.$key", "match.$key", "data.$key"] as $path) {
                $value = data_get($payload, $path);
                if (filled($value)) {
                    $found[] = (string) $value;
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function extractSessionId(array $session): ?string
    {
        foreach (['sessionId', 'session_id', 'id'] as $key) {
            if (filled($session[$key] ?? null)) {
                return (string) $session[$key];
            }
        }

        return null;
    }

    private function audit(FaceAuthAttempt $attempt, array $payload = []): array
    {
        return [
            'kiosk_id'               => $attempt->kiosk_id,
            'member_id'              => $attempt->member_id,
            'person_id'              => $attempt->person_id,
            'employee_no'            => $attempt->employee_no,
            'session_id'             => $attempt->session_id,
            'final_status'           => $attempt->status,
            'gate_opened'            => $attempt->gate_opened,
            'identity_fallback_used' => $attempt->identity_fallback_used,
            'gate_mode'              => $attempt->gate_mode,
            'matched_identity'       => $this->resolveMatchedIdentity($payload),
            'error'                  => $attempt->error_message,
            'upstream'               => $payload['status'] ?? null,
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
