import { useState, type ReactNode } from 'react'
import { RouterProvider } from 'react-router'
import { AuthProvider } from '../features/auth/AuthProvider'
import { I18nProvider } from '../i18n/I18nProvider'
import { useI18n } from '../i18n/context'
import { DirectionProvider } from '@/components/ui/direction'
import { ErrorBoundary } from './ErrorBoundary'
import { createAppRouter } from './router'

/** Radix primitives (menus, tooltips, sheets) read the reading direction from this provider, not from the DOM. */
function AppDirection({ children }: { children: ReactNode }) {
  const { dir } = useI18n()
  return <DirectionProvider direction={dir}>{children}</DirectionProvider>
}

export function App() {
  // Created once per mount so the router is not rebuilt on re-render.
  const [router] = useState(createAppRouter)

  return (
    <I18nProvider>
      <AppDirection>
        <AuthProvider>
          <ErrorBoundary>
            <RouterProvider router={router} />
          </ErrorBoundary>
        </AuthProvider>
      </AppDirection>
    </I18nProvider>
  )
}
