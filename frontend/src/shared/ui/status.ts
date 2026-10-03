/** The application's semantic status vocabulary (see StatusBadge and the status tokens in styles/globals.css). */
export const STATUS_KEYS = [
  'active',
  'inactive',
  'on-duty',
  'traveling',
  'captive',
  'suspended',
  'unpaid-leave',
  'external-sick-leave',
  'retired',
  'resigned',
  'contract-ended',
  'deceased',
  'martyr',
  'warning',
  'success',
  'information',
] as const

export type StatusKey = (typeof STATUS_KEYS)[number]

/** Backend employment-status detail codes (ref.employment_status_details.code) -> visual key. Unknown codes stay neutral. */
const CODE_TO_KEY: Record<string, StatusKey> = {
  on_duty: 'on-duty',
  traveling: 'traveling',
  captive: 'captive',
  suspended: 'suspended',
  unpaid_leave: 'unpaid-leave',
  external_sick_leave: 'external-sick-leave',
  retired: 'retired',
  resigned: 'resigned',
  contract_ended: 'contract-ended',
  deceased: 'deceased',
  martyred: 'martyr',
}

export function statusKeyFromCode(code: string | null | undefined): StatusKey {
  return (code ? CODE_TO_KEY[code] : undefined) ?? 'inactive'
}
