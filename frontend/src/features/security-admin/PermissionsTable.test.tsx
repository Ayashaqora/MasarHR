import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { jsonResponse, stubFetch } from '../../test/render'
import { PermissionsTable } from './PermissionsTable'

describe('PermissionsTable', () => {
  it('lists permission codes and their module', async () => {
    stubFetch(() =>
      jsonResponse({
        data: [
          { id: 'perm1', code: 'security.users.view', module: 'security', description: 'View users', created_at: null },
        ],
      }),
    )

    render(
      <I18nProvider>
        <PermissionsTable />
      </I18nProvider>,
    )

    expect(await screen.findByText('security.users.view')).toBeInTheDocument()
    expect(screen.getByText('View users')).toBeInTheDocument()
  })

  it('shows an empty state with no rows', async () => {
    stubFetch(() => jsonResponse({ data: [] }))

    render(
      <I18nProvider>
        <PermissionsTable />
      </I18nProvider>,
    )

    expect(await screen.findByText('لا توجد صلاحيات.')).toBeInTheDocument()
  })
})
