import type { MutationFailure } from '../../../shared/api/mutationError'

/** Props every Employee 360 operation form receives. */
export interface OperationContext {
  personId: string
  relationshipId: string
  /** Human-readable employee identity shown in confirmation steps (name, else national ID). */
  employeeLabel: string
}

/** Client-side required-field errors win; otherwise the server's per-field message for the same field. */
export function fieldErrorFor(client: Record<string, string>, failure: MutationFailure | null, name: string): string | null {
  return client[name] ?? failure?.fieldErrors[name] ?? null
}
