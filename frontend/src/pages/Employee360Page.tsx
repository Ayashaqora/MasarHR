import { useMemo, useState } from 'react'
import { useParams } from 'react-router'
import { PermissionGate } from '../features/auth/PermissionGate'
import { Employee360Employment } from '../features/employees/Employee360Employment'
import { Employee360Header } from '../features/employees/Employee360Header'
import { Employee360MovementTimeline } from '../features/employees/Employee360MovementTimeline'
import { Employee360Overview } from '../features/employees/Employee360Overview'
import { Employee360StatusHistory } from '../features/employees/Employee360StatusHistory'
import { Employee360Workplace } from '../features/employees/Employee360Workplace'
import {
  useActualWorkplace,
  useEmploymentRelationships,
  useEmploymentStatusDetailCatalog,
  useFullSecondmentPeriods,
  useOrganizationalUnitNames,
  usePerson,
  usePlacementPeriods,
  useStatusPeriods,
  useWorkplaceAssignmentPeriods,
} from '../features/employees/hooks'
import { useI18n } from '../i18n/context'
import { describeApiError } from '../shared/api/errorMessage'
import { HR_PERMISSIONS } from '../shared/security/permissions'
import { PageHeader } from '../shared/ui/PageHeader'
import { StatePanel } from '../shared/ui/StatePanel'

type TabKey = 'overview' | 'employment' | 'workplace' | 'status-history' | 'movement-timeline'

const TAB_ORDER: readonly TabKey[] = ['overview', 'employment', 'workplace', 'status-history', 'movement-timeline']

const TAB_LABEL_KEY: Record<TabKey, 'tabOverview' | 'tabEmployment' | 'tabWorkplace' | 'tabStatusHistory' | 'tabMovementTimeline'> = {
  overview: 'tabOverview',
  employment: 'tabEmployment',
  workplace: 'tabWorkplace',
  'status-history': 'tabStatusHistory',
  'movement-timeline': 'tabMovementTimeline',
}

/**
 * S18 Employee 360 page (spec §S18 flow §7/§12). Read-only (spec §16): no transfer, secondment,
 * assignment, status-transition or employment-termination controls are offered here even though
 * the backend commands exist — those operational workflows are explicitly out of S18 scope.
 * Deep-linking works: every resource is fetched from the route params themselves (spec §20), not
 * from state handed down by the Employees search screen, so a bookmarked or shared URL resolves
 * the same way a search-driven navigation does.
 */
export function Employee360Page() {
  const { messages } = useI18n()
  const params = useParams<{ personId: string; relationshipId: string }>()
  const personId = params.personId ?? ''
  const relationshipId = params.relationshipId ?? ''
  const [tab, setTab] = useState<TabKey>('overview')

  const person = usePerson(personId || null)
  const relationships = useEmploymentRelationships(personId || null)
  const statusPeriods = useStatusPeriods(personId, relationshipId)
  const placementPeriods = usePlacementPeriods(personId, relationshipId)
  const secondmentPeriods = useFullSecondmentPeriods(personId, relationshipId)
  const assignmentPeriods = useWorkplaceAssignmentPeriods(personId, relationshipId)
  const actualWorkplace = useActualWorkplace(personId, relationshipId)
  const statusCatalog = useEmploymentStatusDetailCatalog()

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
    return Array.from(ids)
  }, [actualWorkplace, placementPeriods, secondmentPeriods, assignmentPeriods])

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
          relationships.error.status === 403 ? undefined : (
            <button type="button" className="button" onClick={relationships.retry}>
              {messages.systemStatus.retry}
            </button>
          )
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

  return (
    <PermissionGate permission={HR_PERMISSIONS.employmentRelationshipsView}>
      <PageHeader title={messages.employee360.title} />

      <Employee360Header
        person={person.data}
        relationship={relationship}
        statusPeriods={statusPeriods}
        actualWorkplace={actualWorkplace}
        statusCatalog={statusCatalog}
        unitNames={unitNames}
      />

      <div className="tab-list" role="tablist" aria-label={messages.employee360.tabsLabel}>
        {TAB_ORDER.map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            id={`employee-360-tab-${key}`}
            aria-selected={tab === key}
            aria-controls={`employee-360-panel-${key}`}
            className={tab === key ? 'tab-button tab-button--active' : 'tab-button'}
            onClick={() => setTab(key)}
          >
            {messages.employee360[TAB_LABEL_KEY[key]]}
          </button>
        ))}
      </div>

      {/*
        Every tabpanel stays mounted (hidden via the native `hidden` attribute, not unmounted) so
        each tab button's `aria-controls` always resolves to a real element — an inactive button
        referencing a not-yet-rendered id was a real adversarial-review finding (S18 review,
        non-blocking #10) against an earlier draft that rendered only the active panel. All five
        resources are already fetched unconditionally above regardless of which tab is showing, so
        this costs nothing extra in data-fetching, only in DOM nodes.
      */}
      <div
        role="tabpanel"
        id="employee-360-panel-overview"
        aria-labelledby="employee-360-tab-overview"
        hidden={tab !== 'overview'}
      >
        <Employee360Overview
          statusPeriods={statusPeriods}
          statusCatalog={statusCatalog}
          actualWorkplace={actualWorkplace}
          placementPeriods={placementPeriods}
          unitNames={unitNames}
        />
      </div>
      <div
        role="tabpanel"
        id="employee-360-panel-employment"
        aria-labelledby="employee-360-tab-employment"
        hidden={tab !== 'employment'}
      >
        <Employee360Employment relationship={relationship} />
      </div>
      <div
        role="tabpanel"
        id="employee-360-panel-workplace"
        aria-labelledby="employee-360-tab-workplace"
        hidden={tab !== 'workplace'}
      >
        <Employee360Workplace
          actualWorkplace={actualWorkplace}
          placementPeriods={placementPeriods}
          unitNames={unitNames}
        />
      </div>
      <div
        role="tabpanel"
        id="employee-360-panel-status-history"
        aria-labelledby="employee-360-tab-status-history"
        hidden={tab !== 'status-history'}
      >
        <Employee360StatusHistory statusPeriods={statusPeriods} statusCatalog={statusCatalog} />
      </div>
      <div
        role="tabpanel"
        id="employee-360-panel-movement-timeline"
        aria-labelledby="employee-360-tab-movement-timeline"
        hidden={tab !== 'movement-timeline'}
      >
        <Employee360MovementTimeline
          placementPeriods={placementPeriods}
          secondmentPeriods={secondmentPeriods}
          assignmentPeriods={assignmentPeriods}
          unitNames={unitNames}
        />
      </div>
    </PermissionGate>
  )
}
