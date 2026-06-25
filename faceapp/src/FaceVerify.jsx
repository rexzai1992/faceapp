import { useCallback, useEffect, useRef, useState } from 'react'
import { startFaceAuth, getFaceAuthSession, cancelFaceAuthSession } from './api'
import './FaceVerify.css'

// Terminal statuses returned by face_auth — once we see one we stop polling.
const TERMINAL = new Set(['success', 'failed', 'cancelled', 'timeout', 'error'])

const DEFAULTS = { pollIntervalMs: 1500, pollMaxSeconds: 60 }

/**
 * "Verify with Face" button + status panel.
 *
 * Flow: start session → poll backend until terminal/timeout. All calls go to
 * OUR backend (/api/face-auth/*), never to face_auth directly, so the API key
 * stays server-side. Pass the person you want to verify via props.
 */
export default function FaceVerify({ deviceId, personId, employeeNo, onResult }) {
  const [phase, setPhase] = useState('idle') // idle|starting|pending|qr_required|success|failed|cancelled|timeout|error
  const [message, setMessage] = useState('')
  const [sessionId, setSessionId] = useState(null)

  const pollTimer = useRef(null)
  const deadline = useRef(0)
  const cancelled = useRef(false)

  const clearPoll = useCallback(() => {
    if (pollTimer.current) {
      clearTimeout(pollTimer.current)
      pollTimer.current = null
    }
  }, [])

  // Stop polling if the component unmounts mid-flight.
  useEffect(() => () => { cancelled.current = true; clearPoll() }, [clearPoll])

  const settle = useCallback((status, msg) => {
    clearPoll()
    setPhase(status)
    setMessage(msg)
    onResult?.({ status, sessionId, message: msg })
  }, [clearPoll, onResult, sessionId])

  const poll = useCallback(async (sid, intervalMs) => {
    if (cancelled.current) return

    if (Date.now() > deadline.current) {
      // Best-effort: tell the backend to cancel the abandoned session.
      cancelFaceAuthSession(sid).catch(() => {})
      settle('timeout', 'Verification timed out. Please try again.')
      return
    }

    try {
      const { status, session } = await getFaceAuthSession(sid)
      const normalized = String(status || 'pending').toLowerCase()

      if (normalized === 'qr_required') {
        setPhase('qr_required')
        setMessage('Scan the QR code on the device to continue.')
      } else if (TERMINAL.has(normalized)) {
        const label = {
          success: 'Verified.',
          failed: session?.reason || 'Face not recognised.',
          cancelled: 'Verification cancelled.',
          timeout: 'Verification timed out.',
          error: session?.reason || 'Verification error.',
        }[normalized] || 'Done.'
        settle(normalized, label)
        return
      } else {
        setPhase('pending')
        setMessage('Look at the camera…')
      }
    } catch (err) {
      settle('error', err.message || 'Lost contact with the verification service.')
      return
    }

    pollTimer.current = setTimeout(() => poll(sid, intervalMs), intervalMs)
  }, [settle])

  const begin = useCallback(async () => {
    cancelled.current = false
    setPhase('starting')
    setMessage('Starting verification…')
    setSessionId(null)

    try {
      const res = await startFaceAuth({
        device_id: deviceId,
        person_id: personId,
        employee_no: employeeNo,
      })

      const sid = res.session_id
      if (!sid) throw new Error('No session id returned.')

      const intervalMs = res.poll?.interval_ms ?? DEFAULTS.pollIntervalMs
      const maxSeconds = res.poll?.max_seconds ?? DEFAULTS.pollMaxSeconds
      deadline.current = Date.now() + maxSeconds * 1000

      setSessionId(sid)
      setPhase('pending')
      setMessage('Look at the camera…')
      pollTimer.current = setTimeout(() => poll(sid, intervalMs), intervalMs)
    } catch (err) {
      // A 403 from our backend (authz) or QR-required-at-start surfaces here.
      settle('error', err.message || 'Could not start verification.')
    }
  }, [deviceId, personId, employeeNo, poll, settle])

  const abort = useCallback(async () => {
    cancelled.current = true
    clearPoll()
    if (sessionId) {
      try { await cancelFaceAuthSession(sessionId) } catch { /* already gone */ }
    }
    settle('cancelled', 'Verification cancelled.')
  }, [clearPoll, sessionId, settle])

  const busy = phase === 'starting' || phase === 'pending' || phase === 'qr_required'

  return (
    <div className={`face-verify face-verify--${phase}`}>
      {!busy && (
        <button type="button" className="face-verify__btn" onClick={begin}>
          {phase === 'idle' ? 'Verify with Face' : 'Try again'}
        </button>
      )}

      {busy && (
        <div className="face-verify__status" role="status" aria-live="polite">
          <span className="face-verify__spinner" aria-hidden="true" />
          <span className="face-verify__msg">{message}</span>
          <button type="button" className="face-verify__cancel" onClick={abort}>Cancel</button>
        </div>
      )}

      {!busy && phase !== 'idle' && (
        <p className={`face-verify__result face-verify__result--${phase}`} role="status" aria-live="polite">
          {message}
        </p>
      )}
    </div>
  )
}
