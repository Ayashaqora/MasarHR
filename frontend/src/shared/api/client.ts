import { env } from '../config/env'
import { ApiError, normalizeApiError, parseErrorBody } from './errors'

export interface ApiRequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  headers?: Record<string, string>
  signal?: AbortSignal
}

const MUTATING_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

function buildUrl(path: string): string {
  return `${env.apiBaseUrl}/${path.replace(/^\/+/, '')}`
}

/**
 * Session-cookie authentication (§12 of the S03 authorization) needs the browser to echo the
 * XSRF-TOKEN cookie back as a header on state-changing requests — that is how Laravel's own CSRF
 * middleware recognizes a same-site SPA request. GET requests never need it; sending it there too
 * would be harmless but pointless.
 */
function csrfHeader(method: string): Record<string, string> {
  if (!MUTATING_METHODS.has(method) || typeof document === 'undefined') {
    return {}
  }
  const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(document.cookie)
  return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1] ?? '') } : {}
}

async function readJson(response: Response): Promise<unknown> {
  const text = await response.text()
  if (text === '') {
    return null
  }
  try {
    return JSON.parse(text)
  } catch (cause) {
    throw new ApiError({
      kind: 'parse',
      message: 'The server returned an invalid response.',
      status: response.status,
      cause,
    })
  }
}

/**
 * The only place that talks to the backend. Feature code passes paths relative to /api/v1
 * and always receives either parsed JSON or an ApiError.
 */
export async function apiRequest<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
  const { method = 'GET', body, headers, signal } = options
  const timeout = AbortSignal.timeout(env.requestTimeoutMs)

  let response: Response
  try {
    response = await fetch(buildUrl(path), {
      method,
      // Browser session + secure cookie authentication (§12): the session and XSRF-TOKEN cookies
      // must be sent and accepted even when the SPA and API are served from different origins.
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...csrfHeader(method),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: signal ? AbortSignal.any([signal, timeout]) : timeout,
    })
  } catch (error) {
    throw normalizeApiError(error)
  }

  const payload = await readJson(response)

  if (!response.ok) {
    const { message, fieldErrors } = parseErrorBody(payload)
    throw new ApiError({
      kind: 'http',
      message: message || `Request failed with status ${response.status}.`,
      status: response.status,
      fieldErrors,
    })
  }

  return payload as T
}
