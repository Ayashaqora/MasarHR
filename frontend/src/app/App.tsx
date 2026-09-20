import { useState } from 'react'
import { RouterProvider } from 'react-router'
import { I18nProvider } from '../i18n/I18nProvider'
import { ErrorBoundary } from './ErrorBoundary'
import { createAppRouter } from './router'

export function App() {
  // Created once per mount so the router is not rebuilt on re-render.
  const [router] = useState(createAppRouter)

  return (
    <I18nProvider>
      <ErrorBoundary>
        <RouterProvider router={router} />
      </ErrorBoundary>
    </I18nProvider>
  )
}
