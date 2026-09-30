import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/context'
import { describeApiError } from '../../shared/api/errorMessage'
import type { ApiResourceState } from '../../shared/hooks/useApiResource'
import { StatePanel } from '../../shared/ui/StatePanel'

export interface HistoryColumn<T> {
  header: string
  cell: (row: T) => ReactNode
}

/**
 * S33: one historical timeline (a persisted-period stream) rendered as a table, newest first. It only
 * lists what the backend returned — it never marks any row as "current" (that would be the
 * open-period shortcut S33 removes); current state lives in the Overview/header from authoritative
 * backend reads. Loading / 403 / error / empty are handled per section so one failing stream never
 * blanks the others.
 */
export function Employee360HistorySection<T extends { id: string }>({
  headingId,
  title,
  description,
  state,
  columns,
  emptyText,
  sortKey,
}: {
  headingId: string
  title: string
  description?: string
  state: ApiResourceState<T[]> & { retry?: () => void }
  columns: readonly HistoryColumn<T>[]
  emptyText: string
  sortKey?: (row: T) => string | null
}) {
  const { messages } = useI18n()

  let body: ReactNode
  if (state.status === 'loading') {
    body = <StatePanel tone="loading" title={messages.employee360.loading} />
  } else if (state.status === 'error') {
    body = (
      <StatePanel
        tone="error"
        title={state.error.status === 403 ? messages.securityShared.unauthorizedTitle : messages.employee360.loadFailed}
        action={
          state.error.status === 403 || !state.retry ? undefined : (
            <button type="button" className="button" onClick={state.retry}>
              {messages.systemStatus.retry}
            </button>
          )
        }
      >
        {state.error.status === 403 ? messages.securityShared.unauthorizedDescription : describeApiError(state.error, messages)}
      </StatePanel>
    )
  } else if (state.data.length === 0) {
    body = <StatePanel tone="success" title={emptyText} />
  } else {
    const rows = sortKey
      ? [...state.data].sort((a, b) => (sortKey(b) ?? '').localeCompare(sortKey(a) ?? ''))
      : state.data
    body = (
      <table className="data-table">
        <caption className="sr-only">{title}</caption>
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.header} scope="col">
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id}>
              {columns.map((column) => (
                <td key={column.header}>{column.cell(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    )
  }

  return (
    <section className="card" aria-labelledby={headingId}>
      <h3 id={headingId} className="card__title">
        {title}
      </h3>
      {description ? <p>{description}</p> : null}
      {body}
    </section>
  )
}
