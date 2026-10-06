import { useState } from 'react'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '../features/auth/context'
import { MovementFollowUpsTable } from '../features/followUps/MovementFollowUpsTable'
import { StatusFollowUpsTable } from '../features/followUps/StatusFollowUpsTable'
import { useI18n } from '../i18n/context'
import { HR_PERMISSIONS } from '../shared/security/permissions'
import { PageHeader } from '../shared/ui/PageHeader'
import { StatePanel } from '../shared/ui/StatePanel'

type TabKey = 'movement' | 'status'

/**
 * S47 Expiry Follow-up Read UI (docs/expiry-followups-ui-specification.md). An independent page,
 * never embedded in the Dashboard or a report (the Architecture Authority's explicit decision).
 * Two read-only lists over the S31/S38 scanner-maintained follow-up tables, each gated by its OWN
 * permission — the two are independent in the backend (§2.2: holding one never grants the other),
 * so this page never collapses them into a single check. A principal sees only the tab(s) their
 * permissions cover; direct URL access with neither permission shows the same unauthorized panel
 * every other permission-gated page in this app already shows. The backend remains the sole
 * authority — this is UX only.
 */
export function FollowUpsPage() {
  const { messages } = useI18n()
  const { hasPermission } = useAuth()
  const f = messages.followUps

  const canMovement = hasPermission(HR_PERMISSIONS.movementExpiryFollowupsView)
  const canStatus = hasPermission(HR_PERMISSIONS.employmentStatusExpiryFollowupsView)

  const [tab, setTab] = useState<TabKey>(canMovement ? 'movement' : 'status')

  return (
    <>
      <PageHeader title={f.title} description={f.intro} />

      {!canMovement && !canStatus ? (
        <StatePanel tone="error" title={messages.securityShared.unauthorizedTitle}>
          {messages.securityShared.unauthorizedDescription}
        </StatePanel>
      ) : null}

      {canMovement && canStatus ? (
        <Tabs value={tab} onValueChange={(value) => setTab(value as TabKey)}>
          {/* S47 CONSOLIDATED REVIEW FIX: at 390px the longer label ("Employment status expiry
              follow-up" / its Arabic equivalent) clipped past the viewport edge with no way to
              reach the clipped text (confirmed live). Reuses the exact S46-BF02 pattern already
              established for Employee360Page's own TabsList (pages/Employee360Page.tsx) rather
              than inventing a new one: a dedicated horizontal-scroll wrapper around the list alone,
              so the strip never wraps, both tabs stay reachable and focusable, and nothing outside
              this component becomes scrollable. */}
          <div className="max-w-full overflow-x-auto overflow-y-hidden pb-2 [scrollbar-width:thin]">
            <TabsList aria-label={f.tabsLabel} className="w-max min-w-full flex-nowrap justify-start gap-1 p-1">
              <TabsTrigger
                value="movement"
                className="flex-none px-3 py-1.5"
                onFocus={(event) => event.currentTarget.scrollIntoView({ block: 'nearest', inline: 'nearest' })}
              >
                {f.tabMovement}
              </TabsTrigger>
              <TabsTrigger
                value="status"
                className="flex-none px-3 py-1.5"
                onFocus={(event) => event.currentTarget.scrollIntoView({ block: 'nearest', inline: 'nearest' })}
              >
                {f.tabStatus}
              </TabsTrigger>
            </TabsList>
          </div>
          {/* No forceMount: the inactive tab's content unmounts, so switching tabs cannot leave a
              stale fetch or stale state behind for the tab the viewer just left (spec §5.2). */}
          <TabsContent value="movement" className="mt-4">
            <MovementFollowUpsTable enabled={tab === 'movement'} />
          </TabsContent>
          <TabsContent value="status" className="mt-4">
            <StatusFollowUpsTable enabled={tab === 'status'} />
          </TabsContent>
        </Tabs>
      ) : null}

      {canMovement && !canStatus ? <MovementFollowUpsTable enabled /> : null}
      {canStatus && !canMovement ? <StatusFollowUpsTable enabled /> : null}
    </>
  )
}
