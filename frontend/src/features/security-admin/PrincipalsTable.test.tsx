import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context'
import { I18nProvider } from '../../i18n/I18nProvider'
import { jsonResponse, fakeAuthValue, stubFetch } from '../../test/render'
import { PrincipalsTable } from './PrincipalsTable'

const PRINCIPALS_PAGE = {
  data: [
    { id: 'p1', username: 'admin', display_name: 'Admin One', status: 'ACTIVE', version: 3, created_at: null, updated_at: null },
    { id: 'p2', username: 'jsmith', display_name: 'J Smith', status: 'DISABLED', version: 1, created_at: null, updated_at: null },
  ],
}

function renderTable(permissions: string[]) {
  return render(
    <I18nProvider>
      <AuthContext.Provider value={fakeAuthValue({ permissions, sessionExpired: vi.fn() })}>
        <PrincipalsTable />
      </AuthContext.Provider>
    </I18nProvider>,
  )
}

describe('PrincipalsTable', () => {
  it('lists principals with their status', async () => {
    stubFetch(() => jsonResponse(PRINCIPALS_PAGE))
    renderTable(['security.users.view'])

    expect(await screen.findByText('admin')).toBeInTheDocument()
    const row = screen.getByText('admin').closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('نشط')).toBeInTheDocument()
    expect(screen.getByText('معطّل')).toBeInTheDocument()
  })

  it('hides the actions column without security.users.status.manage', async () => {
    stubFetch(() => jsonResponse(PRINCIPALS_PAGE))
    renderTable(['security.users.view'])

    await screen.findByText('admin')
    expect(screen.queryByRole('button', { name: 'تعطيل' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'تفعيل' })).not.toBeInTheDocument()
  })

  it('disables an active principal and refreshes the list', async () => {
    let call = 0
    stubFetch((url) => {
      if (url.includes('/status')) {
        return jsonResponse({ ...PRINCIPALS_PAGE.data[0], status: 'DISABLED', version: 4 })
      }
      call += 1
      return jsonResponse(call === 1 ? PRINCIPALS_PAGE : { data: [{ ...PRINCIPALS_PAGE.data[0], status: 'DISABLED', version: 4 }, PRINCIPALS_PAGE.data[1]] })
    })
    const user = userEvent.setup()
    renderTable(['security.users.view', 'security.users.status.manage'])

    await screen.findByText('admin')
    await user.click(screen.getAllByRole('button', { name: 'تعطيل' })[0]!)

    expect(await screen.findAllByText('معطّل')).toHaveLength(2)
  })

  it('shows a generic conflict message on a 409 without leaking which protection fired', async () => {
    stubFetch((url) => (url.includes('/status') ? jsonResponse({ message: 'conflict' }, 409) : jsonResponse(PRINCIPALS_PAGE)))
    const user = userEvent.setup()
    renderTable(['security.users.view', 'security.users.status.manage'])

    await screen.findByText('admin')
    await user.click(screen.getAllByRole('button', { name: 'تعطيل' })[0]!)

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('تعذّر إتمام العملية؛ قد تكون البيانات قد تغيّرت')
  })

  it('calls sessionExpired and does not crash on a 401 during an action', async () => {
    const sessionExpired = vi.fn()
    stubFetch((url) => (url.includes('/status') ? jsonResponse({ message: 'Unauthenticated.' }, 401) : jsonResponse(PRINCIPALS_PAGE)))
    const user = userEvent.setup()

    render(
      <I18nProvider>
        <AuthContext.Provider
          value={fakeAuthValue({ permissions: ['security.users.view', 'security.users.status.manage'], sessionExpired })}
        >
          <PrincipalsTable />
        </AuthContext.Provider>
      </I18nProvider>,
    )

    await screen.findByText('admin')
    await user.click(screen.getAllByRole('button', { name: 'تعطيل' })[0]!)

    await waitFor(() => expect(sessionExpired).toHaveBeenCalledTimes(1))
  })

  it('shows an empty state with no rows', async () => {
    stubFetch(() => jsonResponse({ data: [] }))
    renderTable(['security.users.view'])

    expect(await screen.findByText('لا يوجد مستخدمون.')).toBeInTheDocument()
  })
})
