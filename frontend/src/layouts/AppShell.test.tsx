import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { CURRENT_PRINCIPAL_BODY, jsonResponse, renderApp, stubAppFetch } from '../test/render'
import { setViewportWidth } from '../test/viewport'

const principalWith = (permissions: string[]) => ({ ...CURRENT_PRINCIPAL_BODY, permissions })
const stubAs = (permissions: string[]) =>
  stubAppFetch({ overrides: (url) => (url.includes('/auth/me') ? jsonResponse(principalWith(permissions)) : undefined) })
const navLinks = () =>
  within(screen.getByRole('navigation', { name: 'التنقل الرئيسي' }))
    .getAllByRole('link')
    .map((link) => link.textContent)

describe('Application shell (shadcn sidebar)', () => {
  it('shows only the always-available areas to a visitor', async () => {
    stubAppFetch()
    renderApp()

    await screen.findByRole('link', { name: 'تسجيل الدخول' })
    expect(navLinks()).toEqual(['الرئيسية', 'الهيكل التنظيمي', 'التقارير', 'الإعدادات'])
  })

  it('hides Dashboard, Employees and Security from an authenticated principal that lacks their permissions', async () => {
    stubAs([])
    renderApp()

    await screen.findByText('Admin One')
    expect(navLinks()).toEqual(['الرئيسية', 'الهيكل التنظيمي', 'التقارير', 'الإعدادات'])
  })

  it('shows each protected area exactly when its permission is held', async () => {
    stubAs(['hr.workforce_analytics.view', 'hr.persons.view', 'security.roles.view'])
    renderApp()

    await screen.findByText('Admin One')
    expect(navLinks()).toEqual(['الرئيسية', 'لوحة المؤشرات', 'الموظفون', 'الهيكل التنظيمي', 'التقارير', 'الأمان', 'الإعدادات'])
  })

  it('places the sidebar at the inline-start edge and keeps one main landmark', async () => {
    stubAs([])
    renderApp()

    await screen.findByText('Admin One')
    expect(document.documentElement).toHaveAttribute('dir', 'rtl')
    expect(document.querySelector('[data-slot="sidebar"][data-side="left"]')).not.toBeNull()
    expect(screen.getAllByRole('main')).toHaveLength(1)
  })

  it('switches the language from the header menu, flipping direction and labels', async () => {
    stubAppFetch()
    const user = userEvent.setup()
    renderApp()

    await screen.findByRole('link', { name: 'تسجيل الدخول' })
    await user.click(screen.getByRole('button', { name: 'اللغة' }))
    await user.click(await screen.findByRole('menuitemradio', { name: 'English' }))

    await waitFor(() => expect(document.documentElement).toHaveAttribute('dir', 'ltr'))
    expect(document.documentElement).toHaveAttribute('lang', 'en')
    expect(await screen.findByRole('link', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('shows breadcrumbs for the current area', async () => {
    stubAs(['hr.persons.view'])
    renderApp('/employees')

    await screen.findByRole('heading', { level: 1, name: 'الموظفون' })
    const crumbs = screen.getByRole('navigation', { name: 'مسار التنقل' })
    expect(within(crumbs).getByText('الرئيسية')).toBeInTheDocument()
    expect(within(crumbs).getByText('الموظفون')).toHaveAttribute('aria-current', 'page')
  })

  it('collapses the sidebar into a mobile sheet that opens from the header trigger', async () => {
    setViewportWidth(500)
    stubAppFetch()
    const user = userEvent.setup()
    renderApp()

    await screen.findByRole('link', { name: 'تسجيل الدخول' })
    // Closed on mobile: the navigation is not in the page until the trigger opens the sheet.
    expect(screen.queryByRole('navigation', { name: 'التنقل الرئيسي' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'تبديل الشريط الجانبي' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByRole('navigation', { name: 'التنقل الرئيسي' })).toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: 'الهيكل التنظيمي' })).toBeInTheDocument()
  })
})
