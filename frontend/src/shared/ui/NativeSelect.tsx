import type { ComponentProps } from 'react'
import { cn } from '@/lib/utils'

/**
 * A native <select> styled like the shadcn Input. Native on purpose: it is fully keyboard/screen-reader
 * accessible, works with the platform picker on mobile, and needs no extra dependency.
 */
export function NativeSelect({ className, children, ...props }: ComponentProps<'select'>) {
  return (
    <select
      data-slot="native-select"
      className={cn(
        'h-9 w-full min-w-0 rounded-md border border-input bg-background px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm',
        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
        'aria-invalid:border-destructive aria-invalid:ring-destructive/20 disabled:cursor-not-allowed disabled:opacity-50',
        className,
      )}
      {...props}
    >
      {children}
    </select>
  )
}
