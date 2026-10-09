import { useMemo, useState, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { DataTable } from '../../shared/ui/DataTable'
import { DateText } from '../../shared/ui/DateText'
import { EmptyState } from '../../shared/ui/EmptyState'
import { Ltr } from '../../shared/ui/Ltr'
import { RetryButton } from '../../shared/ui/RetryButton'
import { StatePanel } from '../../shared/ui/StatePanel'
import {
  QUALIFICATIONS_ARCHIVE_PER_PAGE,
  type PersonQualificationVersion,
  type QualificationPage,
  type ReferenceValue,
} from './api'
import { useQualificationVersions, useReferenceValues } from './hooks'
import { QualificationArchivePagination } from './QualificationArchivePagination'

type Names = { status: 'loading' | 'ready'; values: Record<string, ReferenceValue> }
type VersionsState = ApiResourceState<QualificationPage<PersonQualificationVersion>> & { retry?: () => void }

/**
 * S49 (docs/person-qualification-history-ui-specification.md Sec.3/Sec.5): a read-only trigger +
 * Sheet over one qualification's version history. The Sheet body (and the data hooks inside it)
 * only exist while `open` is true: closing the Sheet fully unmounts
 * `QualificationVersionHistoryContent`, so reopening it always starts fresh at page 1 — `page`
 * state is owned there, never lifted into this outer component.
 */
export function QualificationVersionHistoryPanel({
  personId,
  qualificationId,
  qualificationLabel,
}: {
  personId: string
  qualificationId: string
  qualificationLabel: string
}) {
  const { messages } = useI18n()
  const e = messages.employee360
  const [open, setOpen] = useState(false)

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => setOpen(true)}
        aria-label={`${e.versionHistory} — ${qualificationLabel}`}
      >
        {e.versionHistory}
      </Button>
      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md" closeLabel={messages.app.close}>
          <SheetHeader>
            <SheetTitle>{e.versionHistoryTitle}</SheetTitle>
            <SheetDescription>{e.versionHistoryDescription}</SheetDescription>
          </SheetHeader>
          {open ? (
            <QualificationVersionHistoryContent personId={personId} qualificationId={qualificationId} />
          ) : null}
        </SheetContent>
      </Sheet>
    </>
  )
}

function QualificationVersionHistoryContent({
  personId,
  qualificationId,
}: {
  personId: string
  qualificationId: string
}) {
  const { messages, locale } = useI18n()
  const e = messages.employee360
  const [page, setPage] = useState(1)

  const versions = useQualificationVersions(personId, qualificationId, page)

  const degreeIds = useMemo(() => {
    if (versions.status !== 'success') return []
    return versions.data.data.flatMap((row) => (row.academic_degree_id ? [row.academic_degree_id] : []))
  }, [versions])
  const qualificationTypeIds = useMemo(() => {
    if (versions.status !== 'success') return []
    return versions.data.data.flatMap((row) => (row.qualification_type_id ? [row.qualification_type_id] : []))
  }, [versions])
  const degreeNames = useReferenceValues('academic-degrees', degreeIds)
  const qualificationTypeNames = useReferenceValues('qualification-types', qualificationTypeIds)

  const name = (names: Names, id: string) => {
    const value = names.values[id]
    return value ? (locale === 'ar' ? value.name_ar : value.name_en) : id
  }
  // Hold this page's content in "loading" until this page's own catalog names are resolved — names
  // are resolved per page, never carried over from a previously-viewed page (Sec.5 of the spec).
  const wait = (state: VersionsState, names: Names): VersionsState =>
    state.status === 'success' && names.status === 'loading' ? { status: 'loading' } : state

  const effective = wait(wait(versions, degreeNames), qualificationTypeNames)

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
          action={<RetryButton onClick={versions.retry} />}
        >
          {describeApiError(effective.error, messages)}
        </StatePanel>
      )
  } else if (effective.data.data.length === 0) {
    body = <EmptyState title={e.noVersionHistory} />
  } else {
    const pageData = effective.data
    body = (
      <>
        <DataTable
          caption={e.versionHistoryTitle}
          getKey={(row: PersonQualificationVersion) => String(row.version_number)}
          rows={pageData.data}
          columns={[
            { header: e.versionNumber, cell: (row: PersonQualificationVersion) => <Ltr>{row.version_number}</Ltr> },
            {
              header: e.academicDegree,
              cell: (row: PersonQualificationVersion) =>
                row.academic_degree_id ? name(degreeNames, row.academic_degree_id) : '—',
            },
            {
              header: e.qualificationType,
              cell: (row: PersonQualificationVersion) =>
                row.qualification_type_id ? name(qualificationTypeNames, row.qualification_type_id) : '—',
            },
            {
              header: e.obtainedOn,
              cell: (row: PersonQualificationVersion) => (
                <DateText value={row.obtained_on} fallback={e.obtainedOnUnknown} />
              ),
            },
            {
              header: e.correctionReason,
              cell: (row: PersonQualificationVersion) => row.reason ?? e.noCorrectionReason,
            },
            {
              header: e.currentVersion,
              cell: (row: PersonQualificationVersion) => (row.is_current ? e.yes : e.no),
            },
            {
              header: e.provenanceColumn,
              cell: (row: PersonQualificationVersion) =>
                row.provenance === 'RECORDED' ? e.provenanceRecorded : e.provenanceBackfilled,
            },
            {
              header: e.createdByPrincipal,
              cell: (row: PersonQualificationVersion) =>
                row.created_by_principal_id ? <Ltr>{row.created_by_principal_id}</Ltr> : e.actorUnknown,
            },
          ]}
        />
        <QualificationArchivePagination
          currentPage={pageData.meta.current_page}
          lastPage={pageData.meta.last_page}
          total={pageData.meta.total}
          pageSize={QUALIFICATIONS_ARCHIVE_PER_PAGE}
          onPageChange={setPage}
        />
      </>
    )
  }

  return <div className="px-4 pb-4">{body}</div>
}
