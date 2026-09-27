import { useEffect, useRef } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { NAV_ITEMS } from '../app/navigation'
import { useAuth } from '../features/auth/context'
import { useI18n } from '../i18n/context'

export function AppShell() {
  const { messages } = useI18n()
  const { status, principal, logout } = useAuth()
  const { pathname } = useLocation()
  const mainRef = useRef<HTMLElement>(null)
  const isFirstRender = useRef(true)
  const visibleNavItems = NAV_ITEMS.filter((item) => !item.requiresAuth || status === 'authenticated')

  // Keyboard/screen-reader users land on the new page content after navigating.
  useEffect(() => {
    if (isFirstRender.current) {
      isFirstRender.current = false
      return
    }
    mainRef.current?.focus()
  }, [pathname])

  return (
    <div className="app-shell">
      <a className="skip-link" href="#main-content">
        {messages.app.skipToContent}
      </a>

      <header className="app-shell__header">
        <span className="brand">
          <span className="brand__mark" aria-hidden="true">
            م
          </span>
          <span className="brand__text">
            <span className="brand__name">{messages.app.name}</span>
            <span className="brand__tagline">{messages.app.tagline}</span>
          </span>
        </span>

        <div className="app-shell__account">
          {status === 'authenticated' && principal ? (
            <>
              <span className="app-shell__account-name">{principal.display_name}</span>
              <button
                type="button"
                className="button button--ghost"
                onClick={() => {
                  void logout()
                }}
              >
                {messages.auth.signOut}
              </button>
            </>
          ) : status === 'unauthenticated' ? (
            <NavLink to="/login" className="nav-link">
              {messages.auth.signIn}
            </NavLink>
          ) : null}
        </div>
      </header>

      <nav className="app-shell__nav" aria-label={messages.app.mainNavigation}>
        <ul className="nav-list">
          {visibleNavItems.map((item) => (
            <li key={item.to}>
              <NavLink to={item.to} end={item.end} className="nav-link">
                {messages.nav[item.labelKey]}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      <main id="main-content" className="app-shell__main" ref={mainRef} tabIndex={-1}>
        <Outlet />
      </main>
    </div>
  )
}
