import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { CURRENT_PRINCIPAL_BODY, jsonResponse, renderApp, stubAppFetch } from '../test/render'

describe('authentication', () => {
  it('redirects an unauthenticated visitor from /security to /login', async () => {
    stubAppFetch()
    renderApp('/security')

    expect(await screen.findByRole('heading', { level: 1, name: 'تسجيل الدخول' })).toBeInTheDocument()
  })

  it('shows a sign-in link, not an account name, while unauthenticated', async () => {
    stubAppFetch()
    renderApp()

    expect(await screen.findByRole('link', { name: 'تسجيل الدخول' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'تسجيل الخروج' })).not.toBeInTheDocument()
  })

  it('signs in, lands on the page originally requested, and reveals the Security nav item', async () => {
    stubAppFetch({
      overrides: (url) => (url.includes('/auth/login') ? jsonResponse(CURRENT_PRINCIPAL_BODY) : undefined),
    })
    const user = userEvent.setup()
    renderApp('/security')

    await screen.findByRole('heading', { level: 1, name: 'تسجيل الدخول' })

    await user.type(screen.getByLabelText('اسم المستخدم'), 'admin')
    await user.type(screen.getByLabelText('كلمة المرور'), 'CorrectHorseBattery1!')
    await user.click(screen.getByRole('button', { name: 'تسجيل الدخول' }))

    expect(await screen.findByText('Admin One')).toBeInTheDocument()

    const nav = screen.getByRole('navigation', { name: 'التنقل الرئيسي' })
    expect(within(nav).getByRole('link', { name: 'الأمان' })).toBeInTheDocument()
  })

  it('shows a single generic message for both a wrong username and a wrong password', async () => {
    stubAppFetch({
      overrides: (url) =>
        url.includes('/auth/login') ? jsonResponse({ message: 'Invalid credentials.' }, 401) : undefined,
    })
    const user = userEvent.setup()
    renderApp('/login')

    await screen.findByRole('heading', { level: 1, name: 'تسجيل الدخول' })

    await user.type(screen.getByLabelText('اسم المستخدم'), 'nobody')
    await user.type(screen.getByLabelText('كلمة المرور'), 'wrong-password')
    await user.click(screen.getByRole('button', { name: 'تسجيل الدخول' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('اسم المستخدم أو كلمة المرور غير صحيحة.')
    // The raw server text must never reach the screen (§12/SEC-10 of the S03 authorization).
    expect(screen.queryByText('Invalid credentials.')).not.toBeInTheDocument()
  })

  it('signs out back to the unauthenticated state', async () => {
    stubAppFetch({ authenticated: true })
    const user = userEvent.setup()
    renderApp()

    await screen.findByText('Admin One')

    await user.click(screen.getByRole('button', { name: 'تسجيل الخروج' }))

    expect(await screen.findByRole('link', { name: 'تسجيل الدخول' })).toBeInTheDocument()
    expect(screen.queryByText('Admin One')).not.toBeInTheDocument()
  })
})
