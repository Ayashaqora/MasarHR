import { useState } from 'react'
import { Plus } from 'lucide-react'
import { useAuth } from '../../auth/context'
import { useI18n } from '../../../i18n/context'
import type { ApiResourceState } from '../../../shared/hooks/useApiResource'
import { useDateFieldValidity } from '../../../shared/hooks/useDateFieldValidity'
import { useOperation } from '../../../shared/hooks/useOperation'
import { HR_PERMISSIONS } from '../../../shared/security/permissions'
import { DateInput } from '../../../shared/ui/DateInput'
import { DateText } from '../../../shared/ui/DateText'
import { DefinitionItem, DefinitionList } from '../../../shared/ui/DefinitionList'
import { FormField } from '../../../shared/ui/FormField'
import { NativeSelect } from '../../../shared/ui/NativeSelect'
import { OperationAction, OperationBar, OperationForm } from '../../../shared/ui/Operation'
import type { EmploymentStatusDetail, ReturnIntentionValue } from '../api'
import {
  OPTIONAL_END_STATUS_CODES,
  REQUIRED_END_STATUS_CODES,
  RETIRED_STATUS_CODES,
  TERMINAL_STATUS_CODES,
  TRAVEL_PAY_STATUS_CODE,
} from '../statusCodes'
import { recordReturnIntention, recordStatusPeriod } from './api'
import { fieldErrorFor, type OperationContext } from './shared'

interface FormProps extends OperationContext {
  onChanged: () => void
  /** Called in addition when a relationship-ending status was recorded, so the relationship itself is re-read. */
  onRelationshipEnded?: () => void
  done: (message: string) => void
  cancel: () => void
}

function RecordStatusForm({
  personId,
  relationshipId,
  employeeLabel,
  statusCatalog,
  onChanged,
  onRelationshipEnded,
  done,
  cancel,
}: FormProps & { statusCatalog: ApiResourceState<EmploymentStatusDetail[]> }) {
  const { messages, locale } = useI18n()
  const o = messages.operations
  const [code, setCode] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [pay, setPay] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const operation = useOperation(recordStatusPeriod)
  // Whether something is currently typed in the from/to date fields that their own `value` can't represent
  // (incomplete or invalid) — must block review/submit even when the field (here, `to`) is optional, so an
  // invalid entry is never silently dropped from the payload just because it collapses to an empty `value`.
  const [fromInvalid, onFromValidityChange] = useDateFieldValidity()
  const [toInvalid, onToValidityChange] = useDateFieldValidity()

  const catalog = statusCatalog.status === 'success' ? statusCatalog.data : []
  // The two legacy return-intention codes are not employment statuses (S34): never offered here.
  const options = catalog
    .filter((detail) => detail.is_active && !RETIRED_STATUS_CODES.has(detail.code))
    .sort((a, b) => a.display_order - b.display_order)

  const showEnd = OPTIONAL_END_STATUS_CODES.has(code) || REQUIRED_END_STATUS_CODES.has(code)
  const endRequired = REQUIRED_END_STATUS_CODES.has(code)
  const showPay = code === TRAVEL_PAY_STATUS_CODE
  const terminal = TERMINAL_STATUS_CODES.has(code)
  const selected = options.find((detail) => detail.code === code)
  const nameOf = (detail: EmploymentStatusDetail) => (locale === 'ar' ? detail.name_ar : detail.name_en)

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!code) next.status_detail_code = o.required
    if (!from) next.effective_from = o.required
    if (endRequired && !to) next.effective_to = o.required
    setErrors(next)
    return Object.keys(next).length === 0 && !fromInvalid && !(showEnd && toInvalid)
  }

  async function submit() {
    const ok = await operation.run(personId, relationshipId, {
      status_detail_code: code,
      effective_from: from,
      ...(showEnd && to ? { effective_to: to } : {}),
      ...(showPay && pay ? { travel_pay_status: pay as 'PAID' | 'UNPAID' } : {}),
    })
    if (ok) {
      onChanged()
      if (terminal) {
        onRelationshipEnded?.()
      }
      done(o.statusSuccess)
    }
  }

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={o.recordStatusSubmit}
      destructive={terminal}
      review={
        terminal ? (
          <div className="space-y-3">
            <p className="text-sm font-medium text-destructive">{o.terminalStatusWarning}</p>
            <DefinitionList>
              <DefinitionItem label={o.reviewEmployee}>{employeeLabel}</DefinitionItem>
              <DefinitionItem label={o.statusLabel}>{selected ? nameOf(selected) : code}</DefinitionItem>
              <DefinitionItem label={o.effectiveFrom}>
                <DateText value={from} />
              </DefinitionItem>
            </DefinitionList>
          </div>
        ) : undefined
      }
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
    >
      <p className="text-sm text-muted-foreground">{o.recordStatusNote}</p>
      <FormField label={o.statusLabel} required error={fieldErrorFor(errors, operation.failure, 'status_detail_code')}>
        {(props) => (
          <NativeSelect
            {...props}
            value={code}
            disabled={statusCatalog.status !== 'success'}
            onChange={(event) => setCode(event.target.value)}
          >
            <option value="">{statusCatalog.status === 'loading' ? o.optionsLoading : o.selectPlaceholder}</option>
            {options.map((detail) => (
              <option key={detail.id} value={detail.code}>
                {nameOf(detail)}
                {TERMINAL_STATUS_CODES.has(detail.code) ? ` — ${o.terminalSuffix}` : ''}
              </option>
            ))}
          </NativeSelect>
        )}
      </FormField>
      {terminal ? <p className="text-sm font-medium text-destructive">{o.terminalStatusWarning}</p> : null}
      <DateInput
        label={o.effectiveFrom}
        value={from}
        onChange={setFrom}
        onValidityChange={onFromValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_from')}
      />
      {showEnd ? (
        <DateInput
          label={o.effectiveTo}
          value={to}
          onChange={setTo}
          onValidityChange={onToValidityChange}
          required={endRequired}
          helper={endRequired ? o.effectiveToRequiredHelper : o.effectiveToOptionalHelper}
          error={fieldErrorFor(errors, operation.failure, 'effective_to')}
        />
      ) : null}
      {showPay ? (
        <FormField label={o.travelPayLabel} error={fieldErrorFor(errors, operation.failure, 'travel_pay_status')}>
          {(props) => (
            <NativeSelect {...props} value={pay} onChange={(event) => setPay(event.target.value)}>
              <option value="">{o.travelPayNone}</option>
              <option value="PAID">{o.travelPayPaid}</option>
              <option value="UNPAID">{o.travelPayUnpaid}</option>
            </NativeSelect>
          )}
        </FormField>
      ) : null}
    </OperationForm>
  )
}

