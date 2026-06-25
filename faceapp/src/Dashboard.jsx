import './Dashboard.css'

function getInitials(name, fallback = 'NU') {
  if (!name) {
    return fallback
  }

  return name
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
}

export default function Dashboard({
  user,
  selectedUserId,
  draftUser,
  loading,
  refreshing,
  onOpenCamera,
  onStartCheckin,
}) {
  const isNewUser = !selectedUserId
  const displayName = isNewUser
    ? (draftUser.name.trim() || 'New User')
    : (user?.name || 'New User')
  const displayPhoto = isNewUser ? null : user?.facePhoto
  const initials = getInitials(displayName)

  return (
    <div className="dashboard">
      <div className="db-blob db-blob-1" aria-hidden="true" />
      <div className="db-blob db-blob-2" aria-hidden="true" />

      <main className="db-main db-main-minimal">
        <section className="minimal-capture-card glass animate-fadeUp">
          <div className="minimal-photo-wrap">
            {displayPhoto ? (
              <img src={displayPhoto} alt="Face" className="profile-face-img minimal-photo-img" />
            ) : (
              <div className="minimal-photo-empty">
                <div className="empty-face-icon minimal-empty-icon">
                  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                    <circle cx="12" cy="13" r="3" />
                  </svg>
                </div>
                <div className="minimal-photo-initials">{initials}</div>
              </div>
            )}
          </div>

          <h1 className="minimal-name">{displayName}</h1>

          <button
            id="enroll-face-btn"
            className="enroll-btn minimal-capture-btn"
            onClick={onOpenCamera}
            disabled={loading || refreshing}
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z" />
              <circle cx="12" cy="13" r="4" />
            </svg>
            Capture My Face
          </button>

          {!isNewUser && onStartCheckin && (
            <button
              type="button"
              className="enroll-btn minimal-capture-btn checkin-btn"
              onClick={onStartCheckin}
              disabled={loading || refreshing}
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
              Check in with Face
            </button>
          )}

          <button
            type="button"
            className="back-btn"
            onClick={() => window.history.back()}
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M15 18l-6-6 6-6" />
            </svg>
            Back
          </button>
        </section>
      </main>
    </div>
  )
}
