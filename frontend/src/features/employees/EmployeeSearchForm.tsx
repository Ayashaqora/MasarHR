import { Search } from 'lucide-react'
import { useState, type FormEvent } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { useI18n } from '../../i18n/context'
import { FormField } from '../../shared/ui/FormField'

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
    <Card>
      <CardContent>
        <form className="flex flex-col gap-4 sm:flex-row sm:items-start" noValidate onSubmit={handleSubmit}>
          <div className="min-w-0 flex-1">
            <FormField label={messages.employees.searchLabel} required helper={messages.employees.searchHelper} error={validationError}>
              {(field) => (
                <div className="relative">
                  <Search aria-hidden="true" className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    {...field}
                    name="national_id"
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    className="h-11 ps-9 text-base tabular-nums"
                    value={value}
                    disabled={submitting}
                    onChange={(event) => setValue(event.target.value)}
                  />
                </div>
              )}
            </FormField>
          </div>
          <Button type="submit" size="lg" className="sm:mt-[1.625rem]" disabled={submitting}>
            {submitting ? messages.employees.searching : messages.employees.searchAction}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
