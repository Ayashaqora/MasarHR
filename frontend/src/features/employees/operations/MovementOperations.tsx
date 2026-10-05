import { type ReactNode, useState } from 'react'
import { ArrowRightLeft, CalendarOff, Plane, PlaneLanding, Plus, UserCog } from 'lucide-react'
import { useAuth } from '../../auth/context'
import { useI18n } from '../../../i18n/context'
import { useDateFieldValidity } from '../../../shared/hooks/useDateFieldValidity'
import { useOperation } from '../../../shared/hooks/useOperation'
import { HR_PERMISSIONS } from '../../../shared/security/permissions'
import { DateInput } from '../../../shared/ui/DateInput'
import { DateText } from '../../../shared/ui/DateText'
import { DefinitionItem, DefinitionList } from '../../../shared/ui/DefinitionList'
import { OperationAction, OperationBar, OperationForm } from '../../../shared/ui/Operation'
import { weekdaysLabel } from '../weekdays'
import {
  endFullSecondment,
  endWorkplaceAssignment,
  recordPartialSecondment,
  startFullSecondment,
  startWorkplaceAssignment,
  transferEmployee,
  type ReferenceOption,
} from './api'
import { useDecisionTypeOptions, unitOptionLabel, useLocalizedOptionLabel, useUnitOptions } from './optionHooks'
import { OptionSelect } from './options'
import { fieldErrorFor, type OperationContext } from './shared'
import { WeekdayPicker } from './WeekdayPicker'

interface FormProps extends OperationContext {
  onChanged: () => void
  done: (message: string) => void
  cancel: () => void
}

function Review({ employeeLabel, rows }: { employeeLabel: string; rows: ReadonlyArray<{ label: string; value: ReactNode }> }) {
  const { messages } = useI18n()
  return (
    <DefinitionList>
      <DefinitionItem label={messages.operations.reviewEmployee}>{employeeLabel}</DefinitionItem>
      {rows.map((row) => (
        <DefinitionItem key={row.label} label={row.label}>
          {row.value}
        </DefinitionItem>
      ))}
    </DefinitionList>
  )
}

function unitName(units: ReturnType<typeof useUnitOptions>, id: string): string {
  return units.status === 'success' ? (units.data.find((unit) => unit.id === id)?.name ?? id) : id
}

function labelOf(options: ReferenceOption[] | undefined, id: string, label: (option: ReferenceOption) => string): string {
  const found = options?.find((option) => option.id === id)
  return found ? label(found) : id
}

function TransferForm({ personId, relationshipId, employeeLabel, onChanged, done, cancel }: FormProps) {
  const { messages } = useI18n()
  const o = messages.operations
  const units = useUnitOptions()
  const decisions = useDecisionTypeOptions('TRANSFER')
  const decisionLabel = useLocalizedOptionLabel()
  const [unit, setUnit] = useState('')
  const [from, setFrom] = useState('')
  const [decision, setDecision] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const operation = useOperation(transferEmployee)
  const [fromInvalid, onFromValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!unit) next.organizational_unit_id = o.required
    if (!from) next.effective_from = o.required
    if (!decision) next.decision_type_id = o.required
    setErrors(next)
    return Object.keys(next).length === 0 && !fromInvalid
  }

  async function submit() {
    const ok = await operation.run(personId, relationshipId, { organizational_unit_id: unit, effective_from: from, decision_type_id: decision })
    if (ok) {
      onChanged()
      done(o.transferSuccess)
    }
  }

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={o.transferSubmit}
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
      review={
        <Review
          employeeLabel={employeeLabel}
          rows={[
            { label: o.destinationUnit, value: unitName(units, unit) },
            { label: o.effectiveFrom, value: <DateText value={from} /> },
            { label: o.decisionType, value: decisions.status === 'success' ? labelOf(decisions.data, decision, decisionLabel) : decision },
          ]}
        />
      }
    >
      <p className="text-sm text-muted-foreground">{o.transferNote}</p>
      <OptionSelect label={o.destinationUnit} value={unit} onChange={setUnit} state={units} optionLabel={unitOptionLabel} error={fieldErrorFor(errors, operation.failure, 'organizational_unit_id')} />
      <DateInput
        label={o.effectiveFrom}
        value={from}
        onChange={setFrom}
        onValidityChange={onFromValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_from')}
      />
      <OptionSelect label={o.decisionType} value={decision} onChange={setDecision} state={decisions} optionLabel={decisionLabel} error={fieldErrorFor(errors, operation.failure, 'decision_type_id')} />
    </OperationForm>
  )
}