function RecordReturnIntentionForm({ personId, relationshipId, onChanged, done, cancel }: FormProps) {
  const { messages } = useI18n()
  const o = messages.operations
  const [intention, setIntention] = useState<ReturnIntentionValue | ''>('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const operation = useOperation(recordReturnIntention)
  const [fromInvalid, onFromValidityChange] = useDateFieldValidity()
  const [toInvalid, onToValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!intention) next.intention = o.required
    if (!from) next.effective_from = o.required
    setErrors(next)
    return Object.keys(next).length === 0 && !fromInvalid && !toInvalid
  }

  async function submit() {
    if (!intention) return
    const ok = await operation.run(personId, relationshipId, {
      intention,
      effective_from: from,
      ...(to ? { effective_to: to } : {}),
    })
    if (ok) {
      onChanged()
      done(o.intentionSuccess)
    }
  }

  const intentionError = fieldErrorFor(errors, operation.failure, 'intention')

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={o.recordIntentionSubmit}
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
    >
      <p className="text-sm text-muted-foreground">{o.recordIntentionNote}</p>
      <fieldset className="space-y-2" aria-invalid={intentionError ? true : undefined}>
        <legend className="text-sm leading-none font-medium">
          {o.intentionLabel} <span aria-hidden="true" className="text-destructive">*</span>
        </legend>
        {(['WANTS_TO_RETURN', 'DOES_NOT_WANT_TO_RETURN'] as const).map((value) => (
          <label key={value} className="flex min-h-9 items-center gap-2 rounded-md border border-input px-3 py-1 text-sm">
            <input
              type="radio"
              name="return-intention"
              className="size-4 accent-primary"
              checked={intention === value}
              onChange={() => setIntention(value)}
            />
            {value === 'WANTS_TO_RETURN' ? messages.employee360.wantsToReturn : messages.employee360.doesNotWantToReturn}
          </label>
        ))}
        {intentionError ? (
          <p role="alert" className="text-sm font-medium text-destructive">
            {intentionError}
          </p>
        ) : null}
      </fieldset>
      <DateInput
        label={o.effectiveFrom}
        value={from}
        onChange={setFrom}
        onValidityChange={onFromValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_from')}
      />
      <DateInput
        label={o.effectiveTo}
        value={to}
        onChange={setTo}
        onValidityChange={onToValidityChange}
        required={false}
        helper={o.effectiveToOptionalHelper}
        error={fieldErrorFor(errors, operation.failure, 'effective_to')}
      />
    </OperationForm>
  )
}

/** Record-status action (hr.employment_status_periods.record). There is NO end-status command: a later record supersedes per the backend rules. */
export function StatusOperations({
  context,
  statusCatalog,
  ended,
  onChanged,
  onRelationshipEnded,
}: {
  context: OperationContext
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
  ended: boolean
  onChanged: () => void
  onRelationshipEnded: () => void
}) {
  const { hasPermission } = useAuth()
  const { messages } = useI18n()
  const o = messages.operations

  if (!hasPermission(HR_PERMISSIONS.employmentStatusPeriodsRecord)) {
    return null
  }

  return (
    <OperationBar headingId="status-operations-heading" title={o.statusBarTitle} description={o.statusBarDescription} note={ended ? o.relationshipEndedNote : undefined}>
      {ended ? null : (
        <OperationAction label={o.recordStatus} icon={<Plus aria-hidden="true" />} title={o.recordStatusTitle} description={o.recordStatusDescription}>
          {({ done, cancel }) => (
            <RecordStatusForm {...context} statusCatalog={statusCatalog} onChanged={onChanged} onRelationshipEnded={onRelationshipEnded} done={done} cancel={cancel} />
          )}
        </OperationAction>
      )}
    </OperationBar>
  )
}

/** Return Intention is independent of employment status: its own permission, endpoint, bar and form. */
export function ReturnIntentionOperations({
  context,
  ended,
  onChanged,
}: {
  context: OperationContext
  ended: boolean
  onChanged: () => void
}) {
  const { hasPermission } = useAuth()
  const { messages } = useI18n()
  const o = messages.operations

  if (!hasPermission(HR_PERMISSIONS.returnIntentionPeriodsRecord)) {
    return null
  }

  return (
    <OperationBar headingId="intention-operations-heading" title={o.intentionBarTitle} description={o.intentionBarDescription} note={ended ? o.relationshipEndedNote : undefined}>
      {ended ? null : (
        <OperationAction label={o.recordIntention} icon={<Plus aria-hidden="true" />} title={o.recordIntentionTitle} description={o.recordIntentionDescription}>
          {({ done, cancel }) => (
            <RecordReturnIntentionForm {...context} onChanged={onChanged} done={done} cancel={cancel} />
          )}
        </OperationAction>
      )}
    </OperationBar>
  )
}
