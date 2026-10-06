import { Button } from '@/components/ui/button'
import { useI18n } from '../../i18n/context'
import { Ltr } from '../../shared/ui/Ltr'

/**
 * Page-at-a-time Prev/Next over the backend's standard Laravel pagination envelope (spec §2.5/§5.6).
 * No npm dependency: reuses the same Button the rest of the app already uses for every other
 * secondary action. Deliberately NOT "load every page" — the follow-up feed is not a bounded
 * catalog, and looping pages automatically would turn one page view into an unbounded number of
 * requests as the table grows (the Architecture Authority's "read-only ≠ zero risk" correction).
 */
export function FollowUpsPagination({
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
  const f = messages.followUps

  if (total === 0) {
    return null
  }

  const from = (currentPage - 1) * pageSize + 1
  const to = Math.min(currentPage * pageSize, total)

  return (
    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
      <p className="text-sm text-muted-foreground">
        {f.paginationShowing}{' '}
        <Ltr>
          {from}–{to}
        </Ltr>{' '}
        {f.paginationOf} <Ltr>{total}</Ltr>
      </p>
      <div className="flex items-center gap-2">
        <Button type="button" variant="outline" size="sm" disabled={currentPage <= 1} onClick={() => onPageChange(currentPage - 1)}>
          {f.previousPage}
        </Button>
        <Button type="button" variant="outline" size="sm" disabled={currentPage >= lastPage} onClick={() => onPageChange(currentPage + 1)}>
          {f.nextPage}
        </Button>
      </div>
    </div>
  )
}