function StartUnitMovementForm({
  kind,
  personId,
  relationshipId,
  employeeLabel,
  onChanged,
  done,
  cancel,
}: FormProps & { kind: 'secondment' | 'assignment' }) {
  const { messages } = useI18n()
  const o = messages.operations
  const units = useUnitOptions()
  const decisions = useDecisionTypeOptions(kind === 'assignment' ? 'ASSIGNMENT' : null)
  const decisionLabel = useLocalizedOptionLabel()
  const [unit, setUnit] = useState('')
  const [from, setFrom] = useState('')
  const [decision, setDecision] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const startSecondment = useOperation(startFullSecondment)
  const startAssignment = useOperation(startWorkplaceAssignment)
  const operation = kind === 'secondment' ? startSecondment : startAssignment
  const [fromInvalid, onFromValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!unit) next.organizational_unit_id = o.required
    if (!from) next.effective_from = o.required
    if (kind === 'assignment' && !decision) next.decision_type_id = o.required
    setErrors(next)
    return Object.keys(next).length === 0 && !fromInvalid
  }

  async function submit() {
    const ok =
      kind === 'secondment'
        ? await startSecondment.run(personId, relationshipId, { organizational_unit_id: unit, effective_from: from })
        : await startAssignment.run(personId, relationshipId, { organizational_unit_id: unit, effective_from: from, decision_type_id: decision })
    if (ok) {
      onChanged()
      done(kind === 'secondment' ? o.startSecondmentSuccess : o.startAssignmentSuccess)
    }
  }

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={kind === 'secondment' ? o.startSecondmentSubmit : o.startAssignmentSubmit}
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
      review={
        <Review
          employeeLabel={employeeLabel}
          rows={[
            { label: o.destinationUnit, value: unitName(units, unit) },
            { label: o.effectiveFrom, value: <DateText value={from} /> },
            ...(kind === 'assignment'
              ? [{ label: o.decisionType, value: decisions.status === 'success' ? labelOf(decisions.data, decision, decisionLabel) : decision }]
              : []),
          ]}
        />
      }
    >
      <p className="text-sm text-muted-foreground">{kind === 'secondment' ? o.secondmentNote : o.assignmentNote}</p>
      <OptionSelect label={o.destinationUnit} value={unit} onChange={setUnit} state={units} optionLabel={unitOptionLabel} error={fieldErrorFor(errors, operation.failure, 'organizational_unit_id')} />
      <DateInput
        label={o.effectiveFrom}
        value={from}
        onChange={setFrom}
        onValidityChange={onFromValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_from')}
      />
      {kind === 'assignment' ? (
        <OptionSelect label={o.decisionType} value={decision} onChange={setDecision} state={decisions} optionLabel={decisionLabel} error={fieldErrorFor(errors, operation.failure, 'decision_type_id')} />
      ) : null}
    </OperationForm>
  )
}

