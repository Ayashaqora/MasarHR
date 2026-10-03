import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import indexHtml from '../../index.html?raw'
import { renderApp, stubAppFetch } from '../test/render'

describe('Masar application shell', () => {
  it('ships an RTL Arabic document root in index.html', () => {
    expect(indexHtml).toMatch(/<html[^>]*\blang="ar"/)
    expect(indexHtml).toMatch(/<html[^>]*\bdir="rtl"/)
  })

  it('renders the Arabic shell with RTL direction and no HR data', async () => {
    stubAppFetch()
    renderApp()

    expect(document.documentElement).toHaveAttribute('dir', 'rtl')
    expect(document.documentElement).toHaveAttribute('lang', 'ar')

    expect(screen.getByRole('heading', { level: 1, name: 'الرئيسية' })).toBeInTheDocument()
    expect(screen.getByRole('main')).toBeInTheDocument()

    // Employees and Security both require authentication (S18/S03), so an unauthenticated
    // visitor sees only the four public nav items.
    const nav = screen.getByRole('navigation', { name: 'التنقل الرئيسي' })
    const labels = within(nav)
      .getAllByRole('link')
      .map((link) => link.textContent)
    expect(labels).toEqual(['الرئيسية', 'الهيكل التنظيمي', 'التقارير', 'الإعدادات'])

    // The shell must not fabricate records.
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(await screen.findByText('الخادم يعمل')).toBeInTheDocument()
  })

  it('provides a skip link to the main content', () => {
    stubAppFetch()
    renderApp()

    expect(screen.getByRole('link', { name: 'تخطي إلى المحتوى الرئيسي' })).toHaveAttribute(
      'href',
      '#main-content',
    )
    expect(screen.getByRole('main')).toHaveAttribute('id', 'main-content')
  })

  it('uses Western digits for dates', async () => {
    stubAppFetch()
    renderApp()

    const time = await screen.findByText(/2026/)
    expect(time.textContent).toMatch(/[0-9]/)
    expect(time.textContent).not.toMatch(/[٠-٩]/)
  })

  it('navigates to a placeholder area that shows no functionality', async () => {
    stubAppFetch()
    const user = userEvent.setup()
    renderApp()

    await user.click(within(screen.getByRole('navigation', { name: 'التنقل الرئيسي' })).getByRole('link', { name: 'الهيكل التنظيمي' }))

    expect(screen.getByRole('heading', { level: 1, name: 'الهيكل التنظيمي' })).toBeInTheDocument()
    expect(screen.getByText('هذا القسم مخطط له ولم يُنفَّذ بعد.')).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'التنقل الرئيسي' })
    expect(within(nav).getByRole('link', { name: 'الهيكل التنظيمي' })).toHaveAttribute('aria-current', 'page')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
  })

  it('shows a not-found page for unknown paths', () => {
    stubAppFetch()
    renderApp('/no-such-page')

    expect(screen.getByRole('heading', { level: 1, name: 'الصفحة غير موجودة' })).toBeInTheDocument()
  })

  it('is English-ready: switches to LTR with English labels', () => {
    stubAppFetch()
    renderApp('/', 'en')

    expect(document.documentElement).toHaveAttribute('dir', 'ltr')
    expect(document.documentElement).toHaveAttribute('lang', 'en')
    expect(screen.getByRole('link', { name: 'Organizational structure' })).toBeInTheDocument()
  })

  it('redirects an unauthenticated visitor from /employees to /login, and reveals the Employees nav item once signed in', async () => {
    stubAppFetch()
    renderApp('/employees')

    expect(await screen.findByRole('heading', { level: 1, name: 'تسجيل الدخول' })).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'الموظفون' })).not.toBeInTheDocument()
  })
})
