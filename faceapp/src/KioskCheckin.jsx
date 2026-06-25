import { useCallback, useEffect, useRef, useState } from 'react'
import {
  startKioskFaceCheckin,
  getFaceAuthSession,
  completeKioskFaceCheckin,
  cancelFaceAuthSession,
} from './api'
import './KioskCheckin.css'

/*
 * Kiosk face check-in state machine
 * ---------------------------------
 *   preparing ─start ok─► waiting ─poll:verified─► verifying ─complete─► granted
 *       │                   │ │                                  │
 *       │                   │ ├─poll:qr_required─► qr_required    ├─pending─► waiting (resume)
 *       │                   │ └─poll:failed─────► failed          └─device/gate─► unavailable/failed
 *       │                   └─timeout──────────► expired (cancels upstream)
 *       └─device_offline──► unavailable    └─start error─► failed
 *
 * Terminal: granted | failed | unavailable | expired | qr_required.
 * Only the server opens the gate, only inside completeKioskFaceCheckin(); the
 * browser never decides access. Polling stops on unmount / timeout / terminal.
 */

const MESSAGES = {
  preparing:   'Preparing face verification…',
  waiting:     'Please look at the face terminal',
  verifying:   'Face verified',
  granted:     'Access granted',
  failed:      'Face verification failed',
  unavailable: 'Face terminal unavailable',
  expired:     'Session expired, please try again',
  qr_required: 'QR verification required before face scan',
}

// Live face_auth statuses: open | face_matched | timed_out | cancelled.
// The other strings are kept for forward-compat with aliased builds.
const VERIFIED = new Set(['face_matched', 'success', 'verified', 'passed'])
const FAILED   = new Set(['failed', 'error', 'denied', 'rejected', 'cancelled'])
const EXPIRED  = new Set(['timed_out', 'timeout', 'expired'])

const DEFAULTS = { interval_ms: 1500, max_seconds: 60 }

