const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/$/, '')

function buildApiUrl(path) {
  return `${apiBaseUrl}${path}`
}

async function readJson(response) {
  return response.json().catch(() => ({}))
}

async function expectJson(response, fallbackMessage) {
  const data = await readJson(response)

  if (!response.ok || data.ok === false) {
    const error = new Error(data.message || data.error || fallbackMessage)
    error.code = data.error      // machine-readable code, e.g. 'device_offline'
    error.status = response.status
    throw error
  }

  return data
}

export async function fetchAppDashboard(managedUserId) {
  const search = new URLSearchParams()

  if (managedUserId) {
    search.set('managed_user_id', managedUserId)
  }

  const path = search.size > 0
    ? `/api/app/dashboard?${search.toString()}`
    : '/api/app/dashboard'

  const response = await fetch(buildApiUrl(path), {
    headers: {
      'Accept': 'application/json',
    },
  })

  return expectJson(response, 'Failed to load the FaceApp dashboard.')
}

export async function enrollFace(payload) {
  const response = await fetch(buildApiUrl('/api/enrollments'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(payload),
  })

  return expectJson(response, 'Face enrollment failed.')
}

// --- face_auth (proxied through our backend; the API key never reaches here) ---

export async function startFaceAuth(payload) {
  const response = await fetch(buildApiUrl('/api/face-auth/start'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(payload),
  })

  return expectJson(response, 'Could not start face verification.')
}

export async function getFaceAuthSession(sessionId) {
  const response = await fetch(buildApiUrl(`/api/face-auth/session/${encodeURIComponent(sessionId)}`), {
    headers: { 'Accept': 'application/json' },
  })

  return expectJson(response, 'Could not read the verification session.')
}

export async function cancelFaceAuthSession(sessionId) {
  const response = await fetch(buildApiUrl(`/api/face-auth/session/${encodeURIComponent(sessionId)}/cancel`), {
    method: 'POST',
    headers: { 'Accept': 'application/json' },
  })

  return expectJson(response, 'Could not cancel the verification session.')
}

// --- kiosk check-in (face verification → existing turnstile) ---

export async function startKioskFaceCheckin(payload) {
  const response = await fetch(buildApiUrl('/api/kiosk/face-checkin/start'), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(payload),
  })

  return expectJson(response, 'Could not start face verification.')
}

export async function completeKioskFaceCheckin(sessionId) {
  const response = await fetch(buildApiUrl(`/api/kiosk/face-checkin/${encodeURIComponent(sessionId)}/complete`), {
    method: 'POST',
    headers: { 'Accept': 'application/json' },
  })

  return expectJson(response, 'Could not complete check-in.')
}
