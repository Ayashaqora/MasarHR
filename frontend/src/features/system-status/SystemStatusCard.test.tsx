import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { HEALTH_BODY, jsonResponse, stubFetch } from '../../test/render'
import { SystemStatusCard } from './SystemStatusCard'

function renderCard() {
  return render(
    <I18nProvider>
      <SystemStatusCard />
    </I18nProvider>,
  )
}

describe('SystemStatusCard', () => {
  it('shows loading and then the healthy state', async () => {
    stubFetch(() => jsonResponse(HEALTH_BODY))
    renderCard()

    expect(screen.getByText('جارٍ التحقق من الاتصال…')).toBeInTheDocument()
    expect(await screen.findByText('الخادم يعمل')).toBeInTheDocument()
  })

  it('shows an accessible error with a working retry', async () => {
    let calls = 0
    stubFetch(() => {
      calls += 1
      return calls === 1 ? jsonResponse({ message: 'boom' }, 500) : jsonResponse(HEALTH_BODY)
    })
    const user = userEvent.setup()
    renderCard()

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('تعذّر الاتصال بالخادم')
    expect(alert).not.toHaveTextContent('boom')

    await user.click(screen.getByRole('button', { name: 'إعادة المحاولة' }))

    expect(await screen.findByText('الخادم يعمل')).toBeInTheDocument()
    expect(calls).toBe(2)
  })
})
