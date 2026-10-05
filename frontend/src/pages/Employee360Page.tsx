import { useMemo, useState } from 'react'
import { ArrowLeft } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PermissionGate } from '../features/auth/PermissionGate'
import { Employee360CareerHistory } from '../features/employees/Employee360CareerHistory'
import { Employee360Employment } from '../features/employees/Employee360Employment'
import { Employee360Header } from '../features/employees/Employee360Header'
import { Employee360MovementTimeline } from '../features/employees/Employee360MovementTimeline'
import { Employee360Overview } from '../features/employees/Employee360Overview'
import { Employee360ReturnIntentionHistory } from '../features/employees/Employee360ReturnIntention'
import { Employee360StatusHistory } from '../features/employees/Employee360StatusHistory'
import { MovementOperations } from '../features/employees/operations/MovementOperations'
import { EndRelationshipOperations, WorkScheduleOperations } from '../features/employees/operations/ScheduleAndEndOperations'
import { ReturnIntentionOperations, StatusOperations } from '../features/employees/operations/StatusOperations'
import { Employee360WorkArrangements } from '../features/employees/Employee360WorkArrangements'
import { Employee360Workplace } from '../features/employees/Employee360Workplace'
import {
  useActualWorkplace,
  useEffectiveReturnIntention,
  useEffectiveStatus,
  useEmploymentCategoryPeriods,
  useEmploymentContractPeriods,
  useEmploymentJobTitlePeriods,
  useEmploymentRelationships,
  useEmploymentSpecialtyPeriods,
  useEmploymentStatusDetailCatalog,
  useFullSecondmentPeriods,
  useOrganizationalUnitNames,
  usePartialSecondmentPeriods,
  usePerson,
  usePersonQualifications,
  usePlacementPeriods,
  useReferenceValues,
  useReturnIntentionPeriods,
  useStatusPeriods,
  useWorkSchedulePeriods,
  useWorkplaceAssignmentPeriods,
} from '../features/employees/hooks'
import { useI18n } from '../i18n/context'
import { describeApiError } from '../shared/api/errorMessage'
import { HR_PERMISSIONS } from '../shared/security/permissions'
import { FeedbackContext } from '../shared/ui/feedbackContext'
import { OperationFeedback } from '../shared/ui/Operation'
import { PageHeader } from '../shared/ui/PageHeader'
import { RetryButton } from '../shared/ui/RetryButton'
import { StatePanel } from '../shared/ui/StatePanel'

type TabKey =
  | 'employment'
  | 'workplace'
  | 'status-history'
  | 'movement-timeline'
  | 'work-arrangements'
  | 'career-history'

const TAB_ORDER: readonly TabKey[] = [
  'employment',
  'workplace',
  'status-history',
  'movement-timeline',
  'work-arrangements',
  'career-history',
]

const TAB_LABEL_KEY: Record<
  TabKey,
  | 'tabEmployment'
  | 'tabWorkplace'
  | 'tabStatusHistory'
  | 'tabMovementTimeline'
  | 'tabWorkArrangements'
  | 'tabCareerHistory'
> = {
  employment: 'tabEmployment',
  workplace: 'tabWorkplace',
  'status-history': 'tabStatusHistory',
  'movement-timeline': 'tabMovementTimeline',
  'work-arrangements': 'tabWorkArrangements',
  'career-history': 'tabCareerHistory',
}

/**
 * S18 Employee 360 page (spec §S18 flow §7/§12). S46 adds the operational actions that wire the EXISTING backend
 * commands (status, return intention, transfer, secondment, assignment, partial secondment, work schedule,
 * relationship end); each is offered only to a caller holding its write permission, and the backend remains the
 * authority (permission + organizational scope) for every request.
 * Deep-linking works: every resource is fetched from the route params themselves (spec §20), not
 * from state handed down by the Employees search screen, so a bookmarked or shared URL resolves
 * the same way a search-driven navigation does.
 */
