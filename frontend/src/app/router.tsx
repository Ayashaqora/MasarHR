import { createBrowserRouter, createMemoryRouter, Navigate, type RouteObject } from 'react-router'
import { RequireAuth } from '../features/auth/RequireAuth'
import { AppShell } from '../layouts/AppShell'
import { HomePage } from '../pages/HomePage'
import { LoginPage } from '../pages/LoginPage'
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
      { path: 'login', element: <LoginPage /> },
      // S18: Employees is a real, authenticated read-only area (Employee 360 Foundation) — no
      // longer a navigation placeholder. Deep-linking to a specific employee/relationship works
      // (spec §S18 §20): every field the page needs comes from the route params themselves.
      {
        path: 'employees',
        element: <RequireAuth />,
        children: [
          { index: true, lazy: async () => ({ Component: (await import('../pages/EmployeesPage')).EmployeesPage }) },
          { path: ':personId/relationships/:relationshipId', lazy: async () => ({ Component: (await import('../pages/Employee360Page')).Employee360Page }) },
        ],
      },
      // S45: the aggregate Dashboard Foundation — one authenticated, read-only page over the S44 analytics response.
      { path: 'dashboard', element: <RequireAuth />, children: [{ index: true, lazy: async () => ({ Component: (await import('../pages/DashboardPage')).DashboardPage }) }] },
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
          { path: 'principals', lazy: async () => ({ Component: (await import('../pages/security/PrincipalsPage')).PrincipalsPage }) },
          { path: 'roles', lazy: async () => ({ Component: (await import('../pages/security/RolesPage')).RolesPage }) },
          { path: 'permissions', lazy: async () => ({ Component: (await import('../pages/security/PermissionsPage')).PermissionsPage }) },
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
