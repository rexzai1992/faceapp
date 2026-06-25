import { useCallback, useEffect, useState } from 'react'
import Dashboard from './Dashboard'
import Camera from './Camera'
import Preview from './Preview'
import KioskCheckin from './KioskCheckin'
import { enrollFace, fetchAppDashboard } from './api'
import './App.css'

const VIEW = { DASHBOARD: 'dashboard', CAMERA: 'camera', PREVIEW: 'preview', CHECKIN: 'checkin' }

// Identifies this physical kiosk in the check-in audit log. Override per kiosk
// via the Vite build arg / env; falls back to a single-kiosk default.
const KIOSK_ID = import.meta.env.VITE_KIOSK_ID || 'kiosk-1'

function extractRunningNumber(value) {
  const digits = String(value || '').replace(/\D/g, '')

  if (digits === '') {
    return 0
  }

  const parsed = Number.parseInt(digits, 10)

  return Number.isNaN(parsed) ? 0 : parsed
}

function buildNewUserDefaults(users) {
  const nextNumber = users.reduce((highestNumber, managedUser) => (
    Math.max(highestNumber, extractRunningNumber(managedUser.employeeId))
  ), 0) + 1

  const runningNumber = String(nextNumber).padStart(4, '0')

  return {
    employeeId: runningNumber,
    name: `User ${runningNumber}`,
  }
}

function normalizeUserSummary(user) {
  return {
    id: user.id,
    name: user.name,
    role: user.role || 'No role set',
    department: user.department || 'No department set',
    employeeId: user.employee_id,
    status: user.status || 'inactive',
  }
}

function normalizeSelectedUser(user) {
  if (!user) {
    return null
  }

  const enrolledAt = user.enrolled_at
    ? new Date(user.enrolled_at).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
      })
    : null

  return {
    id: user.id,
    name: user.name,
    role: user.role || 'No role set',
    department: user.department || 'No department set',
    employeeId: user.employee_id,
    joined: user.joined || 'Not set',
    accessLevel: user.access_level || 'Not set',
    status: user.status || 'inactive',
    faceId: user.recognition_id,
    facePhoto: user.face_photo,
    enrolledAt,
    activity: Array.isArray(user.activity) ? user.activity : [],
    deviceSyncs: Array.isArray(user.device_syncs)
      ? user.device_syncs.map((sync) => ({
          deviceId: sync.device_id,
          deviceName: sync.device_name,
          deviceKey: sync.device_key,
          isOnline: Boolean(sync.is_online),
          syncStatus: sync.sync_status,
          faceStatus: sync.face_status,
          lastSyncedAt: sync.last_synced_at,
          lastFaceSyncedAt: sync.last_face_synced_at,
          lastErrorMessage: sync.last_error_message,
        }))
      : [],
  }
}

function normalizeDevice(device) {
  return {
    id: device.id,
    name: device.name,
    deviceKey: device.device_key,
    isOnline: Boolean(device.is_online),
    personCount: device.person_count,
    faceCount: device.face_count,
  }
}

