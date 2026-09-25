import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { jsonResponse, stubFetch } from '../../test/render'
import { RolesTable } from './RolesTable'

function renderTable() {
  return render(
    <I18nProvider>
      <RolesTable />
    </I18nProvider>,
  )
}

describe('RolesTable', () => {
  it('lists roles with their active/inactive state', async () => {
    stubFetch(() =>
      jsonResponse({
        data: [
          { id: 'r1', code: 'SECURITY_ADMINISTRATOR', name_ar: 'مسؤول الأمان', name_en: 'Security Administrator', description: null, is_system: true, is_active: true, version: 1, created_at: null, updated_at: null },
          { id: 'r2', code: 'VIEWER', name_ar: 'مشاهد', name_en: 'Viewer', description: null, is_system: false, is_active: false, version: 1, created_at: null, updated_at: null },
        ],
      }),
    )
    renderTable()

    expect(await screen.findByText('SECURITY_ADMINISTRATOR')).toBeInTheDocument()
    expect(screen.getByText('نشط')).toBeInTheDocument()
    expect(screen.getByText('غير نشط')).toBeInTheDocument()
  })

  it('shows an accessible error with a working retry', async () => {
    let calls = 0
    stubFetch(() => {
      calls += 1
      return calls === 1 ? jsonResponse({ message: 'boom' }, 500) : jsonResponse({ data: [] })
    })
    const user = userEvent.setup()
    renderTable()

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('تعذّر تحميل الأدوار')
    expect(alert).not.toHaveTextContent('boom')

    await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))

    expect(await screen.findByText('لا توجد أدوار.')).toBeInTheDocument()
  })
})
