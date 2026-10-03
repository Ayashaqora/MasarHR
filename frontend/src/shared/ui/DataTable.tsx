import type { ReactNode } from 'react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'

export interface DataTableColumn<T> {
  header: string
  cell: (row: T) => ReactNode
  className?: string
}

/**
 * Table shell over the shadcn Table: caption for assistive tech, horizontal overflow on narrow screens
 * (the page never scrolls sideways), a sticky header row inside the scroll area, and a built-in empty row.
 * It renders exactly the rows it is given: no client-side sorting, filtering or paging is invented here.
 */
export function DataTable<T>({
  caption,
  columns,
  rows,
  getKey,
  emptyText,
  rowAttributes,
}: {
  caption: string
  columns: readonly DataTableColumn<T>[]
  rows: readonly T[]
  getKey: (row: T) => string
  emptyText?: string
  rowAttributes?: (row: T) => Record<string, string>
}) {
  return (
    <div className="max-h-[32rem] overflow-auto rounded-md border bg-card" tabIndex={0} role="region" aria-label={caption}>
      <Table>
        <caption className="sr-only">{caption}</caption>
        <TableHeader className="sticky top-0 z-10 bg-muted">
          <TableRow>
            {columns.map((column) => (
              <TableHead key={column.header} scope="col" className={column.className}>
                {column.header}
              </TableHead>
            ))}
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.length === 0 && emptyText ? (
            <TableRow>
              <TableCell colSpan={columns.length} className="py-6 text-center text-muted-foreground">
                {emptyText}
              </TableCell>
            </TableRow>
          ) : null}
          {rows.map((row) => (
            <TableRow key={getKey(row)} {...rowAttributes?.(row)}>
              {columns.map((column) => (
                <TableCell key={column.header} className={column.className}>
                  {column.cell(row)}
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