export default function App() {
  const [users, setUsers] = useState([])
  const [user, setUser] = useState(null)
  const [selectedUserId, setSelectedUserId] = useState('')
  const [activeDevices, setActiveDevices] = useState([])
  const [view, setView] = useState(VIEW.DASHBOARD)
  const [capturedPhoto, setCapturedPhoto] = useState(null)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [toast, setToast] = useState(null)

  const showToast = useCallback((msg, type = 'success') => {
    setToast({ msg, type })
    window.setTimeout(() => setToast(null), 3500)
  }, [])

  const draftUser = buildNewUserDefaults(users)

  const loadDashboard = useCallback(async (managedUserId, options = {}) => {
    const { silent = false, selectManagedUser = false } = options

    if (silent) {
      setRefreshing(true)
    } else {
      setLoading(true)
    }

    try {
      const data = await fetchAppDashboard(managedUserId)

      setUsers(Array.isArray(data.users) ? data.users.map(normalizeUserSummary) : [])
      setActiveDevices(Array.isArray(data.active_devices) ? data.active_devices.map(normalizeDevice) : [])

      if (selectManagedUser && managedUserId) {
        const normalizedSelectedUser = normalizeSelectedUser(data.selected_user)
        setUser(normalizedSelectedUser)
        setSelectedUserId(normalizedSelectedUser?.id ? String(normalizedSelectedUser.id) : '')
        return
      }

      setSelectedUserId('')
      setUser(null)
    } catch (error) {
      console.error(error)
      showToast(error.message || 'Failed to load FaceApp data.', 'error')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [showToast])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  const handleSelectUser = useCallback((nextUserId) => {
    setCapturedPhoto(null)
    setView(VIEW.DASHBOARD)

    if (!nextUserId) {
      setSelectedUserId('')
      setUser(null)
      return
    }

    setSelectedUserId(String(nextUserId))
    setUser(null)
    loadDashboard(nextUserId, { silent: true, selectManagedUser: true })
  }, [loadDashboard])

  const handleOpenCamera = useCallback(() => {
    if (activeDevices.length === 0) {
      showToast('Add at least one active device in admin before enrolling a face.', 'error')
      return
    }

    if (selectedUserId && !user) {
      showToast('Select a managed user before capturing a face.', 'error')
      return
    }

    setView(VIEW.CAMERA)
  }, [activeDevices.length, selectedUserId, showToast, user])

  const handleCapture = useCallback((dataUrl) => {
    setCapturedPhoto(dataUrl)
    setView(VIEW.PREVIEW)
  }, [])

  const handleSave = useCallback(async () => {
    if (!capturedPhoto) {
      return
    }

    setSaving(true)

    try {
      const payload = selectedUserId && user
        ? {
            managed_user_id: user.id,
            photo_data_url: capturedPhoto,
          }
        : {
            employee_id: draftUser.employeeId,
            name: draftUser.name,
            photo_data_url: capturedPhoto,
          }

      const result = await enrollFace(payload)

      const enrolledUserId = result.enrollment?.managed_user_id

      if (enrolledUserId) {
        await loadDashboard(enrolledUserId, { silent: true, selectManagedUser: true })
      } else {
        await loadDashboard(undefined, { silent: true })
      }

      const verifiedDevices = Array.isArray(result.enrollment.sync_results)
        ? result.enrollment.sync_results.filter((sync) => sync.status === 'verified').length
        : 0

      const totalDevices = Array.isArray(result.enrollment.sync_results)
        ? result.enrollment.sync_results.length
        : activeDevices.length

      if (result.enrollment.status === 'partial') {
        showToast(`Face enrolled on ${verifiedDevices} of ${totalDevices} devices. Check the sync status for the rest.`, 'warning')
      } else {
        showToast(`Face enrolled on ${verifiedDevices} device${verifiedDevices === 1 ? '' : 's'}.`)
      }
      setCapturedPhoto(null)
      setView(VIEW.DASHBOARD)
    } catch (error) {
      console.error(error)
      showToast(error.message || 'Face enrollment failed.', 'error')
    } finally {
      setSaving(false)
    }
  }, [activeDevices.length, capturedPhoto, draftUser.employeeId, draftUser.name, loadDashboard, selectedUserId, showToast, user])

  const handleStartCheckin = useCallback(() => {
    // Check-in needs an identified member (the app's existing "select user"
    // step). Without one there is nobody to verify against face_auth.
    if (!selectedUserId || !user) {
      showToast('Select a member before checking in with face.', 'error')
      return
    }

    setView(VIEW.CHECKIN)
  }, [selectedUserId, user, showToast])

  const handleCheckinResult = useCallback((result) => {
    if (result?.status === 'granted') {
      showToast('Access granted.')
    }
    // Other outcomes are shown inside the kiosk card; no toast needed.
  }, [showToast])

  const handleCloseCheckin = useCallback(() => {
    setView(VIEW.DASHBOARD)
  }, [])

  const handleRetake = useCallback(() => {
    setCapturedPhoto(null)
    setView(VIEW.CAMERA)
  }, [])

  const handleCloseCamera = useCallback(() => {
    setCapturedPhoto(null)
    setView(VIEW.DASHBOARD)
  }, [])

  return (
    <div className="app-root">
      <Dashboard
        users={users}
        user={user}
        selectedUserId={selectedUserId}
        draftUser={draftUser}
        activeDevices={activeDevices}
        loading={loading}
        refreshing={refreshing}
        onSelectUser={handleSelectUser}
        onOpenCamera={handleOpenCamera}
        onStartCheckin={handleStartCheckin}
      />

      {view === VIEW.CAMERA && (
        <Camera
          onCapture={handleCapture}
          onClose={handleCloseCamera}
        />
      )}

      {view === VIEW.PREVIEW && capturedPhoto && (
        <Preview
          photo={capturedPhoto}
          onSave={handleSave}
          onRetake={handleRetake}
          saving={saving}
        />
      )}

      {view === VIEW.CHECKIN && user && (
        <KioskCheckin
          kioskId={KIOSK_ID}
          member={{
            id: user.id,
            name: user.name,
            employeeId: user.employeeId,
            personId: user.faceId,
          }}
          onResult={handleCheckinResult}
          onClose={handleCloseCheckin}
        />
      )}

      {toast && (
        <div className={`toast toast-${toast.type} animate-fadeUp`}>
          <div className="toast-icon">
            {toast.type === 'success' ? (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                <polyline points="20 6 9 17 4 12" />
              </svg>
            ) : toast.type === 'warning' ? (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
                <path d="M12 9v4" />
                <path d="M12 17h.01" />
              </svg>
            ) : (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round">
                <circle cx="12" cy="12" r="10" />
                <line x1="12" y1="8" x2="12" y2="12" />
                <line x1="12" y1="16" x2="12.01" y2="16" />
              </svg>
            )}
          </div>
          <span>{toast.msg}</span>
        </div>
      )}
    </div>
  )
}
