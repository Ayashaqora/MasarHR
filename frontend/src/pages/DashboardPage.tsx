import { useState } from 'react'
import { Card, CardContent } from '@/components/ui/card'
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
import { DateText } from '../shared/ui/DateText'
import { MonthInput } from '../shared/ui/MonthInput'
import { PageHeader } from '../shared/ui/PageHeader'
import { RetryButton } from '../shared/ui/RetryButton'
import { Skeleton } from '@/components/ui/skeleton'
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
        <Card className="mb-6">
          <CardContent>
            <form noValidate onSubmit={(event) => event.preventDefault()} className="max-w-sm">
              <MonthInput
                label={d.monthLabel}
                helper={d.monthHelp}
                error={invalid ? d.invalidMonth : null}
                value={reportingMonthToMonthInput(month)}
                onChange={(next) => {
                  const parsed = monthInputToReportingMonth(next)
                  if (parsed === null) {
                    setInvalid(true)
                    return
                  }
                  setInvalid(false)
                  setMonth(parsed)
                }}
              />
            </form>
          </CardContent>
        </Card>
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
    return (
      <div className="space-y-4" aria-hidden="false">
        <StatePanel tone="loading" title={d.loading} className="my-0" />
        <div className="grid gap-4 sm:grid-cols-2" aria-hidden="true">
          <Skeleton className="h-28" />
          <Skeleton className="h-28" />
        </div>
      </div>
    )
  }

  if (state.status === 'error') {
    const forbidden = state.error.status === 403
    return (
      <StatePanel
        tone="error"
        title={forbidden ? d.forbiddenTitle : d.errorTitle}
        action={
          forbidden ? undefined : <RetryButton onClick={state.retry} />
        }
      >
        {forbidden ? d.forbiddenDescription : describeApiError(state.error, messages)}
      </StatePanel>
    )
  }

  return (
    <>
      <p className="mb-4 text-sm font-medium text-muted-foreground" data-testid="selected-month">
        {d.selectedMonth}: <DateText value={state.data.month_start} /> — <DateText value={state.data.month_end} />
      </p>
      <DashboardView data={state.data} />
    </>
  )
}
