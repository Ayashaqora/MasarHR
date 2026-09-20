import { useEffect, useRef } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { NAV_ITEMS } from '../app/navigation'
import { useI18n } from '../i18n/context'

export function AppShell() {
  const { messages } = useI18n()
  const { pathname } = useLocation()
  const mainRef = useRef<HTMLElement>(null)
  const isFirstRender = useRef(true)

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
      </header>

      <nav className="app-shell__nav" aria-label={messages.app.mainNavigation}>
        <ul className="nav-list">
          {NAV_ITEMS.map((item) => (
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
