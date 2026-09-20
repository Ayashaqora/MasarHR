import type { Messages } from '../../i18n/messages/types'
import type { ApiError } from './errors'

/** Maps a normalized ApiError to a localized, user-safe message (never the raw server text). */
export function describeApiError(error: ApiError, messages: Messages): string {
  switch (error.kind) {
    case 'network':
      return messages.errors.network
    case 'timeout':
      return messages.errors.timeout
    case 'parse':
      return messages.errors.server
    case 'http':
      return error.isServerError ? messages.errors.server : messages.errors.client
    default:
      return messages.errors.unknown
  }
}
