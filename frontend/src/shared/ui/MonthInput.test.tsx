import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { MonthInput } from './MonthInput'

const LABEL = 'شهر التقرير'

function Controlled({ onChange }: { onChange: (value: string) => void }) {
  const [value, setValue] = useState('')
  return (
    <MonthInput
      label={LABEL}
      value={value}
      onChange={(next) => {
        setValue(next)
        onChange(next)
      }}
      error={null}
    />
  )
}

function renderMonthInput(onChange: (value: string) => void) {
  return render(
    <I18nProvider initialLocale="ar">
      <Controlled onChange={onChange} />
    </I18nProvider>,
  )
}

describe('MonthInput — typing and the native <input type="month"> contract it replaces', () => {
  it('emits "YYYY-MM" only once month and year are both filled', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderMonthInput(onChange)

    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '11')
    expect(onChange).not.toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}$/))

    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2026')
    expect(onChange).toHaveBeenLastCalledWith('2026-11')
  })

  it('rejects a month outside 01-12', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderMonthInput(onChange)

    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '13')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2026')
    expect(onChange).not.toHaveBeenCalledWith('2026-13')
  })

  it('auto-advances focus from month to year', async () => {
    const user = userEvent.setup()
    renderMonthInput(vi.fn())
    const month = screen.getByLabelText(`${LABEL} — الشهر`)
    const year = screen.getByLabelText(`${LABEL} — السنة`)
    await user.type(month, '11')
    expect(year).toHaveFocus()
  })

  it('rejects the year 0000 — the same year range (1-9999) already approved for DateInput, never a nonsense year silently committed as a real reporting month', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderMonthInput(onChange)

    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '05')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '0000')

    expect(onChange).not.toHaveBeenCalledWith('0000-05')
    expect(await screen.findByText('شهر غير صالح.')).toBeInTheDocument()
  })

  it('calls showPicker on the hidden native input when the calendar button is clicked', async () => {
    const user = userEvent.setup()
    renderMonthInput(vi.fn())
    const hidden = screen.getByLabelText(LABEL) as HTMLInputElement & { showPicker: () => void }
    const showPicker = vi.fn()
    hidden.showPicker = showPicker

    await user.click(screen.getByRole('button', { name: 'فتح التقويم' }))

    expect(showPicker).toHaveBeenCalledTimes(1)
  })

  it('falls back to focusing the month segment when showPicker is unavailable', async () => {
    const user = userEvent.setup()
    renderMonthInput(vi.fn())

    await user.click(screen.getByRole('button', { name: 'فتح التقويم' }))

    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveFocus()
  })

  it('reports via onValidityChange when typed segments are non-empty but cannot form a valid month, and clears it once valid', async () => {
    const onValidityChange = vi.fn()
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(
      <I18nProvider initialLocale="ar">
        <MonthInput label={LABEL} value="" onChange={onChange} error={null} onValidityChange={onValidityChange} />
      </I18nProvider>,
    )

    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '13')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2026')

    expect(onValidityChange).toHaveBeenLastCalledWith(true)
    expect(onChange).not.toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}$/))

    await user.clear(screen.getByLabelText(`${LABEL} — الشهر`))
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '11')

    expect(onValidityChange).toHaveBeenLastCalledWith(false)
    expect(onChange).toHaveBeenLastCalledWith('2026-11')
  })

  it('shows the Latin lowercase mm/yyyy placeholders in Arabic — labels and error text stay translated, only the placeholder hint is Latin', () => {
    render(
      <I18nProvider initialLocale="ar">
        <MonthInput label={LABEL} value="" onChange={vi.fn()} error={null} />
      </I18nProvider>,
    )

    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveAttribute('placeholder', 'mm')
    expect(screen.getByLabelText(`${LABEL} — السنة`)).toHaveAttribute('placeholder', 'yyyy')
  })

  it('shows the same Latin lowercase mm/yyyy placeholders in English, with English-translated labels', () => {
    render(
      <I18nProvider initialLocale="en">
        <MonthInput label={LABEL} value="" onChange={vi.fn()} error={null} />
      </I18nProvider>,
    )

    expect(screen.getByLabelText(`${LABEL} — Month`)).toHaveAttribute('placeholder', 'mm')
    expect(screen.getByLabelText(`${LABEL} — Year`)).toHaveAttribute('placeholder', 'yyyy')
  })

  it('keeps the hidden native month input as the one accessible-by-label element and in sync via fireEvent.change (the existing DashboardPage.test.tsx interaction)', () => {
    renderMonthInput(vi.fn())
    const hidden = screen.getByLabelText(LABEL)

    fireEvent.change(hidden, { target: { value: '2026-03' } })
    expect(hidden).toHaveValue('2026-03')
    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveValue('03')
    expect(screen.getByLabelText(`${LABEL} — السنة`)).toHaveValue('2026')

    fireEvent.change(hidden, { target: { value: '' } })
    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveValue('')
    expect(screen.getByLabelText(`${LABEL} — السنة`)).toHaveValue('')
  })
})