function EndUnitMovementForm({
  kind,
  personId,
  relationshipId,
  employeeLabel,
  onChanged,
  done,
  cancel,
}: FormProps & { kind: 'secondment' | 'assignment' }) {
  const { messages } = useI18n()
  const o = messages.operations
  const [to, setTo] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const endSecondment = useOperation(endFullSecondment)
  const endAssignment = useOperation(endWorkplaceAssignment)
  const operation = kind === 'secondment' ? endSecondment : endAssignment
  const [toInvalid, onToValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!to) next.effective_to = o.required
    setErrors(next)
    return Object.keys(next).length === 0 && !toInvalid
  }

  async function submit() {
    const ok =
      kind === 'secondment'
        ? await endSecondment.run(personId, relationshipId, { effective_to: to })
        : await endAssignment.run(personId, relationshipId, { effective_to: to })
    if (ok) {
      onChanged()
      done(kind === 'secondment' ? o.endSecondmentSuccess : o.endAssignmentSuccess)
    }
  }

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={kind === 'secondment' ? o.endSecondmentSubmit : o.endAssignmentSubmit}
      destructive
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
      review={<Review employeeLabel={employeeLabel} rows={[{ label: o.effectiveTo, value: <DateText value={to} /> }]} />}
    >
      <p className="text-sm text-muted-foreground">{o.endMovementNote}</p>
      <DateInput
        label={o.effectiveTo}
        value={to}
        onChange={setTo}
        onValidityChange={onToValidityChange}
        error={fieldErrorFor(errors, operation.failure, 'effective_to')}
      />
    </OperationForm>
  )
}

