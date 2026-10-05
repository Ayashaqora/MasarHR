import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider'
import { DateInput } from './DateInput'

const LABEL = 'تاريخ البدء'

function Controlled({ onChange, initial = '' }: { onChange: (value: string) => void; initial?: string }) {
  const [value, setValue] = useState(initial)
  return (
    <DateInput
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

function renderDateInput(props: { onChange: (value: string) => void; initial?: string }) {
  return render(
    <I18nProvider initialLocale="ar">
      <Controlled {...props} />
    </I18nProvider>,
  )
}

describe('DateInput — typing, validation and ISO emission', () => {
  it('never emits while typing is incomplete, then emits the ISO date once day/month/year are all filled', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderDateInput({ onChange })

    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '05')
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '10')
    expect(onChange).not.toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/))

    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2026')
    expect(onChange).toHaveBeenLastCalledWith('2026-10-05')
  })

  it('auto-advances focus from day to month to year as each segment fills', async () => {
    const user = userEvent.setup()
    renderDateInput({ onChange: vi.fn() })

    const day = screen.getByLabelText(`${LABEL} — اليوم`)
    const month = screen.getByLabelText(`${LABEL} — الشهر`)
    const year = screen.getByLabelText(`${LABEL} — السنة`)

    await user.type(day, '05')
    expect(month).toHaveFocus()
    await user.type(month, '10')
    expect(year).toHaveFocus()
  })

  it('accepts 29 February on a leap year', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderDateInput({ onChange })

    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '29')
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '02')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2024')

    expect(onChange).toHaveBeenLastCalledWith('2024-02-29')
  })

  it('rejects 29 February on a non-leap year and shows the invalid-date hint instead of emitting it', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderDateInput({ onChange })

    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '29')
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '02')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2023')

    expect(onChange).not.toHaveBeenCalledWith('2023-02-29')
    expect(await screen.findByText('تاريخ غير صالح.')).toBeInTheDocument()
  })

  it('rejects an out-of-range day for the given month (31 April) without emitting it', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderDateInput({ onChange })

    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '31')
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '04')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2026')

    expect(onChange).not.toHaveBeenCalledWith('2026-04-31')
    expect(await screen.findByText('تاريخ غير صالح.')).toBeInTheDocument()
  })

  it('accepts Arabic-Indic digits, converting them to the Western-digit ISO value', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderDateInput({ onChange })

    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '٠٥')
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '١٠')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '٢٠٢٦')

    expect(onChange).toHaveBeenLastCalledWith('2026-10-05')
  })

  it('keeps the hidden native date input as the one accessible-by-label element, LTR, and in sync via fireEvent.change (existing test interaction contract)', () => {
    renderDateInput({ onChange: vi.fn(), initial: '2026-10-05' })

    const hidden = screen.getByLabelText(LABEL)
    expect(hidden).toHaveValue('2026-10-05')
    expect(hidden).toHaveAttribute('dir', 'ltr')

    fireEvent.change(hidden, { target: { value: '2026-12-31' } })
    expect(hidden).toHaveValue('2026-12-31')
    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toHaveValue('31')
    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveValue('12')
    expect(screen.getByLabelText(`${LABEL} — السنة`)).toHaveValue('2026')
  })

  it('calls showPicker on the hidden native input when the calendar button is clicked', async () => {
    const user = userEvent.setup()
    renderDateInput({ onChange: vi.fn() })
    const hidden = screen.getByLabelText(LABEL) as HTMLInputElement & { showPicker: () => void }
    const showPicker = vi.fn()
    hidden.showPicker = showPicker

    await user.click(screen.getByRole('button', { name: 'فتح التقويم' }))

    expect(showPicker).toHaveBeenCalledTimes(1)
  })

  it('falls back to focusing the day segment when showPicker is unavailable (this jsdom version has no showPicker at all)', async () => {
    const user = userEvent.setup()
    renderDateInput({ onChange: vi.fn() })

    await user.click(screen.getByRole('button', { name: 'فتح التقويم' }))

    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toHaveFocus()
  })

  it('falls back to focusing the day segment when showPicker is present but throws (some browsers refuse an untrusted call)', async () => {
    const user = userEvent.setup()
    renderDateInput({ onChange: vi.fn() })
    const hidden = screen.getByLabelText(LABEL) as HTMLInputElement & { showPicker: () => void }
    hidden.showPicker = () => {
      throw new DOMException('Not implemented', 'NotSupportedError')
    }

    await user.click(screen.getByRole('button', { name: 'فتح التقويم' }))

    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toHaveFocus()
  })

  it('reports via onValidityChange when typed segments are non-empty but cannot form a valid date, and clears it once valid', async () => {
    const onValidityChange = vi.fn()
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(
      <I18nProvider initialLocale="ar">
        <DateInput label={LABEL} value="" onChange={onChange} error={null} onValidityChange={onValidityChange} />
      </I18nProvider>,
    )

    // 31 April does not exist: a complete set of segments that can never form a valid date.
    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '31')
    await user.type(screen.getByLabelText(`${LABEL} — الشهر`), '04')
    await user.type(screen.getByLabelText(`${LABEL} — السنة`), '2026')

    expect(onValidityChange).toHaveBeenLastCalledWith(true)
    expect(onChange).not.toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/))

    await user.clear(screen.getByLabelText(`${LABEL} — اليوم`))
    await user.type(screen.getByLabelText(`${LABEL} — اليوم`), '30')

    expect(onValidityChange).toHaveBeenLastCalledWith(false)
    expect(onChange).toHaveBeenLastCalledWith('2026-04-30')
  })

  it('settles back to "no problem" once a field is cleared back to fully empty — even though it passes through a transient incomplete state while the user is still typing', async () => {
    const onValidityChange = vi.fn()
    const onChange = vi.fn()
    const user = userEvent.setup()
    render(
      <I18nProvider initialLocale="ar">
        <DateInput label={LABEL} value="" onChange={onChange} error={null} onValidityChange={onValidityChange} />
      </I18nProvider>,
    )
    const day = screen.getByLabelText(`${LABEL} — اليوم`)

    await user.type(day, '05')
    await user.clear(day)

    // A genuinely empty field (nothing typed in any segment) must never be treated as a dropped invalid entry.
    expect(onValidityChange).toHaveBeenLastCalledWith(false)
    expect(onChange).not.toHaveBeenCalledWith(expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/))
  })

  it('shows the Latin lowercase dd/mm/yyyy placeholders in Arabic — labels and error text stay translated, only the placeholder hint is Latin', () => {
    render(
      <I18nProvider initialLocale="ar">
        <DateInput label={LABEL} value="" onChange={vi.fn()} error={null} />
      </I18nProvider>,
    )

    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toHaveAttribute('placeholder', 'dd')
    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveAttribute('placeholder', 'mm')
    expect(screen.getByLabelText(`${LABEL} — السنة`)).toHaveAttribute('placeholder', 'yyyy')
    // Labels themselves remain translated — this is the Arabic day/month/year wording, not Latin.
    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toBeInTheDocument()
  })

  it('shows the same Latin lowercase dd/mm/yyyy placeholders in English, with English-translated labels', () => {
    render(
      <I18nProvider initialLocale="en">
        <DateInput label={LABEL} value="" onChange={vi.fn()} error={null} />
      </I18nProvider>,
    )

    expect(screen.getByLabelText(`${LABEL} — Day`)).toHaveAttribute('placeholder', 'dd')
    expect(screen.getByLabelText(`${LABEL} — Month`)).toHaveAttribute('placeholder', 'mm')
    expect(screen.getByLabelText(`${LABEL} — Year`)).toHaveAttribute('placeholder', 'yyyy')
  })

  it('resyncs the visible segments when the parent resets the value externally', () => {
    const { rerender } = render(
      <I18nProvider initialLocale="ar">
        <DateInput label={LABEL} value="2026-10-05" onChange={vi.fn()} error={null} />
      </I18nProvider>,
    )
    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toHaveValue('05')

    rerender(
      <I18nProvider initialLocale="ar">
        <DateInput label={LABEL} value="" onChange={vi.fn()} error={null} />
      </I18nProvider>,
    )
    expect(screen.getByLabelText(`${LABEL} — اليوم`)).toHaveValue('')
    expect(screen.getByLabelText(`${LABEL} — الشهر`)).toHaveValue('')
    expect(screen.getByLabelText(`${LABEL} — السنة`)).toHaveValue('')
  })
})
