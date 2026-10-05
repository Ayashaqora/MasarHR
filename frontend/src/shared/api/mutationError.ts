import type { Messages } from '../../i18n/messages/types'
import type { ApiError } from './errors'

/**
 * How a failed write is presented. Only evidenced backend behavior is mapped: 401 (session), 403 (permission
 * OR organizational scope — the response cannot tell them apart), 404 (a referenced unit/decision type),
 * 409 (conflict / already ended / stale version), 422 (validation or a domain rejection, with per-field
 * messages), and network/timeout/server failures. The raw body is never shown wholesale.
 */
export type MutationFailureKind =
  | 'unauthenticated'
  | 'forbidden'
  | 'notFound'
  | 'conflict'
  | 'validation'
  | 'network'
  | 'server'
  | 'unknown'

export interface MutationFailure {
  kind: MutationFailureKind
  /** Localized headline for the form-level banner. */
  message: string
  /** Server-supplied domain/field messages (the backend's own generic, non-sensitive wording), first per field. */
  fieldErrors: Record<string, string>
  /** The server's own message for 409/422, shown as supporting detail (never a stack trace or SQL). */
  detail: string | null
}

export function describeMutationFailure(error: ApiError, messages: Messages): MutationFailure {
  const fieldErrors: Record<string, string> = {}
  for (const [field, list] of Object.entries(error.fieldErrors)) {
    const first = list[0]
    if (first) {
      fieldErrors[field] = first
    }
  }

  const op = messages.operations

  if (error.kind === 'network' || error.kind === 'timeout') {
    return { kind: 'network', message: error.kind === 'timeout' ? messages.errors.timeout : messages.errors.network, fieldErrors, detail: null }
  }
  if (error.kind === 'parse') {
    return { kind: 'server', message: messages.errors.server, fieldErrors, detail: null }
  }

  switch (error.status) {
    case 401:
      return { kind: 'unauthenticated', message: op.errorUnauthenticated, fieldErrors, detail: null }
    case 403:
      return { kind: 'forbidden', message: op.errorForbidden, fieldErrors, detail: null }
    case 404:
      // Fully generic, like 401/403 (see the doc comment above: only 409/422 carry the server's own detail
      // text) — a 404 body may name the specific missing reference in backend-internal terms, in `message`
      // AND in a per-field `errors` entry (e.g. on the destination-unit field), neither of which is safe to
      // surface verbatim. `fieldErrors` must not pass through here, or `fieldErrorFor` (shared.ts) renders it
      // under the referenced field as soon as the form leaves the review step.
      return { kind: 'notFound', message: op.errorNotFound, fieldErrors: {}, detail: null }
    case 409:
      return { kind: 'conflict', message: op.errorConflict, fieldErrors, detail: error.message || null }
    case 422:
      return { kind: 'validation', message: op.errorValidation, fieldErrors, detail: Object.keys(fieldErrors).length === 0 ? error.message || null : null }
    default:
      return error.isServerError
        ? { kind: 'server', message: messages.errors.server, fieldErrors, detail: null }
        : { kind: 'unknown', message: messages.errors.client, fieldErrors, detail: null }
  }
}
