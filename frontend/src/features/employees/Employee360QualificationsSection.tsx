import { useMemo, type ReactNode } from 'react'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { DataTable } from '../../shared/ui/DataTable'
import { DateText } from '../../shared/ui/DateText'
import { EmptyState } from '../../shared/ui/EmptyState'
import { RetryButton } from '../../shared/ui/RetryButton'
import { SectionCard } from '../../shared/ui/SectionCard'
import { StatePanel } from '../../shared/ui/StatePanel'
import type { PersonQualification, ReferenceValue } from './api'
import { usePersonQualifications, useReferenceValues } from './hooks'
import { PrimaryQualificationHistoryPanel } from './PrimaryQualificationHistoryPanel'
import { QualificationVersionHistoryPanel } from './QualificationVersionHistoryPanel'

type Names = { status: 'loading' | 'ready'; values: Record<string, ReferenceValue> }
type Retryable<T> = ApiResourceState<T[]> & { retry?: () => void }

/**
 * S49 (docs/person-qualification-history-ui-specification.md Sec.3/Sec.4): the person's current
 * qualifications list, the two reference-value fetches it depends on (academic-degree and
 * qualification-type names), and the two archive entry points (per-row version history, and the
 * person-level Primary-designation history in the section's own header action slot). Mounted only
 * inside the `hr.person_qualifications.view` PermissionGate in Employee360CareerHistory.tsx — this
 * component never checks the permission itself; nothing here fetches unless that gate mounts it.
 */
export function Employee360QualificationsSection({ personId }: { personId: string }) {
  const { messages, locale } = useI18n()
  const e = messages.employee360

  const qualifications = usePersonQualifications(personId)

  const idsOf = <T,>(state: Retryable<T>, pick: (row: T) => string): string[] =>
    state.status === 'success' ? state.data.map(pick) : []
  const degreeIds = useMemo(() => idsOf(qualifications, (row) => row.academic_degree_id), [qualifications])
  const qualificationTypeIds = useMemo(
    () => idsOf(qualifications, (row) => row.qualification_type_id),
    [qualifications],
  )
  const degreeNames = useReferenceValues('academic-degrees', degreeIds)
  const qualificationTypeNames = useReferenceValues('qualification-types', qualificationTypeIds)

  const name = (names: Names, id: string) => {
    const value = names.values[id]
    return value ? (locale === 'ar' ? value.name_ar : value.name_en) : id
  }
  // Hold the section in "loading" until both catalogs' names are resolved, so ids never flash as names.
  const wait = <T,>(state: Retryable<T>, names: Names): Retryable<T> =>
    state.status === 'success' && names.status === 'loading' ? { status: 'loading' } : state

  const effective = wait(wait(qualifications, degreeNames), qualificationTypeNames)

  let body: ReactNode
  if (effective.status === 'loading') {
    body = <StatePanel tone="loading" title={e.loading} className="my-0" />
  } else if (effective.status === 'error') {
    body =
      effective.error.status === 403 ? (
        <StatePanel tone="error" className="my-0" title={messages.securityShared.unauthorizedTitle}>
          {messages.securityShared.unauthorizedDescription}
        </StatePanel>
      ) : (
        <StatePanel
          tone="error"
          className="my-0"
          title={e.loadFailed}
          action={<RetryButton onClick={qualifications.retry} />}
        >
          {describeApiError(effective.error, messages)}
        </StatePanel>
      )
  } else if (effective.data.length === 0) {
    body = <EmptyState title={e.noQualifications} />
  } else {
    body = (
      <DataTable
        caption={e.qualifications}
        getKey={(row: PersonQualification) => row.id}
        rows={effective.data}
        columns={[
          {
            header: e.academicDegree,
            cell: (row: PersonQualification) => name(degreeNames, row.academic_degree_id),
          },
          {
            header: e.qualificationType,
            cell: (row: PersonQualification) => name(qualificationTypeNames, row.qualification_type_id),
          },
          {
            header: e.obtainedOn,
            cell: (row: PersonQualification) => <DateText value={row.obtained_on} fallback={e.obtainedOnUnknown} />,
          },
          { header: e.isPrimaryColumn, cell: (row: PersonQualification) => (row.is_primary ? e.yes : e.no) },
          {
            header: e.provenanceColumn,
            cell: (row: PersonQualification) =>
              row.provenance === 'RECORDED' ? e.provenanceRecorded : e.provenanceBackfilled,
          },
          {
            header: e.actionsColumn,
            cell: (row: PersonQualification) => (
              <QualificationVersionHistoryPanel
                personId={personId}
                qualificationId={row.id}
                qualificationLabel={name(degreeNames, row.academic_degree_id)}
              />
            ),
          },
        ]}
      />
    )
  }

  return (
    <SectionCard
      level={3}
      headingId="career-qualifications-heading"
      title={e.qualifications}
      action={<PrimaryQualificationHistoryPanel personId={personId} />}
    >
      {body}
    </SectionCard>
  )
}
