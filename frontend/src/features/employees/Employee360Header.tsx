import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { DefinitionItem, DefinitionList } from '../../shared/ui/DefinitionList'
import { Ltr } from '../../shared/ui/Ltr'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatusBadge } from '../../shared/ui/StatusBadge'
import type {
  ActualWorkplace,
  EffectiveEmploymentStatus,
  EffectiveReturnIntention,
  EmploymentRelationship,
  EmploymentStatusDetail,
  Person,
} from './api'
import { EffectiveStatusText } from './EffectiveStatusText'
import { ReturnIntentionText } from './Employee360ReturnIntention'

/**
 * Compact identity/employment header (spec §S18 §11), always visible above the tabs. Only fields
 * the backend actually supports are shown — no profile photo, email, phone, address, manager or
 * salary (none exist in the domain; spec §11 forbids fabricating them). S24 adds the Person's
 * full_name_ar (docs/person-profile-foundation-specification.md §S24.17); a legacy Person with no
 * recorded name shows "not recorded", never a placeholder. The other S24 profile fields are not
 * displayed here yet (deferred — §S24.17).
 *
 * S33: the current status is the backend's effective status (S32 semantics), never the persisted
 * open period (which is absent during a bounded status and after its expiry).
 */
export function Employee360Header({
  person,
  relationship,
  effectiveStatus,
  effectiveIntention,
  actualWorkplace,
  statusCatalog,
  unitNames,
}: {
  person: Person
  relationship: EmploymentRelationship
  effectiveStatus: ApiResourceState<EffectiveEmploymentStatus>
  effectiveIntention: ApiResourceState<EffectiveReturnIntention>
  actualWorkplace: ApiResourceState<ActualWorkplace>
  statusCatalog: ApiResourceState<EmploymentStatusDetail[]>
  unitNames: { status: 'loading' | 'ready'; names: Record<string, string> }
}) {
  const { messages } = useI18n()

  const actualWorkplaceName =
    actualWorkplace.status === 'success' && actualWorkplace.data.organizational_unit_id
      ? (unitNames.names[actualWorkplace.data.organizational_unit_id] ?? null)
      : null
  const name = person.full_name_ar ?? messages.employee360.notRecorded
  const ended = relationship.end_knowledge_state === 'KNOWN'

  return (
    <SectionCard
      headingId="employee-360-header-heading"
      title={messages.employee360.headerTitle}
      action={
        ended ? (
          <StatusBadge status="inactive">{messages.employees.relationshipEnded}</StatusBadge>
        ) : (
          <StatusBadge status="active">{messages.employees.relationshipActive}</StatusBadge>
        )
      }
    >
      <div className="flex items-start gap-4">
        <span
          aria-hidden="true"
          className="hidden size-14 shrink-0 place-items-center rounded-full bg-primary text-xl font-semibold text-primary-foreground sm:grid"
        >
          {name.trim().charAt(0)}
        </span>
      <DefinitionList className="min-w-0 flex-1 lg:grid-cols-3">
        <DefinitionItem label={messages.employee360.fullNameAr}>{name}</DefinitionItem>
        <DefinitionItem label={messages.employee360.nationalId}>
          <Ltr>{person.national_id}</Ltr>
        </DefinitionItem>
        <DefinitionItem label={messages.employees.employeeNumber}>
          {relationship.employee_number ? <Ltr>{relationship.employee_number}</Ltr> : messages.employees.noEmployeeNumber}
        </DefinitionItem>
        <DefinitionItem label={messages.employees.employmentScheme}>
          {relationship.employee_number_scheme === 'PERMANENT' ? messages.employees.schemePermanent : messages.employees.schemeContract}
        </DefinitionItem>
        <DefinitionItem label={messages.employees.relationshipState}>
          {ended ? messages.employees.relationshipEnded : messages.employees.relationshipActive}
        </DefinitionItem>
        <DefinitionItem label={messages.employee360.currentStatus}>
          {effectiveStatus.status === 'loading' || statusCatalog.status === 'loading' ? (
            messages.employee360.loading
          ) : effectiveStatus.status === 'error' ? (
            messages.employee360.loadFailed
          ) : (
            <EffectiveStatusText effectiveStatus={effectiveStatus} statusCatalog={statusCatalog} />
          )}
        </DefinitionItem>
        <DefinitionItem label={messages.employee360.returnIntention}>
          {effectiveIntention.status === 'loading' ? (
            messages.employee360.loading
          ) : effectiveIntention.status === 'error' ? (
            messages.employee360.loadFailed
          ) : (
            <ReturnIntentionText effectiveIntention={effectiveIntention} />
          )}
        </DefinitionItem>
        <DefinitionItem label={messages.employee360.actualWorkplace}>
          {actualWorkplace.status === 'loading' || unitNames.status === 'loading'
            ? messages.employee360.loading
            : (actualWorkplaceName ?? messages.employee360.noActualWorkplace)}
        </DefinitionItem>
      </DefinitionList>
      </div>
    </SectionCard>
  )
}
