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

  it('shows the checked-at date as dd/MM/yyyy (UI-DATE-001 item 3) while keeping the original ISO instant and the time portion unchanged', async () => {
    stubFetch(() => jsonResponse(HEALTH_BODY))
    renderCard()

    await screen.findByText('الخادم يعمل')
    const time = screen.getByText((_, element) => element?.tagName === 'TIME')
    // HEALTH_BODY.timestamp is '2026-01-15T10:30:00+00:00' — the date part must render dd/MM/yyyy regardless of
    // the runtime's local time zone shifting the clock time, and the element must still carry the exact ISO
    // instant for assistive tech / machine reading, unchanged by the display reformat.
    expect(time).toHaveAttribute('dateTime', HEALTH_BODY.timestamp)
    expect(time.textContent).toMatch(/^\d{2}\/\d{2}\/\d{4}, /)
    const localDatePart = new Intl.DateTimeFormat('en-u-nu-latn-ca-gregory', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(
      new Date(HEALTH_BODY.timestamp),
    )
    const [mm, dd, yyyy] = localDatePart.split('/')
    expect(time.textContent).toContain(`${dd}/${mm}/${yyyy}`)
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
