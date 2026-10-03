import { useI18n } from '../../i18n/context'
import { DateText } from '../../shared/ui/DateText'
import { DefinitionItem, DefinitionList } from '../../shared/ui/DefinitionList'
import { Ltr } from '../../shared/ui/Ltr'
import { SectionCard } from '../../shared/ui/SectionCard'
import type { EmploymentRelationship } from './api'

/** Employment tab (spec §S18 §12.B): the full Employment Relationship record, read-only. */
export function Employee360Employment({ relationship }: { relationship: EmploymentRelationship }) {
  const { messages } = useI18n()

  return (
    <SectionCard level={3} headingId="employment-record-heading" title={messages.employee360.tabEmployment}>
      <DefinitionList>
        <DefinitionItem label={messages.employees.employeeNumber}>
          {relationship.employee_number ? <Ltr>{relationship.employee_number}</Ltr> : messages.employees.noEmployeeNumber}
        </DefinitionItem>
        <DefinitionItem label={messages.employees.employmentScheme}>
          {relationship.employee_number_scheme === 'PERMANENT' ? messages.employees.schemePermanent : messages.employees.schemeContract}
        </DefinitionItem>
        <DefinitionItem label={messages.employees.effectiveFrom}>
          <DateText value={relationship.effective_from} />
        </DefinitionItem>
        <DefinitionItem label={messages.employee360.effectiveTo}>
          <DateText value={relationship.effective_to} fallback={messages.employee360.openEnded} />
        </DefinitionItem>
        <DefinitionItem label={messages.employees.relationshipState}>
          {relationship.end_knowledge_state === 'KNOWN'
            ? messages.employees.relationshipEnded
            : relationship.end_knowledge_state === 'UNKNOWN'
              ? messages.employee360.endUnknown
              : messages.employees.relationshipActive}
        </DefinitionItem>
        {relationship.end_knowledge_state === 'KNOWN' ? (
          <DefinitionItem label={messages.employee360.endedTerminally}>
            {relationship.ended_terminally ? messages.employee360.yes : messages.employee360.no}
          </DefinitionItem>
        ) : null}
      </DefinitionList>
    </SectionCard>
  )
}
