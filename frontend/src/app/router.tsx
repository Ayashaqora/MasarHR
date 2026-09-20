import { createBrowserRouter, createMemoryRouter, type RouteObject } from 'react-router'
import { AppShell } from '../layouts/AppShell'
import { HomePage } from '../pages/HomePage'
import { NotFoundPage } from '../pages/NotFoundPage'
import { PlaceholderPage } from '../pages/PlaceholderPage'
import { ErrorFallback } from './ErrorBoundary'

export const routes: RouteObject[] = [
  {
    path: '/',
    element: <AppShell />,
    errorElement: <ErrorFallback />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'employees', element: <PlaceholderPage navKey="employees" /> },
      { path: 'organization', element: <PlaceholderPage navKey="organization" /> },
      { path: 'reports', element: <PlaceholderPage navKey="reports" /> },
      { path: 'settings', element: <PlaceholderPage navKey="settings" /> },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]

export const createAppRouter = () => createBrowserRouter(routes)

/** Same routes without a browser history; used by tests. */
export const createTestRouter = (initialEntries: string[] = ['/']) =>
  createMemoryRouter(routes, { initialEntries })
