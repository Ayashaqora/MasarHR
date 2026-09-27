import { createBrowserRouter, createMemoryRouter, Navigate, type RouteObject } from 'react-router'
import { RequireAuth } from '../features/auth/RequireAuth'
import { AppShell } from '../layouts/AppShell'
import { HomePage } from '../pages/HomePage'
import { LoginPage } from '../pages/LoginPage'
import { NotFoundPage } from '../pages/NotFoundPage'
import { PlaceholderPage } from '../pages/PlaceholderPage'
import { PermissionsPage } from '../pages/security/PermissionsPage'
import { PrincipalsPage } from '../pages/security/PrincipalsPage'
import { RolesPage } from '../pages/security/RolesPage'
import { ErrorFallback } from './ErrorBoundary'

export const routes: RouteObject[] = [
  {
    path: '/',
    element: <AppShell />,
    errorElement: <ErrorFallback />,
    children: [
      { index: true, element: <HomePage /> },
      { path: 'login', element: <LoginPage /> },
      { path: 'employees', element: <PlaceholderPage navKey="employees" /> },
      { path: 'organization', element: <PlaceholderPage navKey="organization" /> },
      { path: 'reports', element: <PlaceholderPage navKey="reports" /> },
      { path: 'settings', element: <PlaceholderPage navKey="settings" /> },
      {
        path: 'security',
        // Authentication is checked once, here, for the whole section. Which permission a given
        // Security page needs is different per page, so that check lives on the page itself.
        element: <RequireAuth />,
        children: [
          { index: true, element: <Navigate to="principals" replace /> },
          { path: 'principals', element: <PrincipalsPage /> },
          { path: 'roles', element: <RolesPage /> },
          { path: 'permissions', element: <PermissionsPage /> },
        ],
      },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]

export const createAppRouter = () => createBrowserRouter(routes)

/** Same routes without a browser history; used by tests. */
export const createTestRouter = (initialEntries: string[] = ['/']) =>
  createMemoryRouter(routes, { initialEntries })