function PartialSecondmentForm({ personId, relationshipId, employeeLabel, onChanged, done, cancel }: FormProps) {
  const { messages } = useI18n()
  const o = messages.operations
  const units = useUnitOptions()
  const [unit, setUnit] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [weekdays, setWeekdays] = useState<string[]>([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const operation = useOperation(recordPartialSecondment)
  const [fromInvalid, onFromValidityChange] = useDateFieldValidity()
  // `to` is optional here: an uncommitted (incomplete/invalid) typed value must still block submit, otherwise
  // it collapses to '' exactly like "left blank on purpose" and the invalid entry is silently omitted from
  // the payload below.
  const [toInvalid, onToValidityChange] = useDateFieldValidity()

  function validate(): boolean {
    const next: Record<string, string> = {}
    if (!unit) next.organizational_unit_id = o.required
    if (!from) next.effective_from = o.required
    if (weekdays.length === 0) next.weekdays = o.weekdaysRequired
    setErrors(next)
    return Object.keys(next).length === 0 && !fromInvalid && !toInvalid
  }

  async function submit() {
    const ok = await operation.run(personId, relationshipId, {
      organizational_unit_id: unit,
      effective_from: from,
      ...(to ? { effective_to: to } : {}),
      weekdays,
    })
    if (ok) {
      onChanged()
      done(o.partialSuccess)
    }
  }

  return (
    <OperationForm
      pending={operation.pending}
      failure={operation.failure}
      submitLabel={o.partialSubmit}
      validate={validate}
      onSubmit={submit}
      onCancel={cancel}
      review={
        <Review
          employeeLabel={employeeLabel}
          rows={[
            { label: o.destinationUnit, value: unitName(units, unit) },
            { label: o.effectiveFrom, value: <DateText value={from} /> },
            { label: o.effectiveTo, value: <DateText value={to} fallback={messages.employee360.openEnded} /> },
            { label: o.weekdaysLabel, value: weekdaysLabel(weekdays, messages) },
          ]}
        />
      }
    >
      <p className="text-sm text-muted-foreground">{o.partialNote}</p>
      <OptionSelect label={o.destinationUnit} value={unit} onChange={setUnit} state={units} optionLabel={unitOptionLabel} error={fieldErrorFor(errors, operation.failure, 'organizational_unit_id')} />
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
      <WeekdayPicker legend={o.weekdaysLabel} helper={o.partialWeekdaysHelper} value={weekdays} onChange={setWeekdays} error={fieldErrorFor(errors, operation.failure, 'weekdays')} />
    </OperationForm>
  )
}

/**
 * Workplace / movement operations. Transfer, full secondment, workplace assignment and partial secondment are
 * movement/workplace concepts — never employment-status values — and the backend alone closes/truncates any
 * conflicting movement. The End buttons are offered only while the current read shows an open period.
 */
export function MovementOperations({
  context,
  ended,
  hasOpenSecondment,
  hasOpenAssignment,
  onChanged,
}: {
  context: OperationContext
  ended: boolean
  hasOpenSecondment: boolean
  hasOpenAssignment: boolean
  onChanged: () => void
}) {
  const { hasPermission } = useAuth()
  const { messages } = useI18n()
  const o = messages.operations
  const can = HR_PERMISSIONS

  const allowed = {
    transfer: hasPermission(can.employmentRelationshipsTransfer),
    startSecondment: hasPermission(can.fullSecondmentPeriodsStart),
    endSecondment: hasPermission(can.fullSecondmentPeriodsEnd),
    startAssignment: hasPermission(can.workplaceAssignmentPeriodsStart),
    endAssignment: hasPermission(can.workplaceAssignmentPeriodsEnd),
    partial: hasPermission(can.partialSecondmentPeriodsRecord),
  }

  if (!Object.values(allowed).some(Boolean)) {
    return null
  }

  const common = { ...context, onChanged }

  return (
    <OperationBar
      headingId="movement-operations-heading"
      title={o.movementBarTitle}
      description={o.movementBarDescription}
      note={ended ? o.relationshipEndedNote : o.movementNote}
    >
      {ended ? null : (
        <>
          {allowed.transfer ? (
            <OperationAction label={o.transfer} icon={<ArrowRightLeft aria-hidden="true" />} title={o.transferTitle} description={o.transferDescription}>
              {({ done, cancel }) => <TransferForm {...common} done={done} cancel={cancel} />}
            </OperationAction>
          ) : null}
          {allowed.startSecondment ? (
            <OperationAction label={o.startSecondment} icon={<Plane aria-hidden="true" />} title={o.startSecondmentTitle} description={o.startSecondmentDescription}>
              {({ done, cancel }) => <StartUnitMovementForm kind="secondment" {...common} done={done} cancel={cancel} />}
            </OperationAction>
          ) : null}
          {allowed.endSecondment ? (
            <OperationAction
              label={o.endSecondment}
              icon={<PlaneLanding aria-hidden="true" />}
              title={o.endSecondmentTitle}
              description={o.endSecondmentDescription}
              disabled={!hasOpenSecondment}
              disabledReason={o.noOpenSecondment}
            >
              {({ done, cancel }) => <EndUnitMovementForm kind="secondment" {...common} done={done} cancel={cancel} />}
            </OperationAction>
          ) : null}
          {allowed.startAssignment ? (
            <OperationAction label={o.startAssignment} icon={<UserCog aria-hidden="true" />} title={o.startAssignmentTitle} description={o.startAssignmentDescription}>
              {({ done, cancel }) => <StartUnitMovementForm kind="assignment" {...common} done={done} cancel={cancel} />}
            </OperationAction>
          ) : null}
          {allowed.endAssignment ? (
            <OperationAction
              label={o.endAssignment}
              icon={<CalendarOff aria-hidden="true" />}
              title={o.endAssignmentTitle}
              description={o.endAssignmentDescription}
              disabled={!hasOpenAssignment}
              disabledReason={o.noOpenAssignment}
            >
              {({ done, cancel }) => <EndUnitMovementForm kind="assignment" {...common} done={done} cancel={cancel} />}
            </OperationAction>
          ) : null}
          {allowed.partial ? (
            <OperationAction label={o.recordPartial} icon={<Plus aria-hidden="true" />} title={o.partialTitle} description={o.partialDescription}>
              {({ done, cancel }) => <PartialSecondmentForm {...common} done={done} cancel={cancel} />}
            </OperationAction>
          ) : null}
        </>
      )}
    </OperationBar>
  )
}
