import { useState } from 'react'
import { PermissionGate } from '../features/auth/PermissionGate'
import { DashboardView } from '../features/dashboard/DashboardView'
import { useWorkforceAnalytics } from '../features/dashboard/hooks'
import {
  defaultReportingMonth,
  monthInputToReportingMonth,
  reportingMonthToMonthInput,
} from '../features/dashboard/month'
import { useI18n } from '../i18n/context'
import { describeApiError } from '../shared/api/errorMessage'
import { PERMISSIONS_HR_WORKFORCE_ANALYTICS_VIEW } from '../shared/security/permissions'
import { PageHeader } from '../shared/ui/PageHeader'
import { StatePanel } from '../shared/ui/StatePanel'

/**
 * S45 Dashboard Foundation (docs/dashboard-foundation-specification.md). An aggregate-only view over the S44 Workforce Analytics
 * response: ONE request per selected reporting month feeds every widget (DB-D48/DB-D49). No identity, no drilldown, no unsupported KPI.
 */
export function DashboardPage() {
  const { messages } = useI18n()
  const d = messages.dashboard
  const [month, setMonth] = useState(() => defaultReportingMonth())
  const [invalid, setInvalid] = useState(false)

  return (
    <>
      <PageHeader title={d.title} description={d.intro} />
      <PermissionGate permission={PERMISSIONS_HR_WORKFORCE_ANALYTICS_VIEW}>
        <div className="month-picker">
          <label htmlFor="reporting-month" className="month-picker__label">
            {d.monthLabel}
          </label>
          <input
            id="reporting-month"
            className="month-picker__input"
            type="month"
            value={reportingMonthToMonthInput(month)}
            aria-describedby="reporting-month-help"
            onChange={(event) => {
              const next = monthInputToReportingMonth(event.target.value)
              if (next === null) {
                setInvalid(true)
                return
              }
              setInvalid(false)
              setMonth(next)
            }}
          />
          <p id="reporting-month-help" className="month-picker__help">
            {d.monthHelp}
          </p>
          {invalid ? (
            <p role="alert" className="month-picker__error">
              {d.invalidMonth}
            </p>
          ) : null}
        </div>
        <DashboardBody month={month} />
      </PermissionGate>
    </>
  )
}

/** The only component that triggers the analytics request: exactly one per selected month. */
function DashboardBody({ month }: { month: string }) {
  const { messages } = useI18n()
  const d = messages.dashboard
  const state = useWorkforceAnalytics(month)

  if (state.status === 'loading') {
    return <StatePanel tone="loading" title={d.loading} />
  }

  if (state.status === 'error') {
    const forbidden = state.error.status === 403
    return (
      <StatePanel
        tone="error"
        title={forbidden ? d.forbiddenTitle : d.errorTitle}
        action={
          forbidden ? undefined : (
            <button type="button" className="button" onClick={state.retry}>
              {d.retry}
            </button>
          )
        }
      >
        {forbidden ? d.forbiddenDescription : describeApiError(state.error, messages)}
      </StatePanel>
    )
  }

  return (
    <>
      <p className="dashboard-month" data-testid="selected-month">
        {d.selectedMonth}: {state.data.month_start} — {state.data.month_end}
      </p>
      <DashboardView data={state.data} />
    </>
  )
}
