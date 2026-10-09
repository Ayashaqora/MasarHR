import { Button } from '@/components/ui/button'
import { useI18n } from '../../i18n/context'
import { Ltr } from '../../shared/ui/Ltr'

/**
 * S49: page-at-a-time Prev/Next over the qualification-archive endpoints' Laravel pagination
 * envelope — structurally identical to FollowUpsPagination (features/followUps), reading
 * messages.employee360.paginationShowing / paginationOf / previousPage / nextPage instead of
 * messages.followUps.* (Sec.6
 * of the spec). Deliberately NOT "load every page" for the same reason FollowUpsPagination isn't:
 * these archives are not bounded catalogs, so looping pages automatically would turn one page view
 * into an unbounded number of requests.
 */
export function QualificationArchivePagination({
  currentPage,
  lastPage,
  total,
  pageSize,
  onPageChange,
}: {
  currentPage: number
  lastPage: number
  total: number
  pageSize: number
  onPageChange: (page: number) => void
}) {
  const { messages } = useI18n()
  const e = messages.employee360

  if (total === 0) {
    return null
  }

  const from = (currentPage - 1) * pageSize + 1
  const to = Math.min(currentPage * pageSize, total)

  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
      <p className="text-sm text-muted-foreground">
        {e.paginationShowing}{' '}
        <Ltr>
          {from}–{to}
        </Ltr>{' '}
        {e.paginationOf} <Ltr>{total}</Ltr>
      </p>
      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={currentPage <= 1}
          onClick={() => onPageChange(currentPage - 1)}
        >
          {e.previousPage}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={currentPage >= lastPage}
          onClick={() => onPageChange(currentPage + 1)}
        >
          {e.nextPage}
        </Button>
      </div>
    </div>
  )
}
