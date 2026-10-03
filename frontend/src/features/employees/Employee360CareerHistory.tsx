import { useI18n } from '../../i18n/context'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import type {
  EmploymentCategoryPeriod,
  EmploymentContractPeriod,
  EmploymentJobTitlePeriod,
  EmploymentSpecialtyPeriod,
  PersonQualification,
  ReferenceValue,
} from './api'
import { DateText } from '../../shared/ui/DateText'
import { Employee360HistorySection } from './Employee360HistorySection'

type Names = { status: 'loading' | 'ready'; values: Record<string, ReferenceValue> }
type Retryable<T> = ApiResourceState<T[]> & { retry?: () => void }

/**
 * S33: Employment Category (S20), Contract (S21), Job Title (S22) and Specialty (S26) HISTORY plus the
 * person-level Qualifications (S23), exactly as persisted. Catalog names are resolved by id from the
 * reference API; an id that cannot be resolved is shown as the id, never as an invented name. No
 * supervisory concept is shown or implied.
 */
export function Employee360CareerHistory({
  categories,
  contracts,
  jobTitles,
  specialties,
  qualifications,
  categoryNames,
  contractTypeNames,
  jobTitleNames,
  specialtyNames,
  degreeNames,
  qualificationTypeNames,
}: {
  categories: Retryable<EmploymentCategoryPeriod>
  contracts: Retryable<EmploymentContractPeriod>
  jobTitles: Retryable<EmploymentJobTitlePeriod>
  specialties: Retryable<EmploymentSpecialtyPeriod>
  qualifications: Retryable<PersonQualification>
  categoryNames: Names
  contractTypeNames: Names
  jobTitleNames: Names
  specialtyNames: Names
  degreeNames: Names
  qualificationTypeNames: Names
}) {
  const { messages, locale } = useI18n()
  const e = messages.employee360

  const name = (names: Names, id: string) => {
    const value = names.values[id]
    return value ? (locale === 'ar' ? value.name_ar : value.name_en) : id
  }
  // Hold a section in "loading" until its catalog names are resolved, so ids never flash as names.
  const wait = <T,>(state: Retryable<T>, names: Names): Retryable<T> =>
    state.status === 'success' && names.status === 'loading' ? { status: 'loading' } : state

  const from = { header: messages.employees.effectiveFrom }
  const to = { header: e.effectiveTo }

  return (
    <div className="space-y-4">
      <Employee360HistorySection
        headingId="career-category-heading"
        title={e.categoryHistory}
        description={e.historyTimelineNote}
        state={wait(categories, categoryNames)}
        emptyText={e.noCategoryHistory}
        sortKey={(row) => row.effective_from}
        columns={[
          { header: e.employmentCategory, cell: (row) => name(categoryNames, row.employment_category_id) },
          { ...from, cell: (row) => <DateText value={row.effective_from} /> },
          { ...to, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
        ]}
      />
      <Employee360HistorySection
        headingId="career-contract-heading"
        title={e.contractHistory}
        description={e.historyTimelineNote}
        state={wait(contracts, contractTypeNames)}
        emptyText={e.noContractHistory}
        sortKey={(row) => row.effective_from}
        columns={[
          { header: e.contractType, cell: (row) => name(contractTypeNames, row.contract_type_id) },
          { ...from, cell: (row) => <DateText value={row.effective_from} /> },
          { header: e.contractValidTo, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
          {
            header: e.contractualEnd,
            cell: (row) =>
              row.contract_end_knowledge_state === 'UNKNOWN_LEGACY'
                ? e.endUnknown
                : <DateText value={row.contractual_effective_to} fallback={e.openEnded} />,
          },
        ]}
      />
      <Employee360HistorySection
        headingId="career-job-title-heading"
        title={e.jobTitleHistory}
        description={e.historyTimelineNote}
        state={wait(jobTitles, jobTitleNames)}
        emptyText={e.noJobTitleHistory}
        sortKey={(row) => row.effective_from}
        columns={[
          { header: e.jobTitle, cell: (row) => name(jobTitleNames, row.job_title_id) },
          {
            ...from,
            cell: (row) =>
              row.start_knowledge_state === 'UNKNOWN_LEGACY' ? e.startUnknown : <DateText value={row.effective_from} />,
          },
          { ...to, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
        ]}
      />
      <Employee360HistorySection
        headingId="career-specialty-heading"
        title={e.specialtyHistory}
        description={e.historyTimelineNote}
        state={wait(specialties, specialtyNames)}
        emptyText={e.noSpecialtyHistory}
        sortKey={(row) => row.effective_from}
        columns={[
          { header: e.specialty, cell: (row) => name(specialtyNames, row.specialty_id) },
          { ...from, cell: (row) => <DateText value={row.effective_from} /> },
          { ...to, cell: (row) => <DateText value={row.effective_to} fallback={e.openEnded} /> },
        ]}
      />
      <Employee360HistorySection
        headingId="career-qualifications-heading"
        title={e.qualifications}
        state={
          qualifications.status === 'success' && (degreeNames.status === 'loading' || qualificationTypeNames.status === 'loading')
            ? { status: 'loading' }
            : qualifications
        }
        emptyText={e.noQualifications}
        columns={[
          { header: e.academicDegree, cell: (row) => name(degreeNames, row.academic_degree_id) },
          { header: e.qualificationType, cell: (row) => name(qualificationTypeNames, row.qualification_type_id) },
        ]}
      />
    </div>
  )
}
