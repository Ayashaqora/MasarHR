import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { fakeAuthValue } from '../../test/render'
import { AuthContext } from './context'
import { PermissionGate } from './PermissionGate'

function renderGate(permission: string, granted: string[]) {
  return render(
    <I18nProvider>
      <AuthContext.Provider value={fakeAuthValue({ permissions: granted })}>
        <PermissionGate permission={permission}>
          <p>secret content</p>
        </PermissionGate>
      </AuthContext.Provider>
    </I18nProvider>,
  )
}

describe('PermissionGate', () => {
  it('renders its children when the principal holds the permission', () => {
    renderGate('security.users.view', ['security.users.view'])

    expect(screen.getByText('secret content')).toBeInTheDocument()
  })

  it('shows an unauthorized panel, not the children, when the permission is missing', () => {
    renderGate('security.users.view', ['security.roles.view'])

    expect(screen.queryByText('secret content')).not.toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('لا تملك صلاحية الوصول')
  })
})
