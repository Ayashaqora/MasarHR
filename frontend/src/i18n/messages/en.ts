import type { Messages } from './types'

export const en: Messages = {
  app: {
    name: 'Masar',
    tagline: 'Human Resources & Workforce Management System',
    documentTitle: 'Masar — Human Resources & Workforce Management',
    skipToContent: 'Skip to main content',
    mainNavigation: 'Main navigation',
  },
  nav: {
    home: 'Home',
    employees: 'Employees',
    organization: 'Organizational structure',
    reports: 'Reports',
    settings: 'Settings',
  },
  home: {
    title: 'Home',
    intro: 'This is the Masar application foundation. Functional areas are added in later stages.',
  },
  placeholder: {
    notImplemented: 'This area is planned and has not been implemented yet.',
  },
  systemStatus: {
    title: 'Server connection',
    loading: 'Checking connection…',
    ok: 'Server is running',
    checkedAt: 'Last checked',
    failed: 'Could not reach the server',
    retry: 'Try again',
  },
  errors: {
    title: 'An unexpected error occurred',
    description: 'This page could not be displayed. You can try again or return home.',
    reload: 'Reload page',
    backHome: 'Back to home',
    notFoundTitle: 'Page not found',
    notFoundDescription: 'The link you requested is not available.',
    network: 'The server could not be reached. Check your connection and try again.',
    timeout: 'The server took too long to respond. Try again.',
    server: 'A server error occurred. Try again later.',
    client: 'The request could not be completed.',
    unknown: 'An unknown error occurred.',
  },
}
