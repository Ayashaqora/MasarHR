import { useState, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import { DataTable } from '../../shared/ui/DataTable'
import { DateText } from '../../shared/ui/DateText'
import { EmptyState } from '../../shared/ui/EmptyState'
import { Ltr } from '../../shared/ui/Ltr'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import { QUALIFICATIONS_ARCHIVE_PER_PAGE, type PrimaryQualificationHistoryEvent } from './api'
import { usePrimaryQualificationHistory } from './hooks'
import { QualificationArchivePagination } from './QualificationArchivePagination'

/**
 * S49 (docs/person-qualification-history-ui-specification.md Sec.3/Sec.5): a read-only trigger +
 * Sheet over the whole person's Primary-designation history plus its evidence-gap summary. Same
 * lazy-mount, reset-on-reopen shape as QualificationVersionHistoryPanel — `page` is owned by
 * `PrimaryQualificationHistoryContent`, which fully unmounts when the Sheet closes.
 */
export function PrimaryQualificationHistoryPanel({ personId }: { personId: string }) {
  const { messages } = useI18n()
  const e = messages.employee360
  const [open, setOpen] = useState(false)

  return (
    <>
      <Button type="button" variant="outline" size="sm" onClick={() => setOpen(true)}>
        {e.primaryHistory}
      </Button>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md" closeLabel={messages.app.close}>
          <SheetHeader>
            <SheetTitle>{e.primaryHistoryTitle}</SheetTitle>
            <SheetDescription>{e.primaryHistoryDescription}</SheetDescription>
          </SheetHeader>
          {open ? <PrimaryQualificationHistoryContent personId={personId} /> : null}
        </SheetContent>
      </Sheet>
    </>
  )
}

function PrimaryQualificationHistoryContent({ personId }: { personId: string }) {
  const { messages } = useI18n()
  const e = messages.employee360
  const [page, setPage] = useState(1)

  const history = usePrimaryQualificationHistory(personId, page)

  let body: ReactNode
  if (history.status === 'loading') {
    body = <StatePanel tone="loading" title={e.loading} className="my-0" />
  } else if (history.status === 'error') {
    body =
      history.error.status === 403 ? (
        <StatePanel tone="error" className="my-0" title={messages.securityShared.unauthorizedTitle}>
          {messages.securityShared.unauthorizedDescription}
        </StatePanel>
      ) : (
        <StatePanel
          tone="error"
          className="my-0"
          title={e.loadFailed}
          action={<RetryButton onClick={history.retry} />}
        >
          {describeApiError(history.error, messages)}
        </StatePanel>
      )
  } else {
    const result = history.data
    const gaps = result.evidence_completeness.gaps
    body = (
      <>
        {result.events.length === 0 ? (
          <EmptyState title={e.noPrimaryHistory} />
        ) : (
          <>
            <DataTable
              caption={e.primaryHistory}
              getKey={(row: PrimaryQualificationHistoryEvent) =>
                `${row.type}-${row.qualification_id}-${row.occurred_at}`
              }
              rows={result.events}
              columns={[
                {
                  header: e.status,
                  cell: (row: PrimaryQualificationHistoryEvent) =>
                    row.type === 'DESIGNATED' ? e.eventTypeDesignated : e.eventTypeAutoFirst,
                },
                {
                  header: e.qualificationReference,
                  cell: (row: PrimaryQualificationHistoryEvent) => <Ltr>{row.qualification_id}</Ltr>,
                },
                {
                  header: e.previousPrimaryQualification,
                  cell: (row: PrimaryQualificationHistoryEvent) =>
                    row.previous_primary_qualification_id ? (
                      <Ltr>{row.previous_primary_qualification_id}</Ltr>
                    ) : (
                      '—'
                    ),
                },
                {
                  header: e.actorReference,
                  cell: (row: PrimaryQualificationHistoryEvent) =>
                    row.actor_principal_id ? <Ltr>{row.actor_principal_id}</Ltr> : e.actorUnknown,
                },
                {
                  header: e.occurredAt,
                  cell: (row: PrimaryQualificationHistoryEvent) => <DateText value={row.occurred_at.slice(0, 10)} />,
                },
              ]}
            />
            <QualificationArchivePagination
              currentPage={result.meta.current_page}
              lastPage={result.meta.last_page}
              total={result.meta.total}
              pageSize={QUALIFICATIONS_ARCHIVE_PER_PAGE}
              onPageChange={setPage}
            />
          </>
        )}
        {gaps.length > 0 ? (
          <div role="status" className="mt-3 rounded-md border border-dashed p-3 text-sm">
            <p className="font-medium">{e.evidenceGapsTitle}</p>
            <ul className="mt-1 list-inside list-disc space-y-1">
              {gaps.map((gap, index) => (
                <li key={`${gap.code}-${gap.qualification_id}-${index}`}>
                  {gap.code === 'GAP_NO_DESIGNATION_EVIDENCE' ? e.gapNoDesignationEvidence : e.gapChainBroken}
                  {' '}
                  <Ltr>{gap.qualification_id}</Ltr>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </>
    )
  }

  return <div className="px-4 pb-4">{body}</div>
}
