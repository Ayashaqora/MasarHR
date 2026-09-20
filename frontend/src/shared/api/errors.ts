export type ApiErrorKind = 'network' | 'timeout' | 'http' | 'parse'

export interface ApiErrorInit {
  kind: ApiErrorKind
  message: string
  status?: number
  fieldErrors?: Record<string, string[]>
  cause?: unknown
}

/** Every failure from the API client is normalized into this single shape. */
export class ApiError extends Error {
  readonly kind: ApiErrorKind
  readonly status: number | undefined
  readonly fieldErrors: Record<string, string[]>

  constructor(init: ApiErrorInit) {
    super(init.message, { cause: init.cause })
    this.name = 'ApiError'
    this.kind = init.kind
    this.status = init.status
    this.fieldErrors = init.fieldErrors ?? {}
  }

  get isServerError(): boolean {
    return this.kind === 'http' && (this.status ?? 0) >= 500
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/** Reads the Laravel error envelope: { message, errors?: { field: [messages] } }. */
export function parseErrorBody(body: unknown): Pick<ApiErrorInit, 'message' | 'fieldErrors'> {
  if (!isRecord(body)) {
    return { message: '' }
  }

  const message = typeof body.message === 'string' ? body.message : ''
  const fieldErrors: Record<string, string[]> = {}

  if (isRecord(body.errors)) {
    for (const [field, value] of Object.entries(body.errors)) {
      if (Array.isArray(value)) {
        fieldErrors[field] = value.filter((item): item is string => typeof item === 'string')
      }
    }
  }

  return { message, fieldErrors }
}

export function normalizeApiError(error: unknown): ApiError {
  if (error instanceof ApiError) {
    return error
  }
  if (error instanceof DOMException && error.name === 'TimeoutError') {
    return new ApiError({ kind: 'timeout', message: 'Request timed out.', cause: error })
  }
  if (error instanceof Error) {
    return new ApiError({ kind: 'network', message: error.message, cause: error })
  }
  return new ApiError({ kind: 'network', message: 'Unknown error.', cause: error })
}
