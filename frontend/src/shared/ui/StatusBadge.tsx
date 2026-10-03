import {
  Archive,
  CalendarOff,
  CircleCheck,
  CircleOff,
  Cross,
  FileX,
  Flag,
  Info,
  LogOut,
  PauseCircle,
  Plane,
  ShieldAlert,
  Stethoscope,
  TriangleAlert,
  type LucideIcon,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import type { StatusKey } from './status'

/**
 * The application's semantic status vocabulary. Every key has a dedicated token triple in styles/globals.css and a
 * dedicated icon: meaning is never carried by color alone, and the visible label is always supplied by the caller.
 */
const STATUS_STYLES: Record<StatusKey, { Icon: LucideIcon; className: string }> = {
  active: { Icon: CircleCheck, className: 'border-status-active-border bg-status-active text-status-active-foreground' },
  inactive: { Icon: CircleOff, className: 'border-status-inactive-border bg-status-inactive text-status-inactive-foreground' },
  'on-duty': { Icon: CircleCheck, className: 'border-status-on-duty-border bg-status-on-duty text-status-on-duty-foreground' },
  traveling: { Icon: Plane, className: 'border-status-traveling-border bg-status-traveling text-status-traveling-foreground' },
  captive: { Icon: ShieldAlert, className: 'border-status-captive-border bg-status-captive text-status-captive-foreground' },
  suspended: { Icon: PauseCircle, className: 'border-status-suspended-border bg-status-suspended text-status-suspended-foreground' },
  'unpaid-leave': { Icon: CalendarOff, className: 'border-status-unpaid-leave-border bg-status-unpaid-leave text-status-unpaid-leave-foreground' },
  'external-sick-leave': { Icon: Stethoscope, className: 'border-status-external-sick-leave-border bg-status-external-sick-leave text-status-external-sick-leave-foreground' },
  retired: { Icon: Archive, className: 'border-status-retired-border bg-status-retired text-status-retired-foreground' },
  resigned: { Icon: LogOut, className: 'border-status-resigned-border bg-status-resigned text-status-resigned-foreground' },
  'contract-ended': { Icon: FileX, className: 'border-status-contract-ended-border bg-status-contract-ended text-status-contract-ended-foreground' },
  deceased: { Icon: Cross, className: 'border-status-deceased-border bg-status-deceased text-status-deceased-foreground' },
  martyr: { Icon: Flag, className: 'border-status-martyr-border bg-status-martyr text-status-martyr-foreground' },
  warning: { Icon: TriangleAlert, className: 'border-status-warning-border bg-status-warning text-status-warning-foreground' },
  success: { Icon: CircleCheck, className: 'border-status-success-border bg-status-success text-status-success-foreground' },
  information: { Icon: Info, className: 'border-status-information-border bg-status-information text-status-information-foreground' },
}

export function StatusBadge({ status, children, className }: { status: StatusKey; children: ReactNode; className?: string }) {
  const { Icon, className: tone } = STATUS_STYLES[status]

  return (
    <Badge variant="outline" data-status={status} className={cn('gap-1.5 px-2 py-0.5 text-xs font-medium', tone, className)}>
      <Icon aria-hidden="true" className="size-3.5" />
      {children}
    </Badge>
  )
}