export function Employee360Page() {
  const { messages } = useI18n()
  const params = useParams<{ personId: string; relationshipId: string }>()
  const personId = params.personId ?? ''
  const relationshipId = params.relationshipId ?? ''
  const [tab, setTab] = useState<TabKey>('status-history')
  // S46: the success announcement lives here, above every data-dependent branch, so a refetch cannot erase it.
  const [feedback, setFeedback] = useState<string | null>(null)

  const person = usePerson(personId || null)
  const relationships = useEmploymentRelationships(personId || null)
  const statusPeriods = useStatusPeriods(personId, relationshipId)
  const placementPeriods = usePlacementPeriods(personId, relationshipId)
  const secondmentPeriods = useFullSecondmentPeriods(personId, relationshipId)
  const assignmentPeriods = useWorkplaceAssignmentPeriods(personId, relationshipId)
  const actualWorkplace = useActualWorkplace(personId, relationshipId)
  const statusCatalog = useEmploymentStatusDetailCatalog()
  // S33: the current status is the backend's effective status (S32), not derived from statusPeriods.
  const effectiveStatus = useEffectiveStatus(personId, relationshipId)
  // S34: Return Intention is an independent concept — read from its own endpoints, never from status rows.
  const effectiveIntention = useEffectiveReturnIntention(personId, relationshipId)
  const returnIntentionPeriods = useReturnIntentionPeriods(personId, relationshipId)
  const partialSecondments = usePartialSecondmentPeriods(personId, relationshipId)
  const workSchedules = useWorkSchedulePeriods(personId, relationshipId)
  const categoryPeriods = useEmploymentCategoryPeriods(personId, relationshipId)
  const contractPeriods = useEmploymentContractPeriods(personId, relationshipId)
  const jobTitlePeriods = useEmploymentJobTitlePeriods(personId, relationshipId)
  const specialtyPeriods = useEmploymentSpecialtyPeriods(personId, relationshipId)
  const qualifications = usePersonQualifications(personId)

  const idsOf = <T,>(state: { status: string; data?: T[] }, pick: (row: T) => string): string[] =>
    state.status === 'success' && state.data ? state.data.map(pick) : []
  const categoryIds = useMemo(() => idsOf(categoryPeriods, (r) => r.employment_category_id), [categoryPeriods])
  const contractTypeIds = useMemo(() => idsOf(contractPeriods, (r) => r.contract_type_id), [contractPeriods])
  const jobTitleIds = useMemo(() => idsOf(jobTitlePeriods, (r) => r.job_title_id), [jobTitlePeriods])
  const specialtyIds = useMemo(() => idsOf(specialtyPeriods, (r) => r.specialty_id), [specialtyPeriods])
  const degreeIds = useMemo(() => idsOf(qualifications, (r) => r.academic_degree_id), [qualifications])
  const qualificationTypeIds = useMemo(() => idsOf(qualifications, (r) => r.qualification_type_id), [qualifications])
  const categoryNames = useReferenceValues('employment-categories', categoryIds)
  const contractTypeNames = useReferenceValues('contract-types', contractTypeIds)
  const jobTitleNames = useReferenceValues('job-titles', jobTitleIds)
  const specialtyNames = useReferenceValues('specialties', specialtyIds)
  const degreeNames = useReferenceValues('academic-degrees', degreeIds)
  const qualificationTypeNames = useReferenceValues('qualification-types', qualificationTypeIds)

  const unitIds = useMemo(() => {
    const ids = new Set<string>()
    if (actualWorkplace.status === 'success' && actualWorkplace.data.organizational_unit_id) {
      ids.add(actualWorkplace.data.organizational_unit_id)
    }
    if (placementPeriods.status === 'success') {
      placementPeriods.data.forEach((period) => ids.add(period.organizational_unit_id))
    }
    if (secondmentPeriods.status === 'success') {
      secondmentPeriods.data.forEach((period) => ids.add(period.organizational_unit_id))
    }
    if (assignmentPeriods.status === 'success') {
      assignmentPeriods.data.forEach((period) => ids.add(period.organizational_unit_id))
    }
    if (partialSecondments.status === 'success') {
      partialSecondments.data.forEach((period) => ids.add(period.organizational_unit_id))
    }
    return Array.from(ids)
  }, [actualWorkplace, placementPeriods, secondmentPeriods, assignmentPeriods, partialSecondments])

  const unitNames = useOrganizationalUnitNames(unitIds)

  if (personId === '' || relationshipId === '') {
    return (
      <StatePanel tone="error" title={messages.errors.notFoundTitle}>
        {messages.errors.notFoundDescription}
      </StatePanel>
    )
  }

  if (person.status === 'loading' || relationships.status === 'loading') {
    return <StatePanel tone="loading" title={messages.employee360.loading} />
  }

  if (person.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={
          person.error.status === 404
            ? messages.errors.notFoundTitle
            : person.error.status === 403
              ? messages.securityShared.unauthorizedTitle
              : messages.employee360.loadFailed
        }
      >
        {person.error.status === 404
          ? messages.errors.notFoundDescription
          : person.error.status === 403
            ? messages.securityShared.unauthorizedDescription
            : describeApiError(person.error, messages)}
      </StatePanel>
    )
  }

  if (relationships.status === 'error') {
    return (
      <StatePanel
        tone="error"
        title={
          relationships.error.status === 403 ? messages.securityShared.unauthorizedTitle : messages.employee360.loadFailed
        }
        action={
          relationships.error.status === 403 ? undefined : <RetryButton onClick={relationships.retry} />
        }
      >
        {relationships.error.status === 403
          ? messages.securityShared.unauthorizedDescription
          : describeApiError(relationships.error, messages)}
      </StatePanel>
    )
  }

  const relationship = relationships.data.find((item) => item.id === relationshipId)

  if (!relationship) {
    return (
      <StatePanel tone="error" title={messages.errors.notFoundTitle}>
        {messages.errors.notFoundDescription}
      </StatePanel>
    )
  }

  // S46 operations. The backend stays the authority: after any accepted write the canonical reads are re-fetched
  // (never patched locally), and the relationship-ended state comes from the relationship read itself.
  const ended = relationship.end_knowledge_state === 'KNOWN'
  const context = {
    personId,
    relationshipId,
    employeeLabel: person.status === 'success' ? person.data.full_name_ar || person.data.national_id : relationshipId,
  }
  const refetchStatus = () => {
    effectiveStatus.retry()
    statusPeriods.retry()
  }
  const refetchIntention = () => {
    effectiveIntention.retry()
    returnIntentionPeriods.retry()
  }
  const refetchMovements = () => {
    placementPeriods.retry()
    secondmentPeriods.retry()
    assignmentPeriods.retry()
    partialSecondments.retry()
    actualWorkplace.retry()
  }
  const refetchAfterRelationshipChange = () => {
    relationships.retry()
    refetchStatus()
    refetchIntention()
    refetchMovements()
    workSchedules.retry()
  }
  const hasOpenSecondment = secondmentPeriods.status === 'success' && secondmentPeriods.data.some((period) => period.effective_to === null)
  const hasOpenAssignment = assignmentPeriods.status === 'success' && assignmentPeriods.data.some((period) => period.effective_to === null)

  return (
    <PermissionGate permission={HR_PERMISSIONS.employmentRelationshipsView}>
     <FeedbackContext.Provider value={setFeedback}>
      <PageHeader
        title={messages.employee360.title}
        actions={
          <Button asChild variant="outline" size="sm">
            <Link to="/employees">
              <ArrowLeft aria-hidden="true" className="ltr:rotate-180" />
              {messages.employee360.backToSearch}
            </Link>
          </Button>
        }
      />

      <OperationFeedback message={feedback} onDismiss={() => setFeedback(null)} />

      <div className="space-y-6">
        <Employee360Header
          person={person.data}
          relationship={relationship}
          effectiveStatus={effectiveStatus}
          effectiveIntention={effectiveIntention}
          actualWorkplace={actualWorkplace}
          statusCatalog={statusCatalog}
          unitNames={unitNames}
        />

        <Employee360Overview
          effectiveStatus={effectiveStatus}
          effectiveIntention={effectiveIntention}
          statusCatalog={statusCatalog}
          actualWorkplace={actualWorkplace}
          placementPeriods={placementPeriods}
          unitNames={unitNames}
        />

        {/*
          Every tabpanel stays mounted (hidden via the `hidden` attribute, not unmounted) so each tab trigger's
          `aria-controls` always resolves to a real element — an inactive trigger referencing a not-yet-rendered id
          was a real adversarial-review finding (S18 review, non-blocking #10) against an earlier draft that rendered
          only the active panel. All resources are already fetched unconditionally above regardless of which tab is
          showing, so this costs nothing extra in data-fetching, only in DOM nodes.
        */}
        <Tabs value={tab} onValueChange={(value) => setTab(value as TabKey)}>
          {/*
            S46-BF02 RC2: one row, always. A wrapped list (RC1) was rejected in the real browser, so the strip never wraps.
            The scroll container is a plain wrapper around the list (not the list itself): the list keeps the shared 36px
            height, `w-max min-w-full` makes it exactly as wide as its tabs (full width when they fit, so desktop is
            unchanged), and the wrapper alone scrolls horizontally when they do not. `justify-start` matters: the shared
            default `justify-center` would push the overflowing end of the strip out of reach. The scrollbar renders in the
            wrapper's own bottom padding, below the list, so it can never cover a tab or the content under it. Direction
            follows the document (the start edge is right in Arabic, left in English), and every tab scrolls fully into view when focused.
          */}
          <div className="max-w-full overflow-x-auto overflow-y-hidden pb-2 [scrollbar-width:thin]">
            <TabsList aria-label={messages.employee360.tabsLabel} className="w-max min-w-full flex-nowrap justify-start gap-1 p-1">
              {TAB_ORDER.map((key) => (
                <TabsTrigger
                  key={key}
                  value={key}
                  className="flex-none px-3 py-1.5"
                  // Browsers only scroll a focused element into view when it is wholly hidden; a half-clipped tab stayed half-clipped.
                  onFocus={(event) => event.currentTarget.scrollIntoView({ block: 'nearest', inline: 'nearest' })}
                >
                  {messages.employee360[TAB_LABEL_KEY[key]]}
                </TabsTrigger>
              ))}
            </TabsList>
          </div>

          <TabsContent value="employment" forceMount hidden={tab !== 'employment'} className="mt-4 space-y-4">
            <Employee360Employment relationship={relationship} />
            <EndRelationshipOperations
              context={context}
              relationship={relationship}
              onChanged={refetchAfterRelationshipChange}
              onRefresh={refetchAfterRelationshipChange}
            />
          </TabsContent>
          <TabsContent value="workplace" forceMount hidden={tab !== 'workplace'} className="mt-4">
            <Employee360Workplace actualWorkplace={actualWorkplace} placementPeriods={placementPeriods} unitNames={unitNames} />
          </TabsContent>
          <TabsContent value="status-history" forceMount hidden={tab !== 'status-history'} className="mt-4 space-y-4">
            <StatusOperations
              context={context}
              statusCatalog={statusCatalog}
              ended={ended}
              onChanged={refetchStatus}
              onRelationshipEnded={refetchAfterRelationshipChange}
            />
            <Employee360StatusHistory statusPeriods={statusPeriods} statusCatalog={statusCatalog} />
            <ReturnIntentionOperations context={context} ended={ended} onChanged={refetchIntention} />
            <Employee360ReturnIntentionHistory returnIntentionPeriods={returnIntentionPeriods} />
          </TabsContent>
          <TabsContent value="movement-timeline" forceMount hidden={tab !== 'movement-timeline'} className="mt-4 space-y-4">
            <MovementOperations
              context={context}
              ended={ended}
              hasOpenSecondment={hasOpenSecondment}
              hasOpenAssignment={hasOpenAssignment}
              onChanged={refetchMovements}
            />
            <Employee360MovementTimeline
              placementPeriods={placementPeriods}
              secondmentPeriods={secondmentPeriods}
              assignmentPeriods={assignmentPeriods}
              unitNames={unitNames}
            />
          </TabsContent>
          <TabsContent value="work-arrangements" forceMount hidden={tab !== 'work-arrangements'} className="mt-4 space-y-4">
            <WorkScheduleOperations context={context} ended={ended} onChanged={() => workSchedules.retry()} />
            <Employee360WorkArrangements partialSecondments={partialSecondments} workSchedules={workSchedules} unitNames={unitNames} />
          </TabsContent>
          <TabsContent value="career-history" forceMount hidden={tab !== 'career-history'} className="mt-4">
            <Employee360CareerHistory
              categories={categoryPeriods}
              contracts={contractPeriods}
              jobTitles={jobTitlePeriods}
              specialties={specialtyPeriods}
              qualifications={qualifications}
              categoryNames={categoryNames}
              contractTypeNames={contractTypeNames}
              jobTitleNames={jobTitleNames}
              specialtyNames={specialtyNames}
              degreeNames={degreeNames}
              qualificationTypeNames={qualificationTypeNames}
            />
          </TabsContent>
        </Tabs>
      </div>
     </FeedbackContext.Provider>
    </PermissionGate>
  )
}