export default function KioskCheckin({ kioskId, member, onClose, onResult }) {
  const [phase, setPhase] = useState('preparing')

  const sessionIdRef = useRef(null)
  const intervalRef = useRef(DEFAULTS.interval_ms)
  const deadline = useRef(0)
  const pollTimer = useRef(null)
  const stopped = useRef(false)     // unmount/terminal — guards stray async work
  const started = useRef(false)     // double-submit guard
  const completing = useRef(false)  // double-complete (double gate-open) guard

  const clearPoll = useCallback(() => {
    if (pollTimer.current) {
      clearTimeout(pollTimer.current)
      pollTimer.current = null
    }
  }, [])

  const finish = useCallback((next) => {
    stopped.current = true
    clearPoll()
    setPhase(next)
    onResult?.({ status: next, sessionId: sessionIdRef.current })
  }, [clearPoll, onResult])

  // One poll cycle; re-arms itself until a terminal status or the deadline.
  const tick = useCallback(async () => {
    if (stopped.current) return

    if (Date.now() > deadline.current) {
      if (sessionIdRef.current) cancelFaceAuthSession(sessionIdRef.current).catch(() => {})
      finish('expired')
      return
    }

    try {
      const { status } = await getFaceAuthSession(sessionIdRef.current)
      if (stopped.current) return
      const s = String(status || 'pending').toLowerCase()

      if (VERIFIED.has(s)) { clearPoll(); setPhase('verifying'); return } // effect runs complete()
      if (FAILED.has(s))   { finish('failed'); return }
      if (EXPIRED.has(s))  { finish('expired'); return }
      if (s === 'qr_required') { finish('qr_required'); return }

      setPhase('waiting')
    } catch {
      // transient read error — keep trying until the deadline
    }

    pollTimer.current = setTimeout(tick, intervalRef.current)
  }, [clearPoll, finish])

  // Entering 'verifying' triggers the authoritative complete() exactly once.
  useEffect(() => {
    if (phase !== 'verifying' || completing.current) return
    completing.current = true
    let abandoned = false

    ;(async () => {
      try {
        const res = await completeKioskFaceCheckin(sessionIdRef.current)
        if (stopped.current || abandoned) return

        if (res.status === 'granted') finish('granted')
        else if (res.status === 'qr_required') finish('qr_required')
        else if (res.status === 'pending') {
          // Rare eventual-consistency race: poll saw verified, server didn't yet.
          completing.current = false
          setPhase('waiting')
          pollTimer.current = setTimeout(tick, intervalRef.current)
        } else finish('failed')
      } catch (err) {
        if (stopped.current || abandoned) return
        finish(err?.code === 'device_offline' ? 'unavailable' : 'failed')
      }
    })()

    return () => { abandoned = true }
  }, [phase, finish, tick])

  const begin = useCallback(async () => {
    if (started.current) return
    started.current = true
    setPhase('preparing')

    try {
      const res = await startKioskFaceCheckin({
        kiosk_id: kioskId,
        member_id: String(member?.id ?? ''),
        person_id: member?.personId ? String(member.personId) : undefined,
        employee_no: member?.employeeId ? String(member.employeeId) : undefined,
      })
      if (stopped.current) return

      sessionIdRef.current = res.session_id
      intervalRef.current = res.poll?.interval_ms ?? DEFAULTS.interval_ms
      const maxSeconds = res.poll?.max_seconds ?? DEFAULTS.max_seconds
      deadline.current = Date.now() + maxSeconds * 1000

      setPhase('waiting')
      pollTimer.current = setTimeout(tick, intervalRef.current)
    } catch (err) {
      if (stopped.current) return
      // start can fail with qr_required (409) or device_offline (503).
      const map = { device_offline: 'unavailable', qr_required: 'qr_required' }
      finish(map[err?.code] || 'failed')
    }
  }, [kioskId, member, tick, finish])

  // Auto-start once on mount; tear down only on real unmount. Intentionally
  // empty deps — begin() guards itself with started.current, and depending on
  // begin/clearPoll would re-run this whenever the inline `member` prop changes
  // identity, whose cleanup would wrongly set stopped.current mid-poll.
  useEffect(() => {
    begin()
    return () => { stopped.current = true; clearPoll() }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const cancel = useCallback(async () => {
    const sid = sessionIdRef.current
    stopped.current = true
    clearPoll()
    if (sid) { try { await cancelFaceAuthSession(sid) } catch { /* already gone */ } }
    onClose?.()
  }, [clearPoll, onClose])

  const retry = useCallback(() => {
    stopped.current = false
    started.current = false
    completing.current = false
    sessionIdRef.current = null
    begin()
  }, [begin])

  const busy = phase === 'preparing' || phase === 'waiting' || phase === 'verifying'
  const isError = phase === 'failed' || phase === 'unavailable' || phase === 'expired' || phase === 'qr_required'

  return (
    <div className="kiosk-checkin">
      <div className={`kiosk-checkin__card kiosk-checkin__card--${phase}`}>
        <div className="kiosk-checkin__icon" aria-hidden="true">
          {busy && <span className="kiosk-checkin__spinner" />}
          {phase === 'granted' && <span className="kiosk-checkin__mark kiosk-checkin__mark--ok">✓</span>}
          {isError && <span className="kiosk-checkin__mark kiosk-checkin__mark--bad">!</span>}
        </div>

        <p className="kiosk-checkin__message" role="status" aria-live="polite">
          {MESSAGES[phase]}
        </p>

        {member?.name && <p className="kiosk-checkin__member">{member.name}</p>}

        <div className="kiosk-checkin__actions">
          {busy && (
            <button type="button" className="kiosk-checkin__btn kiosk-checkin__btn--ghost" onClick={cancel}>
              Cancel
            </button>
          )}
          {isError && (
            <>
              <button type="button" className="kiosk-checkin__btn" onClick={retry}>Try again</button>
              <button type="button" className="kiosk-checkin__btn kiosk-checkin__btn--ghost" onClick={onClose}>Close</button>
            </>
          )}
          {phase === 'granted' && (
            <button type="button" className="kiosk-checkin__btn" onClick={onClose}>Done</button>
          )}
        </div>
      </div>
    </div>
  )
}
