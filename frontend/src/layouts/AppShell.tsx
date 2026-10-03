import { useEffect, useRef } from 'react'
import { Outlet, useLocation } from 'react-router'
import { useI18n } from '../i18n/context'
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar'
import { AppHeader } from './AppHeader'
import { AppSidebar } from './AppSidebar'

/**
 * The authenticated application shell: collapsible sidebar (a Sheet on mobile), sticky header with breadcrumbs,
 * language and account menus, and ONE focusable main region. The sidebar sits at the inline-start edge in both
 * directions (right in Arabic, left in English): the shadcn components were migrated to logical properties.
 */
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
    <SidebarProvider>
      <a
        className="sr-only z-50 rounded-md bg-card px-4 py-2 font-semibold text-primary shadow focus:not-sr-only focus:absolute focus:start-4 focus:top-2"
        href="#main-content"
      >
        {messages.app.skipToContent}
      </a>

      <AppSidebar />

      <SidebarInset className="min-w-0">
        <AppHeader />
        <main id="main-content" ref={mainRef} tabIndex={-1} className="mx-auto w-full max-w-[88rem] min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
          <Outlet />
        </main>
      </SidebarInset>
    </SidebarProvider>
  )
}
