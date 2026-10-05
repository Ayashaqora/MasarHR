import { useState } from 'react'
import { CalendarClock, UserX } from 'lucide-react'
import { useAuth } from '../../auth/context'
import { useI18n } from '../../../i18n/context'
import { useDateFieldValidity } from '../../../shared/hooks/useDateFieldValidity'
import { useOperation } from '../../../shared/hooks/useOperation'
import { HR_PERMISSIONS } from '../../../shared/security/permissions'
import { DateInput } from '../../../shared/ui/DateInput'
import { DateText } from '../../../shared/ui/DateText'
import { DefinitionItem, DefinitionList } from '../../../shared/ui/DefinitionList'
import { OperationAction, OperationBar, OperationForm } from '../../../shared/ui/Operation'
import type { EmploymentRelationship } from '../api'
import { endEmploymentRelationship, recordWorkSchedule } from './api'
import { fieldErrorFor, type OperationContext } from './shared'
import { WeekdayPicker } from './WeekdayPicker'

interface FormProps extends OperationContext {
  onChanged: () => void
  done: (message: string) => void
  cancel: () => void
}

function WorkScheduleForm({ personId, relationshipId, onChanged, done, cancel }: FormProps) {
  const { messages } = useI18n()
  const o = messages.operations
  const [from, setFrom] = useState('')
  const [weekdays, setWeekdays] = useState<string[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const operation = useOperation(recordWorkSchedule)
  const [fromInvalid, onFromValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!from) next.effective_from = o.required
    if (weekdays.length === 0) next.weekdays = o.weekdaysRequired
    setErrors(next)
    return Object.keys(next).length === 0 && !fromInvalid
  }

  async function submit() {
    const ok = await operation.run(personId, relationshipId, { effective_from: from, weekdays })
    if (ok) {
      onChanged()
      done(o.scheduleSuccess)
    }
  }

  return (
    <OperationForm pending={operation.pending} failure={operation.failure} submitLabel={o.scheduleSubmit} validate={validate} onSubmit={submit} onCancel={cancel}>
      <p className="text-sm text-muted-foreground">{o.scheduleNote}</p>
      <DateInput
        label={o.effectiveFrom}
        value={from}
        onChange={setFrom}
        onValidityChange={onFromValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_from')}
      />
      <WeekdayPicker legend={o.scheduleWeekdaysLabel} value={weekdays} onChange={setWeekdays} error={fieldErrorFor(errors, operation.failure, 'weekdays')} />
    </OperationForm>
  )
}

/** Work Arrangements: record a work schedule (a weekday pattern — not attendance tracking). */
export function WorkScheduleOperations({ context, ended, onChanged }: { context: OperationContext; ended: boolean; onChanged: () => void }) {
  const { hasPermission } = useAuth()
  const { messages } = useI18n()
  const o = messages.operations

  if (!hasPermission(HR_PERMISSIONS.workSchedulePeriodsRecord)) {
    return null
  }

  return (
    <OperationBar headingId="schedule-operations-heading" title={o.scheduleBarTitle} description={o.scheduleBarDescription} note={ended ? o.relationshipEndedNote : undefined}>
      {ended ? null : (
        <OperationAction label={o.recordSchedule} icon={<CalendarClock aria-hidden="true" />} title={o.scheduleTitle} description={o.scheduleDescription}>
          {({ done, cancel }) => <WorkScheduleForm {...context} onChanged={onChanged} done={done} cancel={cancel} />}
        </OperationAction>
      )}
    </OperationBar>
  )
}

function EndRelationshipForm({
  personId,
  relationshipId,
  employeeLabel,
  relationship,
  onChanged,
  onRefresh,
  done,
  cancel,
}: FormProps & { relationship: EmploymentRelationship; onRefresh: () => void }) {
  const { messages } = useI18n()
  const o = messages.operations
  const [to, setTo] = useState('')
  const [terminal, setTerminal] = useState<'' | 'yes' | 'no'>('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const operation = useOperation(endEmploymentRelationship)
  const [toInvalid, onToValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!to) next.effective_to = o.required
    if (!terminal) next.is_terminal = o.required
    setErrors(next)
    return Object.keys(next).length === 0 && !toInvalid
  }

  async function submit() {
    const ok = await operation.run(personId, relationshipId, {
      // The version this screen last read: if anything changed since, the backend answers 409 and we do NOT retry.
      expected_version: relationship.version,
      effective_to: to,
      is_terminal: terminal === 'yes',
    })
    if (ok) {
      onChanged()
      done(o.endRelationshipSuccess)
    }
  }

  const terminalError = fieldErrorFor(errors, operation.failure, 'is_terminal')

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={o.endRelationshipSubmit}
      destructive
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
      onRefresh={onRefresh}
      review={
        <div className="space-y-3">
          <p className="text-sm font-medium text-destructive">{o.endRelationshipWarning}</p>
          <DefinitionList>
            <DefinitionItem label={o.reviewEmployee}>{employeeLabel}</DefinitionItem>
            <DefinitionItem label={o.effectiveTo}>
              <DateText value={to} />
            </DefinitionItem>
            <DefinitionItem label={o.terminalLabel}>{terminal === 'yes' ? o.terminalYes : o.terminalNo}</DefinitionItem>
          </DefinitionList>
        </div>
      }
    >
      <p className="text-sm font-medium text-destructive">{o.endRelationshipWarning}</p>
      <DateInput
        label={o.effectiveTo}
        value={to}
        onChange={setTo}
        onValidityChange={onToValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_to')}
      />
      <fieldset className="space-y-2" aria-invalid={terminalError ? true : undefined}>
        <legend className="text-sm leading-none font-medium">
          {o.terminalLabel} <span aria-hidden="true" className="text-destructive">*</span>
        </legend>
        {(
          [
            ['yes', o.terminalYes, o.terminalYesHelper],
            ['no', o.terminalNo, o.terminalNoHelper],
          ] as const
        ).map(([value, label, helper]) => (
          <label key={value} className="flex items-start gap-2 rounded-md border border-input px-3 py-2 text-sm">
            <input type="radio" name="is-terminal" className="mt-1 size-4 accent-primary" checked={terminal === value} onChange={() => setTerminal(value)} />
            <span>
              <span className="block font-medium">{label}</span>
              <span className="block text-xs text-muted-foreground">{helper}</span>
            </span>
          </label>
        ))}
        {terminalError ? (
          <p role="alert" className="text-sm font-medium text-destructive">
            {terminalError}
          </p>
        ) : null}
      </fieldset>
    </OperationForm>
  )
}

/** High-impact relationship end (hr.employment_relationships.end): explicit review, no silent retry on a version conflict. */
export function EndRelationshipOperations({
  context,
  relationship,
  onChanged,
  onRefresh,
}: {
  context: OperationContext
  relationship: EmploymentRelationship
  onChanged: () => void
  onRefresh: () => void
}) {
  const { hasPermission } = useAuth()
  const { messages } = useI18n()
  const o = messages.operations

  if (!hasPermission(HR_PERMISSIONS.employmentRelationshipsEnd)) {
    return null
  }

  const ended = relationship.end_knowledge_state === 'KNOWN'

  return (
    <OperationBar headingId="end-relationship-heading" title={o.endBarTitle} description={o.endBarDescription} note={ended ? o.relationshipEndedNote : undefined}>
      {ended ? null : (
        <OperationAction variant="destructive" label={o.endRelationship} icon={<UserX aria-hidden="true" />} title={o.endRelationshipTitle} description={o.endRelationshipDescription}>
          {({ done, cancel }) => (
            <EndRelationshipForm {...context} relationship={relationship} onChanged={onChanged} onRefresh={onRefresh} done={done} cancel={cancel} />
          )}
        </OperationAction>
      )}
    </OperationBar>
  )
}
