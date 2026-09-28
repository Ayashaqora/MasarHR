import { useId, useState, type FormEvent } from 'react'
import { useI18n } from '../../i18n/context'

/**
 * The Employees screen's only discovery mechanism (spec §S18 data contract §10): an exact
 * national-ID lookup, mirroring the backend's own GET /hr/persons/lookup contract. There is no
 * partial-name search and no browsable "all employees" listing — no such capability exists on
 * the backend (Person carries no name field, and no list-all-persons endpoint exists), so this
 * form is not a first step toward a broader search UI, it is the whole of it.
 */
export function EmployeeSearchForm({
  onSearch,
  submitting,
}: {
  onSearch: (nationalId: string) => void
  submitting: boolean
}) {
  const { messages } = useI18n()
  const fieldId = useId()
  const [value, setValue] = useState('')
  const [validationError, setValidationError] = useState<string | null>(null)

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const trimmed = value.trim()
    if (trimmed === '') {
      setValidationError(messages.employees.searchRequired)
      return
    }
    setValidationError(null)
    onSearch(trimmed)
  }

  return (
    <form
      className="card employee-search-form"
      onSubmit={(event) => {
        handleSubmit(event)
      }}
    >
      <div className="form-field">
        <label htmlFor={fieldId}>{messages.employees.searchLabel}</label>
        <input
          id={fieldId}
          name="national_id"
          type="text"
          inputMode="numeric"
          autoComplete="off"
          value={value}
          disabled={submitting}
          onChange={(event) => setValue(event.target.value)}
        />
      </div>

      {validationError ? (
        <p role="alert" className="field-error">
          {validationError}
        </p>
      ) : null}

      <button type="submit" className="button" disabled={submitting}>
        {submitting ? messages.employees.searching : messages.employees.searchAction}
      </button>
    </form>
  )
}
