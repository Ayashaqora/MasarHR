import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import type { CurrentPrincipal } from '../features/auth/api'
import { CURRENT_PRINCIPAL_BODY, jsonResponse, renderApp, stubAppFetch } from '../test/render'

const AUTHENTICATED_HR_VIEWER: CurrentPrincipal = {
  ...CURRENT_PRINCIPAL_BODY,
  permissions: [...CURRENT_PRINCIPAL_BODY.permissions, 'hr.persons.view', 'hr.employment_relationships.view'],
}

const PERSON = { id: 'person-1', national_id: '1234567890', is_terminal: false, version: 1 }

const RELATIONSHIPS = [
  {
    id: 'rel-1',
    person_id: 'person-1',
    employment_type_id: 'et-1',
    employee_number: 'EMP-001',
    employee_number_scheme: 'PERMANENT',
    effective_from: '2020-01-01',
    effective_to: null,
    end_knowledge_state: 'NOT_APPLICABLE',
    ended_terminally: null,
    version: 1,
  },
]

function stubEmployeesApp(overrides?: (url: string) => Response | undefined) {
  return stubAppFetch({
    overrides: (url) => {
      const custom = overrides?.(url)
      if (custom) {
        return custom
      }
      if (url.includes('/auth/me')) {
        return jsonResponse(AUTHENTICATED_HR_VIEWER)
      }
      if (url.includes('/hr/persons/lookup')) {
        return jsonResponse(PERSON)
      }
      if (url.includes('/employment-relationships')) {
        return jsonResponse(RELATIONSHIPS)
      }
      return undefined
    },
  })
}

describe('EmployeesPage', () => {
  it('shows only the search form before a search is made', async () => {
    stubEmployeesApp()
    renderApp('/employees')

    expect(await screen.findByRole('heading', { level: 1, name: 'الموظفون' })).toBeInTheDocument()
    expect(screen.getByLabelText('رقم الهوية الوطنية')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('requires a value before searching', async () => {
    stubEmployeesApp()
    const user = userEvent.setup()
    renderApp('/employees')

    await user.click(await screen.findByRole('button', { name: 'بحث' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('يرجى إدخال رقم الهوية الوطنية.')
  })

  it('finds a person and lists their employment relationships with a link to Employee 360', async () => {
    stubEmployeesApp()
    const user = userEvent.setup()
    renderApp('/employees')

    await user.type(await screen.findByLabelText('رقم الهوية الوطنية'), '1234567890')
    await user.click(screen.getByRole('button', { name: 'بحث' }))

    expect(await screen.findByText('EMP-001')).toBeInTheDocument()
    expect(screen.getByText('دائم')).toBeInTheDocument()
    const link = screen.getByRole('link', { name: 'عرض الملف الشامل' })
    expect(link).toHaveAttribute('href', '/employees/person-1/relationships/rel-1')
  })

  it('shows a not-found message on a 404', async () => {
    stubEmployeesApp((url) => (url.includes('/hr/persons/lookup') ? jsonResponse({ message: 'Not found.' }, 404) : undefined))
    const user = userEvent.setup()
    renderApp('/employees')

    await user.type(await screen.findByLabelText('رقم الهوية الوطنية'), '0000000000')
    await user.click(screen.getByRole('button', { name: 'بحث' }))

    expect(await screen.findByText('لم يُعثر على موظف بهذا الرقم.')).toBeInTheDocument()
  })

  it('shows a generic failure message on a server error, never the raw server text', async () => {
    stubEmployeesApp((url) => (url.includes('/hr/persons/lookup') ? jsonResponse({ message: 'db exploded' }, 500) : undefined))
    const user = userEvent.setup()
    renderApp('/employees')

    await user.type(await screen.findByLabelText('رقم الهوية الوطنية'), '1234567890')
    await user.click(screen.getByRole('button', { name: 'بحث' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('حدث خطأ في الخادم')
    expect(screen.queryByText('db exploded')).not.toBeInTheDocument()
  })

  it('never renders a browsable "all employees" table — only a search result', async () => {
    stubEmployeesApp()
    renderApp('/employees')

    await screen.findByRole('heading', { level: 1, name: 'الموظفون' })
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('shows an unauthorized message without hr.persons.view', async () => {
    stubEmployeesApp((url) =>
      url.includes('/auth/me')
        ? jsonResponse({ ...CURRENT_PRINCIPAL_BODY, permissions: [] })
        : undefined,
    )
    renderApp('/employees')

    expect(await screen.findByText('لا تملك صلاحية الوصول')).toBeInTheDocument()
    expect(screen.queryByLabelText('رقم الهوية الوطنية')).not.toBeInTheDocument()
  })
})
